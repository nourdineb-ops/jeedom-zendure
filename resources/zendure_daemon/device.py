"""Un Device = un eqLogic Zendure : son transport (A ou B) + sa boucle rapide anti-injection.

La boucle lente (stratégie éco, SOC nocturne/Tempo, cf. brief §9bis) reste côté
scénario Jeedom ou config quotidienne ; elle n'a pas besoin d'être temps réel et
n'est donc pas modélisée ici.
"""

import logging
from typing import Optional

from regulation.anti_injection import AntiInjectionConfig, AntiInjectionRegulator
from telemetry_map import translate_properties
from telemetry_throttle import TelemetryThrottle
from transport.base import TelemetryFrame, Transport
from transport.factory import build_transport

DEBUG_CAPTURE_DURATION_S = 3600.0

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
        self._throttle = TelemetryThrottle(
            float(eq_config.get("telemetry_min_interval_s", 300)),
            float(eq_config.get("telemetry_noise_threshold", 3)),
        )
        # Dernière puissance RÉELLEMENT délivrée à la maison (télémétrie Zendure,
        # pas une valeur qu'on a nous-même commandée) — cf. anti_injection.py,
        # le régulateur recalcule sa cible à partir de cette mesure à chaque fois.
        self._last_injected_w: float = 0.0
        self._transport.on_telemetry(self._on_telemetry)
        self._transport.on_connection_change(self._on_connection_change)

    def start(self) -> None:
        self._transport.connect()

    def stop(self) -> None:
        # Repasse smartMode à 0 avant de couper : sinon l'app mobile reste
        # indéfiniment sur "Mode intelligent" alors que plus personne ne pilote
        # l'appareil (cf. échange avec l'utilisateur sur ce comportement).
        try:
            self._transport.set_smart_mode(False)
        except Exception:
            log.warning("eq_id=%s échec set_smart_mode(False) à l'arrêt", self.eq_id, exc_info=True)
        self._transport.disconnect()

    def request_telemetry(self) -> None:
        self._transport.request_telemetry()

    # Clés qui déterminent la connexion transport : si l'une change (mode, host,
    # credentials...), il faut reconnecter, pas juste mettre à jour le régulateur.
    _TRANSPORT_KEYS = (
        "mode_connexion",
        "device_id",
        "product_key",
        "cloud_host",
        "cloud_port",
        "cloud_tls",
        "cloud_username",
        "cloud_auth_key",
        "cloud_client_id",
        "local_host",
        "local_port",
        "local_tls",
        "local_username",
        "local_password",
    )

    def reload_config(self, eq_config: dict) -> None:
        transport_changed = any(
            self._eq_config.get(key) != eq_config.get(key) for key in self._TRANSPORT_KEYS
        )
        self._eq_config = eq_config
        self._regulator.reload_config(AntiInjectionConfig.from_dict(eq_config.get("anti_injection", {})))
        self._throttle.min_interval_s = float(eq_config.get("telemetry_min_interval_s", 300))
        self._throttle.noise_threshold = float(eq_config.get("telemetry_noise_threshold", 3))

        if transport_changed:
            log.info("eq_id=%s configuration transport modifiée, reconnexion", self.eq_id)
            self._transport.disconnect()
            self._transport = build_transport(eq_config)
            self._transport.on_telemetry(self._on_telemetry)
            self._transport.on_connection_change(self._on_connection_change)
            self._transport.connect()

    # logicalId (cf. zendure::ACTION_COMMANDS côté PHP) -> méthode Transport.
    _ACTION_DISPATCH = {
        "set_output_limit": "set_output_limit",
        "set_input_limit": "set_input_limit",
        "set_soc_min": "set_soc_min",
        "set_soc_max": "set_soc_max",
        "set_mode": "set_mode",
    }

    def on_action(self, logical_id: str, value) -> None:
        """Appelé depuis le socket serveur pour toute commande "action" déclenchée côté
        Jeedom (curseur du dashboard, exécution manuelle d'une commande...) — cf.
        zendureCmd::execute() qui relaie ici via {"type": "action", ...}. Sans ce
        handler le démon logait juste "Type de message inconnu : action" et les
        curseurs du dashboard ne faisaient strictement rien."""
        if logical_id == "debug_capture_1h":
            self._throttle.enable_debug_capture(DEBUG_CAPTURE_DURATION_S)
            log.info("eq_id=%s capture télémétrie complète activée pour %ds", self.eq_id, int(DEBUG_CAPTURE_DURATION_S))
            return
        method_name = self._ACTION_DISPATCH.get(logical_id)
        if method_name is None:
            log.warning("eq_id=%s action non gérée : %s=%s", self.eq_id, logical_id, value)
            return
        try:
            getattr(self._transport, method_name)(int(float(value)))
        except (TypeError, ValueError):
            log.warning("eq_id=%s valeur invalide pour %s : %r", self.eq_id, logical_id, value)

    def on_grid_power(self, value_w: float) -> None:
        """Appelé depuis le socket serveur quand la pince (via listener PHP) rapporte une nouvelle valeur.

        Décharge uniquement (cf. anti_injection.py) : jamais de bascule en charge
        depuis cette boucle rapide, aligné sur le scénario Jeedom de référence.
        Le calcul se base sur la dernière puissance injectée RÉELLEMENT mesurée
        (télémétrie), pas sur une limite qu'on aurait nous-même commandée."""
        action = self._regulator.update(value_w, self._last_injected_w)
        if action is None:
            return
        log.debug(
            "eq_id=%s grid=%.1fW injected=%.1fW -> discharge %sW",
            self.eq_id, value_w, self._last_injected_w, action.power_w,
        )
        self._transport.set_output_limit(action.power_w)
        # Pousse la valeur commandée à Jeedom immédiatement, sans attendre un écho
        # télémétrie de l'appareil : le champ outputLimit renvoyé par l'appareil
        # n'est pas fiable comme miroir temps réel (constaté : dérive spontanée
        # sans action de notre part, cf. README "Points ouverts") -- si on
        # attendait cet écho, le curseur "Limite sortie AC" du widget restait
        # visuellement figé pendant que la boucle rapide agissait réellement
        # (signalé). On connaît la valeur avec certitude ici, on la publie donc
        # nous-même plutôt que de dépendre du device pour se la confirmer.
        self._callback.send_event(self.eq_id, {"output_limit": action.power_w})

    def _on_telemetry(self, frame: TelemetryFrame) -> None:
        # La trame report Zendure peut porter des données hors du wrapper "properties"
        # (packData, cluster, wifiName/mac/ip...) : translate_properties() aplatit
        # désormais la trame ENTIÈRE (moins la plomberie protocole), pas juste
        # "properties", pour ne perdre aucune information remontée par l'appareil.
        values = translate_properties(dict(frame))
        if "injected_power" in values:
            try:
                self._last_injected_w = float(values["injected_power"])
            except (TypeError, ValueError):
                pass
        values = self._throttle.filter(values)
        if values:
            self._callback.send_event(self.eq_id, values)

    def _on_connection_change(self, connected: bool) -> None:
        self._callback.send_event(self.eq_id, {"transport_connected": connected})
