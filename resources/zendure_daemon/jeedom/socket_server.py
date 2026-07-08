"""Serveur socket local par lequel le cœur Jeedom (PHP) pousse des événements au démon.

Chaîne réelle (brief §12) : pince → listener PHP → ce socket → démon → décision →
MQTT local. C'est PHP qui se connecte en client sur ce port (`socketport` fourni au
lancement du démon), pas l'inverse.

Protocole : une ligne = un objet JSON, terminé par \\n. Exemples de messages reçus :
  {"type": "grid_power", "eq_id": 12, "value_w": -45.2}
  {"type": "reload_config"}
  {"type": "set_target", "eq_id": 12, "mode": "charge", "soc_min": 20}
"""

import json
import logging
import socket
import threading
from typing import Callable, Dict

log = logging.getLogger("zendure.jeedom.socket")


class JeedomSocketServer:
    def __init__(self, port: int, handlers: Dict[str, Callable[[dict], None]]):
        self._port = port
        self._handlers = handlers
        self._server: socket.socket | None = None
        self._thread: threading.Thread | None = None
        self._stop = threading.Event()

    def start(self) -> None:
        self._server = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
        self._server.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
        self._server.bind(("127.0.0.1", self._port))
        self._server.listen(5)
        self._thread = threading.Thread(target=self._accept_loop, daemon=True)
        self._thread.start()
        log.info("Socket serveur démon en écoute sur 127.0.0.1:%s", self._port)

    def stop(self) -> None:
        self._stop.set()
        if self._server:
            self._server.close()

    def _accept_loop(self) -> None:
        while not self._stop.is_set():
            try:
                conn, _addr = self._server.accept()
            except OSError:
                break
            threading.Thread(target=self._client_loop, args=(conn,), daemon=True).start()

    def _client_loop(self, conn: socket.socket) -> None:
        buffer = b""
        with conn:
            while not self._stop.is_set():
                try:
                    chunk = conn.recv(4096)
                except OSError:
                    break
                if not chunk:
                    break
                buffer += chunk
                while b"\n" in buffer:
                    line, buffer = buffer.split(b"\n", 1)
                    self._dispatch(line)

    def _dispatch(self, line: bytes) -> None:
        if not line.strip():
            return
        try:
            message = json.loads(line.decode("utf-8"))
        except (ValueError, UnicodeDecodeError):
            log.warning("Message socket illisible : %r", line)
            return
        handler = self._handlers.get(message.get("type"))
        if handler is None:
            log.warning("Type de message inconnu : %s", message.get("type"))
            return
        try:
            handler(message)
        except Exception:
            log.exception("Erreur dans le handler pour %s", message.get("type"))
