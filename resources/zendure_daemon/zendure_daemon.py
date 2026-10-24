#!/usr/bin/env python3
"""Démon Zendure : lancé/arrêté par le plugin Jeedom (core/class/zendure.class.php).

Arguments alignés sur la convention standard des démons Jeedom en Python :
  --config       chemin du fichier JSON de config (écrit par PHP, cf. config/loader.py)
  --callback     URL HTTP du callback plugin (core/php/callback.php)
  --apikey       apikey du plugin, pour authentifier les appels au callback
  --socketport   port TCP local sur lequel PHP pousse les événements (pince, etc.)
  --pid          fichier pid à écrire
  --loglevel     niveau de log

Rechargement à chaud (brief §11) : SIGHUP relit le fichier de config sans redémarrer
le process, pour ne jamais avoir à recoder un paramètre de comportement.
"""

import argparse
import logging
import signal
import sys
import time
from logging.handlers import RotatingFileHandler
from pathlib import Path

from config.loader import load_config
from device import Device
from jeedom.callback_client import JeedomCallbackClient
from jeedom.socket_server import JeedomSocketServer

log = logging.getLogger("zendure.daemon")

# Rétention des événements significatifs (WARNING+), indépendante du log principal
# (cf. _setup_incident_log()) -- volontairement modeste : ce sont des événements
# rares (staleness, retries abandonnés, redémarrage forcé...), pas le flot DEBUG.
INCIDENT_LOG_MAX_BYTES = 2 * 1024 * 1024
INCIDENT_LOG_BACKUP_COUNT = 5

# Cadence de ping télémétrie (properties/read getAll) : l'appareil ne pousse pas
# spontanément en continu, il faut le solliciter périodiquement (même ordre de
# grandeur que le SCAN_INTERVAL de l'intégration Home Assistant zendure_ha).
TELEMETRY_POLL_S = 60

# Cadence du secours BLE (cf. device.py::maybe_ble_failover, transport/ble_fallback.py) :
# choix de conception -- alignée sur le cron HP côté PHP (5 min),
# pas sur TELEMETRY_POLL_S, pour rester une vérification occasionnelle et ne pas
# créer de contention avec le scan passif d'un autre consommateur du même
# adaptateur Bluetooth (une passerelle BLE tierce).
BLE_FAILOVER_POLL_S = 300


class ZendureDaemon:
    def __init__(self, args: argparse.Namespace):
        self._args = args
        self._devices: dict[int, Device] = {}
        self._callback = JeedomCallbackClient(args.callback, args.apikey)
        self._socket_server = JeedomSocketServer(
            args.socketport,
            handlers={
                "grid_power": self._handle_grid_power,
                "reload_config": lambda _msg: self.reload_config(),
                "action": self._handle_action,
            },
        )
        self._running = False

    def start(self) -> None:
        self.reload_config()
        self._socket_server.start()
        for device in self._devices.values():
            device.start()
        self._running = True
        Path(self._args.pid).write_text(str(__import__("os").getpid()))
        log.info("Démon Zendure démarré (%d équipement(s))", len(self._devices))

    def stop(self) -> None:
        self._running = False
        for device in self._devices.values():
            device.stop()
        self._socket_server.stop()
        log.info("Démon Zendure arrêté")

    def reload_config(self) -> None:
        # Chaque équipement est isolé par un try/except : une exception sur l'un
        # (ex. transport mal configuré, host MQTT vide le temps d'une sauvegarde
        # partielle depuis l'onglet équipement) ne doit jamais empêcher les
        # équipements suivants de la liste d'être (re)chargés ou arrêtés dans la
        # même passe -- constaté en testant un 2e équipement de test
        # mal configuré : l'exception levée dans device.start() remontait hors de
        # reload_config() entière, sautant le traitement de tout ce qui suivait
        # dans la boucle. L'équipement en échec se rattrapait de lui-même au
        # rechargement suivant, mais rien ne garantit qu'il y en ait un avant un
        # moment où ça compte.
        config = load_config(self._args.config)
        seen_ids = set()
        for eq_config in config.get("equipments", []):
            eq_id = eq_config["eq_id"]
            seen_ids.add(eq_id)
            existing = self._devices.get(eq_id)
            try:
                if existing is None:
                    device = Device(eq_config, self._callback)
                    self._devices[eq_id] = device
                    if self._running:
                        device.start()
                else:
                    existing.reload_config(eq_config)
            except Exception:
                log.exception("eq_id=%s échec (re)chargement config, équipement suivant traité quand même", eq_id)

        for eq_id in list(self._devices):
            if eq_id not in seen_ids:
                try:
                    self._devices.pop(eq_id).stop()
                except Exception:
                    log.exception("eq_id=%s échec à l'arrêt (suppression de la config)", eq_id)

    def _handle_grid_power(self, message: dict) -> None:
        device = self._devices.get(message.get("eq_id"))
        if device is None:
            log.warning("grid_power reçu pour eq_id inconnu: %s", message.get("eq_id"))
            return
        device.on_grid_power(float(message["value_w"]))

    def _handle_action(self, message: dict) -> None:
        device = self._devices.get(message.get("eq_id"))
        if device is None:
            log.warning("action reçue pour eq_id inconnu: %s", message.get("eq_id"))
            return
        device.on_action(message.get("logical_id"), message.get("value"))

    def run_forever(self) -> None:
        def handle_sighup(_signum, _frame):
            log.info("SIGHUP reçu, rechargement de la config")
            self.reload_config()

        def handle_sigterm(_signum, _frame):
            self.stop()
            sys.exit(0)

        signal.signal(signal.SIGHUP, handle_sighup)
        signal.signal(signal.SIGTERM, handle_sigterm)
        signal.signal(signal.SIGINT, handle_sigterm)

        self.start()
        last_poll = time.monotonic()
        last_ble_failover = time.monotonic()
        while self._running:
            time.sleep(1)
            now = time.monotonic()
            if now - last_poll >= TELEMETRY_POLL_S:
                last_poll = now
                log.debug("Ping télémétrie périodique (%d équipement(s))", len(self._devices))
                for device in self._devices.values():
                    device.request_telemetry()
                    # check_lan_reachability() avant check_telemetry_staleness() :
                    # cette dernière lit self._lan_reachable pour affiner son message
                    # d'alerte (cf. son docstring) -- le veut frais du même cycle.
                    device.check_lan_reachability()
                    device.check_telemetry_staleness()
                    # Republie transport_connected à chaque tour (cf. son docstring,
                    # bug) : auto-corrige une valeur Jeedom restée
                    # figée/vide suite à un événement de (dé)connexion jamais
                    # correctement repoussé.
                    device.refresh_transport_status()
            if now - last_ble_failover >= BLE_FAILOVER_POLL_S:
                last_ble_failover = now
                for device in self._devices.values():
                    device.maybe_ble_failover()


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser()
    parser.add_argument("--config", required=True)
    parser.add_argument("--callback", required=True)
    parser.add_argument("--apikey", required=True)
    parser.add_argument("--socketport", type=int, required=True)
    parser.add_argument("--pid", required=True)
    # Optionnel : absent = à côté de --config (ancien comportement, cf.
    # _setup_incident_log()).
    parser.add_argument("--incidentlog")
    parser.add_argument("--loglevel", default="info")
    return parser.parse_args()


