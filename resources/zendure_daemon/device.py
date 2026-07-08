"""Un Device = un eqLogic Zendure : son transport (A ou B) + sa boucle rapide anti-injection.

La boucle lente (stratégie éco, SOC nocturne/Tempo, cf. brief §9bis) reste côté
scénario Jeedom ou config quotidienne ; elle n'a pas besoin d'être temps réel et
n'est donc pas modélisée ici.
"""

import logging
from typing import Optional

from regulation.anti_injection import AntiInjectionConfig, AntiInjectionRegulator
from transport.base import TelemetryFrame, Transport
from transport.factory import build_transport

log = logging.getLogger("zendure.device")


class Device:
    def __init__(self, eq_config: dict, callback_client):
        self.eq_id: int = eq_config["eq_id"]
        self._eq_config = eq_config
        self._callback = callback_client
        self._transport: Transport = build_transport(eq_config)
        self._regulator = AntiInjectionRegulator(
            AntiInjectionConfig.from_dict(eq_config.get("anti_injection", {}))
        )
        self._transport.on_telemetry(self._on_telemetry)
        self._transport.on_connection_change(self._on_connection_change)

    def start(self) -> None:
        self._transport.connect()

    def stop(self) -> None:
        self._transport.disconnect()

    def reload_config(self, eq_config: dict) -> None:
        self._eq_config = eq_config
        self._regulator.reload_config(AntiInjectionConfig.from_dict(eq_config.get("anti_injection", {})))

    def on_grid_power(self, value_w: float) -> None:
        """Appelé depuis le socket serveur quand la pince (via listener PHP) rapporte une nouvelle valeur."""
        new_limit = self._regulator.update(value_w)
        if new_limit is not None:
            log.debug("eq_id=%s grid=%.1fW -> nouvelle limite sortie %sW", self.eq_id, value_w, new_limit)
            self._transport.set_output_limit(new_limit)

    def _on_telemetry(self, frame: TelemetryFrame) -> None:
        self._callback.send_event(self.eq_id, dict(frame))

    def _on_connection_change(self, connected: bool) -> None:
        self._callback.send_event(self.eq_id, {"transport_connected": connected})
