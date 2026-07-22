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

# Détection "flapping" (reconnexions en rafale) : une connexion qui tient moins de
# FLAP_MIN_UPTIME_S est comptée comme un "flap". Au-delà de FLAP_THRESHOLD flaps
# d'affilée, on considère que quelque chose se bat pour la même session (typiquement
# un autre client -- ex. Home Assistant -- avec la même identité cloud, cf. incident
# du 2026-07-22 : reconnexion toutes les ~1-2s pendant plus de 4h) et on arrête de
# marteler le broker : paho réinitialise lui-même son délai de reconnexion à chaque
# connexion réussie (même si elle ne tient qu'une seconde), donc sans cette détection
# côté plugin, `loop_forever()` retente indéfiniment à ~1s d'intervalle.
FLAP_MIN_UPTIME_S = 8.0
FLAP_THRESHOLD = 5
FLAP_BACKOFF_START_S = 30.0
FLAP_BACKOFF_MAX_S = 300.0
FLAP_STABLE_AFTER_S = 30.0


class MqttTransport(Transport):
    def __init__(self, conn: dict):
        """conn attendu (toutes les clés viennent de la config, cf. Étage 1 du brief) :
        host, port, tls (bool), username, password, client_id,
        device_id, product_key,
        topic_telemetry (souscription wildcard, template avec {device_id}/{product_key}),
        topic_function (topic de commande deviceAutomation, template idem).
        """
        self._conn = conn
        # protocol=MQTTv31 + clean_session=False : confirmé indispensable en test réel
        # contre mqtteu.zen-iot.com — avec MQTTv3.1.1 (défaut paho) le SUBACK renvoie
        # QoS=128 (souscription refusée par le broker) même avec un clientId/login valides.
        # Avec ces deux réglages (alignés sur Api.mqttCloud.__init__ dans zendure_ha),
        # le SUBACK passe à QoS=1 et la télémétrie arrive réellement.
        self._client = mqtt.Client(
            client_id=conn.get("client_id") or f"jeedom-zendure-{conn['device_id']}",
            clean_session=False,
            protocol=mqtt.MQTTv31,
        )
        self._telemetry_cb: Optional[Callable[[TelemetryFrame], None]] = None
        self._conn_cb: Optional[Callable[[bool], None]] = None
        self._issue_cb: Optional[Callable[[str, str], None]] = None
        self._connected = False
        self._lock = threading.Lock()
        self._message_id = 0

        # État de la détection de flapping (cf. constantes FLAP_* ci-dessus).
        self._connected_at: Optional[float] = None
        self._flap_streak = 0
        self._flapping = False
        self._backoff_s = FLAP_BACKOFF_START_S
        self._stable_timer: Optional[threading.Timer] = None
        self._backoff_stop = threading.Event()
        self._manually_stopped = False

        if conn.get("username"):
            self._client.username_pw_set(conn.get("username"), conn.get("password"))
        if conn.get("tls"):
            self._client.tls_set(cert_reqs=ssl.CERT_REQUIRED)

        self._client.on_connect = self._on_connect
        self._client.on_disconnect = self._on_disconnect
        self._client.on_message = self._on_message
        self._client.on_subscribe = self._on_subscribe

    # -- Transport interface -------------------------------------------------

    def connect(self) -> None:
        self._manually_stopped = False
        log.info("Connexion MQTT vers %s:%s (tls=%s)", self._conn["host"], self._conn["port"], self._conn.get("tls"))
        self._client.connect_async(self._conn["host"], int(self._conn["port"]), keepalive=30)
        self._client.loop_start()

    def disconnect(self) -> None:
        self._manually_stopped = True
        self._backoff_stop.set()
        self._cancel_stable_timer()
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
        # Facteur x10 côté device (confirmé dans zendure_ha : ZendureNumber(...,
        # factor=10) pour minSoc/socSet, cf. async_set_native_value ->
        # onwrite(factor * value)) -- sans lui, régler 40% envoyait en réalité 4%
        # au boîtier (signalé : le curseur SOC minimum ne faisait pas ce qu'il
        # affichait).
        self._publish_property("minSoc", int(percent) * 10)

    def set_soc_max(self, percent: int) -> None:
        # Même mécanique que set_soc_min (propriété socSet, même facteur x10) :
        # c'est le SOC cible où la charge s'arrête (affiché "SOC maximum" côté
        # app/HA) -- absent du plugin jusqu'ici alors que c'est, avec la limite
        # de sortie, l'un des deux leviers réellement utilisés pour piloter la
        # charge en HC (retour utilisateur).
        self._publish_property("socSet", int(percent) * 10)

    def set_smart_mode(self, enabled: bool) -> None:
        self._publish_property("smartMode", 1 if enabled else 0)

    def request_telemetry(self) -> None:
        topic = self._conn["topic_read"].format(
            device_id=self._conn["device_id"], product_key=self._conn.get("product_key", "")
        )
        log.debug("Publish %s -> getAll", topic)
        self._client.publish(topic, json.dumps({"properties": ["getAll"]}), qos=1)

    def on_telemetry(self, callback: Callable[[TelemetryFrame], None]) -> None:
        self._telemetry_cb = callback

    def on_connection_change(self, callback: Callable[[bool], None]) -> None:
        self._conn_cb = callback

    def on_connection_issue(self, callback: Callable[[str, str], None]) -> None:
        self._issue_cb = callback

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
            self._connected_at = time.monotonic()
            self._schedule_stable_timer()
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

    def _on_subscribe(self, client, userdata, mid, granted_qos):
        # QoS=128 (0x80) dans le SUBACK = souscription refusée par le broker (ACL) :
        # sans ce log on ne peut pas distinguer "abonné mais rien ne vient" de
        # "jamais réellement abonné", ce qui est exactement le doute actuel.
        log.info("SUBACK mid=%s granted_qos=%s", mid, granted_qos)

    def _on_disconnect(self, client, userdata, rc):
        with self._lock:
            self._connected = False
        log.warning("Déconnecté MQTT (rc=%s)", rc)
        self._cancel_stable_timer()
        uptime = (time.monotonic() - self._connected_at) if self._connected_at is not None else None
        self._connected_at = None
        if uptime is not None and uptime < FLAP_MIN_UPTIME_S:
            self._flap_streak += 1
        else:
            self._flap_streak = 0
        if self._flap_streak >= FLAP_THRESHOLD and not self._flapping and not self._manually_stopped:
            self._flapping = True
            threading.Thread(target=self._enter_backoff, daemon=True).start()
        if self._conn_cb:
            self._conn_cb(False)

    # -- Détection de flapping / pause de reconnexion ---------------------------

    def _schedule_stable_timer(self) -> None:
        self._cancel_stable_timer()
        self._stable_timer = threading.Timer(FLAP_STABLE_AFTER_S, self._mark_stable)
        self._stable_timer.daemon = True
        self._stable_timer.start()

    def _cancel_stable_timer(self) -> None:
        if self._stable_timer is not None:
            self._stable_timer.cancel()
            self._stable_timer = None

    def _mark_stable(self) -> None:
        self._flap_streak = 0
        self._backoff_s = FLAP_BACKOFF_START_S
        if self._flapping:
            self._flapping = False
            log.info("eq_id=%s connexion MQTT stabilisée, fin de l'alerte flapping", self._conn.get("device_id"))
            if self._issue_cb:
                # issue_id distinct de celui du problème : message::add() dédoublonne
                # par (plugin, logicalId) sans jamais réécrire le texte d'un message
                # existant -- un même id que l'alerte de départ ferait juste
                # incrémenter son compteur d'occurrences sans jamais afficher "rétabli".
                self._issue_cb(
                    "mqtt_flapping_resolu",
                    "Connexion MQTT Zendure rétablie et stable (appareil %s)." % self._conn.get("device_id"),
                )

    def _enter_backoff(self) -> None:
        # Appelé depuis un thread dédié (jamais depuis le thread réseau paho lui-même
        # -- loop_stop() joint ce thread et provoquerait un deadlock si on l'appelait
        # depuis _on_disconnect directement).
        backoff_s = self._backoff_s
        message = (
            "Reconnexions MQTT en rafale (%d en moins de %.0fs) sur l'appareil %s -- "
            "probable conflit de session (ex. Home Assistant actif avec les mêmes "
            "identifiants cloud). Pause de %.0fs avant nouvelle tentative."
        ) % (self._flap_streak, FLAP_MIN_UPTIME_S * self._flap_streak, self._conn.get("device_id"), backoff_s)
        log.error(message)
        if self._issue_cb:
            self._issue_cb("mqtt_flapping", message)
        self._backoff_stop.clear()
        self._client.loop_stop()
        self._backoff_stop.wait(backoff_s)
        self._backoff_s = min(backoff_s * 2, FLAP_BACKOFF_MAX_S)
        if self._manually_stopped:
            return
        log.info("Reprise des tentatives de connexion MQTT après pause flapping")
        self.connect()

    def _on_message(self, client, userdata, msg):
        # Le topic souscrit est un wildcard (.../#) : seule la trame de télémétrie
        # (suffixe properties/report) alimente le callback, mais on logue tout le
        # reste en debug (replies, ack, erreurs) pour garder de la visibilité en
        # diagnostic — notamment sur function/invoke/reply et properties/read/reply.
        if not msg.topic.endswith("properties/report"):
            log.debug("Reçu %s -> %s", msg.topic, msg.payload[:300])
            return
        # Le contenu de properties/report lui-même n'était jamais logué (seul le
        # reste l'était) — impossible de vérifier ce qu'on reçoit vraiment vs. ce
        # qu'un autre client (HA) reçoit sur la même trame. Log complet en debug.
        log.debug("Reçu %s -> %s", msg.topic, msg.payload)
        try:
            data = json.loads(msg.payload.decode("utf-8"))
        except (ValueError, UnicodeDecodeError):
            log.warning("Trame illisible sur %s", msg.topic)
            return
        if self._telemetry_cb:
            self._telemetry_cb(TelemetryFrame(data))