def _setup_incident_log(config_path: str, incident_log: str | None = None) -> None:
    """Ajoute un 2e handler (WARNING+ seulement) vers un fichier séparé, avec SA
    PROPRE rotation (backups gardés) -- ajouté pour faciliter l'analyse
    d'incident, rendue difficile par la
    découverte que le log principal (`--loglevel debug`, `log::getPathToLog(
    'zendure_daemon')` côté PHP, cf. deamon_start()) est VIDÉ SANS AUCUNE
    SAUVEGARDE par le cœur Jeedom dès qu'il dépasse `maxSizeLog` (5 Mo par
    défaut, cf. log.class.php::checkLogSize(), `truncate -s 0`) -- comportement
    du cœur, commun à tous les plugins, PAS à modifier ici. Vu le volume DEBUG
    de ce démon (plusieurs lignes/s), ce plafond est atteint en quelques
    heures : un incident découvert le lendemain n'a souvent déjà plus aucune
    trace exploitable.

    Écrit donc EN PLUS, pas à la place, un flux réduit (WARNING+ : staleness,
    retries de commande abandonnés, redémarrage forcé, flapping MQTT...) vers
    un fichier HORS de `log/` (donc hors de portée de checkLogSize()/clearAll()
    du cœur), avec une rotation Python (RotatingFileHandler, 5 x 2 Mo) qui,
    elle, garde ses anciennes versions (`.1` à `.5`) au lieu de les détruire --
    largement suffisant pour ce volume, une vraie fenêtre d'analyse de
    plusieurs jours/semaines au lieu de quelques heures.

    Chemin fourni par PHP (--incidentlog, data/zendure/ du cœur, cf.
    zendure::incidentLogPath()). Repli à côté de daemon_config.json sinon --
    mais ce dossier (jeedom::getTmpFolder) est VIDÉ à chaque redémarrage de la
    VM, contrairement à ce qui avait été supposé ici à l'origine (historique
    perdu au reboot du)."""
    if incident_log:
        incident_path = Path(incident_log)
    else:
        incident_path = Path(config_path).resolve().parent / "incidents.log"
    handler = RotatingFileHandler(
        str(incident_path),
        maxBytes=INCIDENT_LOG_MAX_BYTES,
        backupCount=INCIDENT_LOG_BACKUP_COUNT,
        encoding="utf-8",
    )
    handler.setLevel(logging.WARNING)
    handler.setFormatter(logging.Formatter("%(asctime)s %(levelname)s [%(name)s] %(message)s"))
    logging.getLogger().addHandler(handler)
    log.info("Journal d'incidents (WARNING+, rotation propre) : %s", incident_path)


def main() -> None:
    args = parse_args()
    logging.basicConfig(
        level=getattr(logging, args.loglevel.upper(), logging.INFO),
        format="%(asctime)s %(levelname)s [%(name)s] %(message)s",
    )
    _setup_incident_log(args.config, args.incidentlog)
    daemon = ZendureDaemon(args)
    daemon.run_forever()


if __name__ == "__main__":
    main()
