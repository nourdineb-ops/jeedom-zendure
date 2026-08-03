"""Transport simulé (mode_connexion = "simulation") : aucun réseau, aucun appareil réel.

But : faire tourner exactement le même code en aval (régulateur anti-injection,
throttle télémétrie, dashboard, calcul de gain, cf. device.py) qu'en conditions
réelles, pour observer le comportement sans matériel Zendure ni broker MQTT
(demande utilisateur 2026-08-03 : "voir le comportement"). Seule cette classe
change par rapport à MqttTransport -- tout le reste du démon l'ignore.

Modèle volontairement simple, pas une simulation physique fidèle : un scénario
synthétique conso/PV comprime une "journée" sur cycle_period_s (def. 900s = 15min)
pour rendre la régulation observable en quelques minutes plutôt qu'en 24h réelles.

Générateur de scénario intégré (choisi plutôt qu'une commande virtuelle Jeedom
pilotée à la main) : ce transport appelle directement Device.on_grid_power() via
set_grid_power_sink(), sans passer par le listener Jeedom/pince -- cette méthode
n'existe QUE sur cette classe, volontairement absente de l'ABC Transport (cf.
base.py) puisqu'elle n'a de sens qu'en simulation ; device.py la découvre via
getattr(..., None) plutôt que via l'interface commune.
"""

import logging
import math
import random
import threading
import time
from typing import Callable, Optional

from .base import TelemetryFrame, Transport

log = logging.getLogger("zendure.transport.simulated")

TICK_PERIOD_S = 2.0


class SimulatedTransport(Transport):
    def __init__(self, conn: dict):
        self._capacity_kwh = float(conn.get("capacity_kwh") or 5.0)
        self._cycle_period_s = float(conn.get("cycle_period_s") or 900.0)
        self._limit_max_w = float(conn.get("limit_max_w") or 1200.0)

        self._soc = float(conn.get("initial_soc") or 50.0)
        self._output_limit_w = 0.0
        self._input_limit_w = 0.0
        self._mode = 2  # acMode : 1=charge, 2=décharge -- même convention que le device réel
        self._smart_mode = False
        self._soc_min = 0
        self._soc_max = 100
        # Puissance réellement "délivrée" par le faux appareil, avec un peu d'inertie
        # vers la limite commandée (cf. _tick) -- jamais un saut instantané, comme un
        # vrai appareil qui met quelques secondes à stabiliser sa sortie.
        self._injected_power_w = 0.0
        self._last_solar_w = 0.0
        self._last_grid_w = 0.0

        self._connected = False
        self._start_time = time.monotonic()
        self._thread: Optional[threading.Thread] = None
        self._stop_event = threading.Event()

        self._telemetry_cb: Optional[Callable[[TelemetryFrame], None]] = None
        self._conn_cb: Optional[Callable[[bool], None]] = None
        self._issue_cb: Optional[Callable[[str, str], None]] = None
        self._grid_power_sink: Optional[Callable[[float], None]] = None

    # -- Transport interface ---------------------------------------------------

    def connect(self) -> None:
        self._connected = True
        self._stop_event.clear()
        self._thread = threading.Thread(target=self._run, daemon=True)
        self._thread.start()
        log.info(
            "Transport simulé démarré (capacité=%.1fkWh, cycle=%.0fs, SOC initial=%.0f%%)",
            self._capacity_kwh, self._cycle_period_s, self._soc,
        )
        if self._conn_cb:
            self._conn_cb(True)

    def disconnect(self) -> None:
        self._connected = False
        self._stop_event.set()
        if self._thread is not None:
            self._thread.join(timeout=5)
        if self._conn_cb:
            self._conn_cb(False)

    @property
    def is_connected(self) -> bool:
        return self._connected

    def set_output_limit(self, watts: int) -> None:
        self._output_limit_w = max(0.0, float(watts))

    def set_input_limit(self, watts: int) -> None:
        self._input_limit_w = max(0.0, float(watts))

    def set_mode(self, mode: int) -> None:
        self._mode = int(mode)

    def set_soc_min(self, percent: int) -> None:
        self._soc_min = int(percent)

    def set_soc_max(self, percent: int) -> None:
        self._soc_max = int(percent)

    def set_smart_mode(self, enabled: bool) -> None:
        self._smart_mode = bool(enabled)

    def request_telemetry(self) -> None:
        self._emit_telemetry()

    def on_telemetry(self, callback: Callable[[TelemetryFrame], None]) -> None:
        self._telemetry_cb = callback

    def on_connection_change(self, callback: Callable[[bool], None]) -> None:
        self._conn_cb = callback

    def on_connection_issue(self, callback: Callable[[str, str], None]) -> None:
        self._issue_cb = callback

    # -- Extension simulation-only (pas dans l'ABC Transport, cf. docstring) ---

    def set_grid_power_sink(self, callback: Callable[[float], None]) -> None:
        self._grid_power_sink = callback

    # -- Internals ---------------------------------------------------------------

    def _run(self) -> None:
        while not self._stop_event.wait(TICK_PERIOD_S):
            self._tick()

    def _tick(self) -> None:
        elapsed = time.monotonic() - self._start_time
        phase = (elapsed % self._cycle_period_s) / self._cycle_period_s  # 0..1 sur un "jour" compressé

        # PV : bosse en cloche centrée sur le "midi" du cycle -- pas une vraie courbe
        # solaire, juste de quoi faire varier grid_power de façon crédible (import le
        # matin/soir, risque d'injection au pic).
        solar_w = max(0.0, math.sin(phase * math.pi)) * 1800.0 + random.uniform(-30, 30)
        load_w = 350.0 + random.uniform(-100, 150)

        target_injected = self._output_limit_w if self._mode == 2 else -self._input_limit_w
        self._injected_power_w += (target_injected - self._injected_power_w) * 0.5

        self._last_solar_w = solar_w
        self._last_grid_w = load_w - solar_w - self._injected_power_w

        self._update_soc()
        self._emit_telemetry()

        if self._grid_power_sink:
            self._grid_power_sink(self._last_grid_w)

    def _update_soc(self) -> None:
        # injected_power_w > 0 = décharge (SOC baisse), < 0 = charge (SOC monte) --
        # même convention que le reste du plugin (cf. anti_injection.py en tête).
        # Bornes SOC min/max de la config volontairement pas appliquées ici (juste
        # exposées en télémétrie) : simplification assumée, pas nécessaire pour
        # observer le comportement de la boucle rapide.
        delta_kwh = -self._injected_power_w * TICK_PERIOD_S / 3600.0 / 1000.0
        if self._capacity_kwh > 0:
            self._soc += (delta_kwh / self._capacity_kwh) * 100.0
        self._soc = max(0.0, min(100.0, self._soc))

    def _emit_telemetry(self) -> None:
        if not self._telemetry_cb:
            return
        frame = TelemetryFrame(
            {
                "properties": {
                    "electricLevel": round(self._soc, 1),
                    "solarInputPower": round(self._last_solar_w, 1),
                    "outputHomePower": round(max(0.0, self._injected_power_w), 1),
                    "gridInputPower": round(self._last_grid_w, 1),
                    "inputLimit": int(self._input_limit_w),
                    "acMode": self._mode,
                    "minSoc": self._soc_min * 10,
                    "socSet": self._soc_max * 10,
                    "smartMode": 1 if self._smart_mode else 0,
                }
            }
        )
        self._telemetry_cb(frame)
