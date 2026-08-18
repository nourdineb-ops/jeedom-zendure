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

La FORME exacte de ce payload `deviceAutomation` (autoModelValue en objet imbriqué
vs simple nombre, capacité de charge AC ou non...) diffère réellement d'un modèle
Zendure à l'autre (vérifié dans plusieurs classes de device de `zendure_ha`,
2026-07-30) -- déléguée au profil d'appareil (cf. ../device_profiles/), pas codée
en dur ici. Seule l'enveloppe (arguments/function/messageId/deviceKey/timestamp)
et le reste de ce fichier (acMode, minSoc/socSet, smartMode via properties/write)
sont supposés communs à toute la famille "legacy" -- à vérifier si un jour un
second profil est ajouté.
"""

import json
import logging
import ssl
import threading
import time
from typing import Callable, Optional

import paho.mqtt.client as mqtt

from device_profiles import build_profile

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

# Retry des écritures properties/write sur absence d'accusé de réception.
# Découvert le 16/08 : le device répond bien à chaque properties/write sur
# .../properties/write/reply (success:1 + valeur appliquée, ~150-300ms en
# temps normal) -- PAS du fire-and-forget comme supposé auparavant. Mais le
# device se connecte en clean_session=true (confirmé dans les logs Mosquitto,
# "c1"), donc tout ce qu'on publie pendant qu'il est déconnecté (flap TLS,
# cf. project_local_mqtt_chemin_b) est perdu sans trace côté broker -- mesuré
# en conditions réelles le 16/08 : ~5% des properties/write jamais acquittées
# (207 envoyées, 196 ack, 1 refus explicite, ~10 perdues). WRITE_ACK_TIMEOUT_S
# nettement sous la cadence de la boucle anti-injection (~10s) pour réduire la
# fenêtre où le device tourne sur un réglage périmé, sans pour autant spammer
# le broker à chaque round-trip normal (150-300ms).
WRITE_ACK_TIMEOUT_S = 5.0
WRITE_MAX_RETRIES = 2
WRITE_RETRY_CHECK_INTERVAL_S = 1.0


class MqttTransport(Transport):
    def __init__(self, conn: dict):
        """conn attendu (toutes les clés viennent de la config, cf. Étage 1 du brief) :
        host, port, tls (bool), username, password, client_id,
        device_id, product_key,
        topic_telemetry (souscription wildcard, template avec {device_id}/{product_key}),
        topic_function (topic de commande deviceAutomation, template idem).
        """
        self._conn = conn
        # Profil d'appareil (cf. device_profiles/) : isole ce qui varie réellement
        # d'un modèle Zendure à l'autre dans le mécanisme deviceAutomation --
        # un seul profil existe à ce jour (hyper2000, repli si non configuré).
        self._profile = build_profile(conn.get("device_model", ""))
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

        # Retry des properties/write sans accusé (cf. constantes WRITE_* ci-dessus).
        # messageId -> {"name", "topic", "payload", "sent_at", "attempts"}.
        self._pending_writes: dict = {}
        # Dernier messageId envoyé par nom de propriété -- une relance dont la
        # valeur a été remplacée entre-temps par une commande plus récente
        # (boucle anti-injection) est abandonnée plutôt que ré-émise en retard.
        self._latest_message_id_for_property: dict = {}
        self._pending_lock = threading.Lock()
        self._retry_stop = threading.Event()
        self._retry_thread = threading.Thread(target=self._retry_loop, daemon=True)
        self._retry_thread.start()

        if conn.get("username"):
            self._client.username_pw_set(conn.get("username"), conn.get("password"))
        if conn.get("tls"):
            if conn.get("tls_insecure"):
                # Certificat non vérifié -- pensé pour un broker LOCAL avec un
                # certificat auto-signé (Chemin B, cf. docs/brief_chemin_b_local.md),
                # jamais pour le cloud Zendure. La config PHP (writeDaemonConfig())
                # ne pose ce flag que sous mode_connexion=local, jamais cloud --
                # même filet de sécurité voulu ici, pas de check dupliqué.
                self._client.tls_set(cert_reqs=ssl.CERT_NONE)
                self._client.tls_insecure_set(True)
            else:
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
        self._retry_stop.set()
        self._client.loop_stop()
        self._client.disconnect()

    @property
    def is_connected(self) -> bool:
        with self._lock:
            return self._connected

    def set_output_limit(self, watts: int) -> None:
        # Écriture de propriété brute (outputLimit), PAS deviceAutomation -- reproduit
        # ZendureDevice.entityWrite() de zendure_ha (device.py), la méthode de base
        # dont hérite Hyper2000/ZendureLegacy SANS la redéfinir : leurs curseurs
        # number.output_limit/input_limit passent par là, jamais par charge()/
        # discharge(). Changé le 15/08 : reproduit en direct sur le vrai compte via
        # l'API HA -- 10 écritures de ce type en rafale (10s, 200-350W) n'ont
        # provoqué aucune réactivation du mode intelligent (acMode/autoModel/
        # chargingMode inchangés), alors que la même plage de valeurs via notre
        # ancien chemin deviceAutomation (autoModel=8, cf. historique) y était
        # associée à chaque fois. discharge_automation()/charge_automation() restent
        # dans les profils (cf. device_profiles/) mais ne sont plus appelés d'ici --
        # gardés pour référence/diagnostic, pas supprimés.
        self._publish_property("outputLimit", int(watts), ram=True)

    def set_input_limit(self, watts: int) -> None:
        if not self._profile.supports_ac_charge():
            log.warning(
                "eq_id=%s profil %s : pas de charge AC, set_input_limit(%sW) ignoré",
                self._conn.get("device_id"), self._profile.name, watts,
            )
            return
        self._publish_property("inputLimit", int(watts), ram=True)

    def set_mode(self, mode: int) -> None:
        # acMode (1=input/2=output) passe par properties/write, PAS function/invoke
        # (canal distinct, cf. ZendureDevice.entityWrite dans zendure_ha) — confirmé en direct.
        self._publish_property("acMode", int(mode), ram=True)

    def set_soc_min(self, percent: int) -> None:
        # Facteur x10 côté device (confirmé dans zendure_ha : ZendureNumber(...,
        # factor=10) pour minSoc/socSet, cf. async_set_native_value ->
        # onwrite(factor * value)) -- sans lui, régler 40% envoyait en réalité 4%
        # au boîtier (signalé : le curseur SOC minimum ne faisait pas ce qu'il
        # affichait).
        self._publish_property("minSoc", int(percent) * 10, ram=True)

    def set_soc_max(self, percent: int) -> None:
        # Même mécanique que set_soc_min (propriété socSet, même facteur x10) :
        # c'est le SOC cible où la charge s'arrête (affiché "SOC maximum" côté
        # app/HA) -- absent du plugin jusqu'ici alors que c'est, avec la limite
        # de sortie, l'un des deux leviers réellement utilisés pour piloter la
        # charge en HC (retour utilisateur).
        self._publish_property("socSet", int(percent) * 10, ram=True)

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

    def _publish_automation(self, argument: dict) -> None:
        # NE PAS repousser smartMode=0 ici avant chaque appel -- tenté le 15/08
        # matin puis retiré le jour même. Zendure/Zendure-HA a connu texto le
        # même symptôme (issue #1521, "smart mode alternates after every mqtt
        # command") : leur PR #1507 (commit f4e2267, 12/07) faisait la même
        # chose -- forcer smartMode/acMode à chaque écriture de limite -- pour
        # corriger un autre bug (#1505). Effet de bord confirmé par 4
        # rapporteurs : dès qu'une automation pilote input_limit/output_limit de
        # façon répétée (exactement notre boucle rapide, ~1-2s), smartMode
        # alterne 0/1 à chaque commande et l'appareil se retrouve parqué en
        # standby. Reverté par eux le 11/08 (commit 6fc073d) -- retour à une
        # simple écriture de propriété, sans réaffirmation systématique.
        # On suit la même conclusion : ne pas réaffirmer smartMode à chaque
        # automation. Si smartMode se réactive vraiment tout seul (pas encore
        # confirmé avec certitude, cf. mémoire projet), la correction passe
        # ailleurs -- pas par un forçage à chaque appel, qui est précisément ce
        # que Zendure-HA a dû annuler.
        topic = self._conn["topic_function"].format(
            device_id=self._conn["device_id"], product_key=self._conn.get("product_key", "")
        )
        # deviceAutomation/autoModel=8 : seul mécanisme qui déclenche l'automation embarquée
        # sans écriture flash (setDeviceAutomationInOutLimit, cf. brief §5 point critique #1) —
        # confirmé contre Hyper2000.charge/discharge dans l'intégration zendure_ha. La forme
        # de `argument` (autoModelValue, etc.) vient du profil d'appareil (device_profiles/),
        # seule l'enveloppe (arguments/function/messageId/deviceKey/timestamp) est commune.
        payload = json.dumps(
            {
                "arguments": [argument],
                "function": "deviceAutomation",
                "messageId": self._next_message_id(),
                "deviceKey": self._conn["device_id"],
                "timestamp": int(time.time()),
            }
        )
        log.debug("Publish %s -> %s", topic, payload)
        self._client.publish(topic, payload, qos=1)

    def _publish_property(self, name: str, value, ram: bool = False) -> None:
        # ram=True : ajoute smartMode=1 dans le MÊME message -- "Flash Guard", cf.
        # Utini2000/Zendure-Solarflow-Local-HomeAssistant (README, 15/08) : smartMode
        # sélectionnerait où le firmware écrit un réglage, RAM (volatile, écritures
        # illimitées) si 1, Flash (persiste au redémarrage, nombre d'écritures limité
        # dans le temps) sinon -- pas juste "IA autonome" comme supposé plus tôt dans
        # la journée. Sans ça, set_output_limit/set_input_limit (potentiellement
        # appelées toutes les 1-2s par la boucle anti-injection) userait la Flash en
        # quelques semaines d'usage normal. Étendu à toutes les propriétés pilotées
        # par cohérence (retour utilisateur), pas seulement celles à haute fréquence
        # -- set_smart_mode() lui-même reste séparé, c'est SA valeur qui est pilotée,
        # pas un effet de bord à ajouter.
        properties = {name: value}
        if ram:
            properties["smartMode"] = 1
        topic = self._conn["topic_write"].format(
            device_id=self._conn["device_id"], product_key=self._conn.get("product_key", "")
        )
        message_id = self._next_message_id()
        payload_dict = {
            "deviceId": self._conn["device_id"],
            "messageId": message_id,
            "timestamp": int(time.time()),
            "properties": properties,
        }
        with self._pending_lock:
            self._pending_writes[message_id] = {
                "name": name,
                "topic": topic,
                "payload": payload_dict,
                "sent_at": time.monotonic(),
                "attempts": 1,
            }
            self._latest_message_id_for_property[name] = message_id
        self._publish_write(topic, payload_dict)

    def _publish_write(self, topic: str, payload_dict: dict) -> None:
        payload = json.dumps(payload_dict)
        log.debug("Publish %s -> %s", topic, payload)
        self._client.publish(topic, payload, qos=1)

    def _retry_loop(self) -> None:
        while not self._retry_stop.wait(WRITE_RETRY_CHECK_INTERVAL_S):
            self._check_pending_writes()

    def _check_pending_writes(self) -> None:
        now = time.monotonic()
        to_retry = []
        to_drop = []
        with self._pending_lock:
            for message_id, entry in list(self._pending_writes.items()):
                if now - entry["sent_at"] < WRITE_ACK_TIMEOUT_S:
                    continue
                if self._latest_message_id_for_property.get(entry["name"]) != message_id:
                    # Une commande plus récente a déjà remplacé celle-ci pour
                    # la même propriété -- relancer une valeur périmée serait
                    # contre-productif (pourrait écraser la commande à jour).
                    to_drop.append(message_id)
                    continue
                if entry["attempts"] > WRITE_MAX_RETRIES:
                    to_drop.append(message_id)
                    log.warning(
                        "Commande messageId=%s (%s) abandonnée après %d tentative(s) sans accusé de réception",
                        message_id, entry["payload"].get("properties"), entry["attempts"],
                    )
                    continue
                entry["attempts"] += 1
                entry["sent_at"] = now
                to_retry.append((message_id, entry["topic"], entry["payload"], entry["attempts"]))
            for message_id in to_drop:
                self._pending_writes.pop(message_id, None)
        for message_id, topic, payload_dict, attempt in to_retry:
            log.info(
                "Relance commande messageId=%s (tentative %d/%d, pas d'accusé sous %.0fs) -> %s",
                message_id, attempt, WRITE_MAX_RETRIES + 1, WRITE_ACK_TIMEOUT_S, payload_dict.get("properties"),
            )
            self._publish_write(topic, payload_dict)

    def _handle_write_reply(self, msg) -> None:
        log.debug("Reçu %s -> %s", msg.topic, msg.payload)
        try:
            data = json.loads(msg.payload.decode("utf-8"))
        except (ValueError, UnicodeDecodeError):
            return
        message_id = data.get("messageId")
        success = data.get("success")
        with self._pending_lock:
            entry = self._pending_writes.pop(message_id, None)
        if entry is None:
            return
        if success != 1:
            log.warning(
                "Écriture refusée par l'appareil : messageId=%s properties=%s (success=%s) -- pas de nouvelle "
                "tentative (valeur probablement invalide plutôt que perdue)",
                message_id, entry["payload"].get("properties"), success,
            )

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
        if msg.topic.endswith("properties/write/reply"):
            # Accusé de réception réel (découvert 16/08, cf. constantes WRITE_*) :
            # dépile la commande en attente et coupe court à son propre retry.
            self._handle_write_reply(msg)
            return
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
