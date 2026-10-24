"""Ping ICMP du boîtier sur le réseau local (cf. device.py::check_lan_reachability).

Diagnostic passif, jamais utilisé pour piloter quoi que ce soit : distingue une
coupure WiFi totale de l'appareil (ni ping, ni MQTT, ni BLE) d'un appareil bien
présent sur le LAN mais dont seule la liaison MQTT/cloud est en rade (ping OK,
silence MQTT) -- cas jusqu'ici indiscernable l'un de l'autre depuis le seul
silence télémétrie (cf. device.py::check_telemetry_staleness).

Passe par le binaire `ping` système (setuid, pas besoin de root ni de socket
raw ICMP côté process Python) plutôt qu'une lib comme icmplib -- une dépendance
de moins, et ce module n'a besoin que d'un aller-retour occasionnel, pas d'un
sondage haute fréquence.
"""

import logging
import re
import subprocess
from typing import Optional, Tuple

log = logging.getLogger("zendure.lan_ping")

_RTT_RE = re.compile(r"time[=<]([\d.]+)")

# Généreux mais borné : un ping qui traîne ne doit pas bloquer la boucle
# périodique du démon (cf. zendure_daemon.py, appelé à la même cadence que
# request_telemetry()) plus qu'un cycle.
PING_TIMEOUT_S = 2


def ping_once(ip: str) -> Tuple[bool, Optional[float]]:
    """Retourne (joignable, latence_ms). latence_ms est None si injoignable
    ou si le parsing de la sortie échoue (le booléen reste la source de vérité)."""
    try:
        result = subprocess.run(
            ["ping", "-c", "1", "-W", str(PING_TIMEOUT_S), ip],
            stdout=subprocess.PIPE,
            stderr=subprocess.DEVNULL,
            timeout=PING_TIMEOUT_S + 1,
        )
    except FileNotFoundError:
        log.warning("binaire `ping` introuvable -- ping réseau local désactivé de fait")
        return False, None
    except subprocess.TimeoutExpired:
        return False, None

    if result.returncode != 0:
        return False, None

    match = _RTT_RE.search(result.stdout.decode("utf-8", errors="ignore"))
    rtt_ms = float(match.group(1)) if match else None
    return True, rtt_ms
