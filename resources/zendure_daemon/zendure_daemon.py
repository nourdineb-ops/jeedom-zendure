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
from pathlib import Path

from config.loader import load_config
from device import Device
from jeedom.callback_client import JeedomCallbackClient
from jeedom.socket_server import JeedomSocketServer

log = logging.getLogger("zendure.daemon")

# Cadence de ping télémétrie (properties/read getAll) : l'appareil ne pousse pas
# spontanément en continu, il faut le solliciter périodiquement (même ordre de
# grandeur que le SCAN_INTERVAL de l'intégration Home Assistant zendure_ha).
TELEMETRY_POLL_S = 60


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
        config = load_config(self._args.config)
        seen_ids = set()
        for eq_config in config.get("equipments", []):
            eq_id = eq_config["eq_id"]
            seen_ids.add(eq_id)
            existing = self._devices.get(eq_id)
            if existing is None:
                device = Device(eq_config, self._callback)
                self._devices[eq_id] = device
                if self._running:
                    device.start()
            else:
                existing.reload_config(eq_config)

        for eq_id in list(self._devices):
            if eq_id not in seen_ids:
                self._devices.pop(eq_id).stop()

    def _handle_grid_power(self, message: dict) -> None:
        device = self._devices.get(message.get("eq_id"))
        if device is None:
            log.warning("grid_power reçu pour eq_id inconnu: %s", message.get("eq_id"))
            return
        device.on_grid_power(float(message["value_w"]))

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
        while self._running:
            time.sleep(1)
            now = time.monotonic()
            if now - last_poll >= TELEMETRY_POLL_S:
                last_poll = now
                for device in self._devices.values():
                    device.request_telemetry()


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser()
    parser.add_argument("--config", required=True)
    parser.add_argument("--callback", required=True)
    parser.add_argument("--apikey", required=True)
    parser.add_argument("--socketport", type=int, required=True)
    parser.add_argument("--pid", required=True)
    parser.add_argument("--loglevel", default="info")
    return parser.parse_args()


def main() -> None:
    args = parse_args()
    logging.basicConfig(
        level=getattr(logging, args.loglevel.upper(), logging.INFO),
        format="%(asctime)s %(levelname)s [%(name)s] %(message)s",
    )
    daemon = ZendureDaemon(args)
    daemon.run_forever()


if __name__ == "__main__":
    main()
