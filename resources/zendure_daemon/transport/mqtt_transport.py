"""Implémentation MQTT commune aux deux modes de connexion (Cloud A / Local B).

Rien de spécifique à "cloud" ou "local" ici : uniquement des paramètres de connexion
(host, port, tls, credentials) et des templates de topic, tous fournis par la config
(cf. factory.py). C'est la même classe qui tourne dans les deux cas — cf. brief §4 :
"le cœur est identique ; seule la couche transport MQTT change".

Protocole (topics + payloads) confirmé contre le code source réel de l'intégration
Home Assistant `zendure_ha` (custom_components/zendure_ha/device.py et
devices/hyper2000.py, testée en production sur ce même Hyper 2000) :
- Souscription télémétrie en wildcard `iot/{product_key}/{device_id}/#`, la trame
  utile arrivant sur le suffixe `properties/report`.
- Le pilotage de la limite de sortie/entrée (setDeviceAutomationInOutLimit) ne passe
  PAS par `properties/write` mais par `function/invoke`, payload `deviceAutomation`
  (cf. Hyper2000.discharge/charge dans zendure_ha). C'est le seul mécanisme qui
  déclenche réellement l'automation embarquée sans écriture flash.
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
        topic_telemetry (souscription wildcard, template avec {device_id}/{product_key}),
        topic_function (topic de commande deviceAutomation, template idem).
        """
        self._conn = conn
        self._client = mqtt.Client(client_id=conn.get("client_id") or f"jeedom-zendure-{conn['device_id']}")
        self._telemetry_cb: Optional[Callable[[TelemetryFrame], None]] = None
        self._conn_cb: Optional[Callable[[bool], None]] = None
        self._connected = False
        self._lock = threading.Lock()
        self._message_id = 0

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
        # autoModelProgram=2 : décharge (sortie vers la maison), cf. Hyper2000.discharge (zendure_ha).
        self._publish_automation(
            program=2,
            value={"chargingType": 0, "chargingPower": 0, "freq": 0, "outPower": int(watts)},
        )

    def set_input_limit(self, watts: int) -> None:
        # autoModelProgram=1 : charge (depuis le réseau), cf. Hyper2000.charge (zendure_ha).
        # chargingPower est positif côté device même si watts représente une limite de charge.
        self._publish_automation(
            program=1,
            value={
                "chargingType": 1,
                "price": 2,
                "chargingPower": int(watts),
                "prices": [1] * 24,
                "outPower": 0,
                "freq": 0,
            },
        )

    def set_mode(self, mode: int) -> None:
        # acMode (1=input/2=output) passe par properties/write, PAS function/invoke
        # (canal distinct, cf. ZendureDevice.entityWrite dans zendure_ha) — confirmé en direct.
        self._publish_property("acMode", int(mode))

    def set_soc_min(self, percent: int) -> None:
        self._publish_property("minSoc", int(percent))

    def request_telemetry(self) -> None:
        topic = self._conn["topic_read"].format(
            device_id=self._conn["device_id"], product_key=self._conn.get("product_key", "")
        )
        self._client.publish(topic, json.dumps({"properties": ["getAll"]}), qos=1)

    def on_telemetry(self, callback: Callable[[TelemetryFrame], None]) -> None:
        self._telemetry_cb = callback

    def on_connection_change(self, callback: Callable[[bool], None]) -> None:
        self._conn_cb = callback

    # -- Internals -------------------------------------------------------------

    def _next_message_id(self) -> int:
        self._message_id += 1
        return self._message_id

    def _publish_automation(self, program: int, value: dict) -> None:
        topic = self._conn["topic_function"].format(
            device_id=self._conn["device_id"], product_key=self._conn.get("product_key", "")
        )
        # deviceAutomation/autoModel=8 : seul mécanisme qui déclenche l'automation embarquée
        # sans écriture flash (setDeviceAutomationInOutLimit, cf. brief §5 point critique #1) —
        # confirmé contre Hyper2000.charge/discharge dans l'intégration zendure_ha.
        payload = json.dumps(
            {
                "arguments": [
                    {
                        "autoModelProgram": program,
                        "autoModelValue": value,
                        "msgType": 1,
                        "autoModel": 8,
                    }
                ],
                "function": "deviceAutomation",
                "messageId": self._next_message_id(),
                "deviceKey": self._conn["device_id"],
                "timestamp": int(time.time()),
            }
        )
        log.debug("Publish %s -> %s", topic, payload)
        self._client.publish(topic, payload, qos=1)

    def _publish_property(self, name: str, value) -> None:
        topic = self._conn["topic_write"].format(
            device_id=self._conn["device_id"], product_key=self._conn.get("product_key", "")
        )
        payload = json.dumps(
            {
                "deviceId": self._conn["device_id"],
                "messageId": self._next_message_id(),
                "timestamp": int(time.time()),
                "properties": {name: value},
            }
        )
        log.debug("Publish %s -> %s", topic, payload)
        self._client.publish(topic, payload, qos=1)

    def _on_connect(self, client, userdata, flags, rc):
        with self._lock:
            self._connected = rc == 0
        if rc == 0:
            pk = self._conn.get("product_key", "")
            did = self._conn["device_id"]
            topic = self._conn["topic_telemetry"].format(device_id=did, product_key=pk)
            client.subscribe(topic, qos=1)
            # zendure_ha souscrit aussi sans le préfixe "iot/" (defensive, cf. Api.mqttConnect) :
            # certains firmwares/génération publient la télémétrie sur ce topic-là.
            legacy_topic = f"/{pk}/{did}/#"
            client.subscribe(legacy_topic, qos=1)
            log.info("Connecté, abonné à %s et %s", topic, legacy_topic)
            self.request_telemetry()
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
        # Le topic souscrit est un wildcard (.../#) : seule la trame de télémétrie
        # (suffixe properties/report) nous intéresse, cf. mqttMessage() dans zendure_ha.
        if not msg.topic.endswith("properties/report"):
            return
        try:
            data = json.loads(msg.payload.decode("utf-8"))
        except (ValueError, UnicodeDecodeError):
            log.warning("Trame illisible sur %s", msg.topic)
            return
        if self._telemetry_cb:
            self._telemetry_cb(TelemetryFrame(data))
