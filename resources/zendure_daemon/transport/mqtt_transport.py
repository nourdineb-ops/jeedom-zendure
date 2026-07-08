"""Implémentation MQTT commune aux deux modes de connexion (Cloud A / Local B).

Rien de spécifique à "cloud" ou "local" ici : uniquement des paramètres de connexion
(host, port, tls, credentials) et des templates de topic, tous fournis par la config
(cf. factory.py). C'est la même classe qui tourne dans les deux cas — cf. brief §4 :
"le cœur est identique ; seule la couche transport MQTT change".

Structure des topics / charge utile de commande à CONFIRMER sur l'installation réelle
(brief §15) en lisant iobroker.zendure-solarflow (Nograx) et
Zendure/developer-device-data-report. Les templates sont donc des paramètres de
config (topic_telemetry, topic_command, property_output_limit, property_input_limit),
pas des constantes en dur, pour pouvoir corriger sans recoder.
"""

import json
import logging
import ssl
import threading
import time
from typing import Callable, Optional

import paho.mqtt.client as mqtt

from .base import TelemetryFrame, Transport

log = logging.getLogger("zendure.transport.mqtt")


class MqttTransport(Transport):
    def __init__(self, conn: dict):
        """conn attendu (toutes les clés viennent de la config, cf. Étage 1 du brief) :
        host, port, tls (bool), username, password, client_id,
        device_id, product_key,
        topic_telemetry (template avec {device_id}/{product_key}),
        topic_command (template idem),
        property_output_limit, property_input_limit (noms de propriété setDeviceAutomationInOutLimit).
        """
        self._conn = conn
        self._client = mqtt.Client(client_id=conn.get("client_id") or f"jeedom-zendure-{conn['device_id']}")
        self._telemetry_cb: Optional[Callable[[TelemetryFrame], None]] = None
        self._conn_cb: Optional[Callable[[bool], None]] = None
        self._connected = False
        self._lock = threading.Lock()

        if conn.get("username"):
            self._client.username_pw_set(conn.get("username"), conn.get("password"))
        if conn.get("tls"):
            self._client.tls_set(cert_reqs=ssl.CERT_REQUIRED)

        self._client.on_connect = self._on_connect
        self._client.on_disconnect = self._on_disconnect
        self._client.on_message = self._on_message

    # -- Transport interface -------------------------------------------------

    def connect(self) -> None:
        log.info("Connexion MQTT vers %s:%s (tls=%s)", self._conn["host"], self._conn["port"], self._conn.get("tls"))
        self._client.connect_async(self._conn["host"], int(self._conn["port"]), keepalive=30)
        self._client.loop_start()

    def disconnect(self) -> None:
        self._client.loop_stop()
        self._client.disconnect()

    @property
    def is_connected(self) -> bool:
        with self._lock:
            return self._connected

    def set_output_limit(self, watts: int) -> None:
        self._publish_property(self._conn["property_output_limit"], int(watts))

    def set_input_limit(self, watts: int) -> None:
        self._publish_property(self._conn["property_input_limit"], int(watts))

    def on_telemetry(self, callback: Callable[[TelemetryFrame], None]) -> None:
        self._telemetry_cb = callback

    def on_connection_change(self, callback: Callable[[bool], None]) -> None:
        self._conn_cb = callback

    # -- Internals -------------------------------------------------------------

    def _publish_property(self, property_name: str, value: int) -> None:
        topic = self._conn["topic_command"].format(
            device_id=self._conn["device_id"], product_key=self._conn.get("product_key", "")
        )
        # setDeviceAutomationInOutLimit : ne déclenche pas d'écriture flash côté appareil
        # (cf. brief §5 point critique #1). Format de payload à confirmer (§15).
        payload = json.dumps({"properties": {property_name: value}, "messageId": int(time.time() * 1000)})
        log.debug("Publish %s -> %s", topic, payload)
        self._client.publish(topic, payload, qos=1)

    def _on_connect(self, client, userdata, flags, rc):
        with self._lock:
            self._connected = rc == 0
        if rc == 0:
            topic = self._conn["topic_telemetry"].format(
                device_id=self._conn["device_id"], product_key=self._conn.get("product_key", "")
            )
            client.subscribe(topic, qos=1)
            log.info("Connecté, abonné à %s", topic)
        else:
            log.error("Échec connexion MQTT, rc=%s", rc)
        if self._conn_cb:
            self._conn_cb(rc == 0)

    def _on_disconnect(self, client, userdata, rc):
        with self._lock:
            self._connected = False
        log.warning("Déconnecté MQTT (rc=%s)", rc)
        if self._conn_cb:
            self._conn_cb(False)

    def _on_message(self, client, userdata, msg):
        try:
            data = json.loads(msg.payload.decode("utf-8"))
        except (ValueError, UnicodeDecodeError):
            log.warning("Trame illisible sur %s", msg.topic)
            return
        if self._telemetry_cb:
            self._telemetry_cb(TelemetryFrame(data))
