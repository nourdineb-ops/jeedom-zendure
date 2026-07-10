"""Un Device = un eqLogic Zendure : son transport (A ou B) + sa boucle rapide anti-injection.

La boucle lente (stratégie éco, SOC nocturne/Tempo, cf. brief §9bis) reste côté
scénario Jeedom ou config quotidienne ; elle n'a pas besoin d'être temps réel et
n'est donc pas modélisée ici.
"""

import logging
from typing import Optional

from regulation.anti_injection import AntiInjectionConfig, AntiInjectionRegulator
from telemetry_map import translate_properties
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

        if transport_changed:
            log.info("eq_id=%s configuration transport modifiée, reconnexion", self.eq_id)
            self._transport.disconnect()
            self._transport = build_transport(eq_config)
            self._transport.on_telemetry(self._on_telemetry)
            self._transport.on_connection_change(self._on_connection_change)
            self._transport.connect()

    def on_grid_power(self, value_w: float) -> None:
        """Appelé depuis le socket serveur quand la pince (via listener PHP) rapporte une nouvelle valeur."""
        new_limit = self._regulator.update(value_w)
        if new_limit is not None:
            log.debug("eq_id=%s grid=%.1fW -> nouvelle limite sortie %sW", self.eq_id, value_w, new_limit)
            self._transport.set_output_limit(new_limit)

    def _on_telemetry(self, frame: TelemetryFrame) -> None:
        # La trame report Zendure vient sous {"properties": {...}} (et parfois packData) ;
        # callback.php attend un dict plat {logicalId: valeur} (cf. core/php/callback.php).
        # Sans ce dépaquetage/traduction, aucune commande info n'était jamais mise à jour.
        properties = dict(frame).get("properties", {})
        if not properties:
            return
        values = translate_properties(properties)
        if values:
            self._callback.send_event(self.eq_id, values)

    def _on_connection_change(self, connected: bool) -> None:
        self._callback.send_event(self.eq_id, {"transport_connected": connected})
