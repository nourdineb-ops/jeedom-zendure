"""Pousse les événements du démon (télémétrie, changements de limite) vers le cœur Jeedom.

Le cœur PHP écoute un callback HTTP (core/php/callback.php côté plugin) protégé par
apikey. C'est le canal retour de la chaîne décrite au brief §12 : le démon ne parle
jamais directement à la base Jeedom, uniquement via ce callback (ou le socket pour
le sens PHP -> démon, cf. socket_server.py).
"""

import logging
from typing import Any, Dict

import requests

log = logging.getLogger("zendure.jeedom.callback")


class JeedomCallbackClient:
    def __init__(self, callback_url: str, apikey: str, timeout: float = 5.0):
        self._url = callback_url
        self._apikey = apikey
        self._timeout = timeout

    def send_event(self, eq_id: int, values: Dict[str, Any]) -> None:
        """values : {nom_logique_cmd_info: valeur}, résolu côté PHP vers les cmdId réels."""
        payload = {"apikey": self._apikey, "eq_id": eq_id, "values": values}
        try:
            resp = requests.post(self._url, json=payload, timeout=self._timeout)
            resp.raise_for_status()
        except requests.RequestException:
            log.exception("Échec envoi callback vers Jeedom (eq_id=%s)", eq_id)

    def send_alert(self, eq_id: int, alert_id: str, message: str) -> None:
        """Alerte utilisateur via le centre de notifications interne de Jeedom
        (message::add côté PHP), pas juste une ligne de log -- cf. brief utilisateur
        du 2026-07-22 (rester notifié en cas de perte de connexion/MQTT en boucle,
        pas seulement le découvrir a posteriori dans les logs)."""
        payload = {"apikey": self._apikey, "eq_id": eq_id, "alert_id": alert_id, "message": message}
        try:
            resp = requests.post(self._url, json=payload, timeout=self._timeout)
            resp.raise_for_status()
        except requests.RequestException:
            log.exception("Échec envoi alerte vers Jeedom (eq_id=%s, alert_id=%s)", eq_id, alert_id)

    def send_log(self, level: str, message: str) -> None:
        payload = {"apikey": self._apikey, "log_level": level, "message": message}
        try:
            requests.post(self._url, json=payload, timeout=self._timeout)
        except requests.RequestException:
            log.exception("Échec envoi log vers Jeedom")
