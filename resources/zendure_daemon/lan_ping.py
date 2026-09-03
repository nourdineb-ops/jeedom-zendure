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


# Chemin du script root dédié (cf. son en-tête pour le contexte complet --
# incident du 2026-08-24 : connexion TCP établie mais jamais nettoyée par le
# noyau avant ~15 min, ping ICMP pourtant bon tout du long). Volontairement
# hors de ce dépôt (provisionné à la main sur le VM, pas déployé par
# scripts/deploy.sh) : ss -K nécessite root, que ce démon (www-data) n'a pas
# en tant que tel -- cf. /etc/sudoers.d/zendure-lan-ping pour la portée exacte
# de ce qui est autorisé.
KILL_ZOMBIE_SCRIPT = "/usr/local/bin/zendure-kill-zombie.sh"

# Le sudo lui-même est quasi instantané (une seule commande système), mais on
# reste généreux pour ne pas ajouter de faux échec sous charge.
KILL_ZOMBIE_TIMEOUT_S = 5


def kill_zombie_connection(ip: str) -> bool:
    """Force la coupure d'une connexion TCP établie vers `ip` (ss -K, via sudo)
    pour provoquer une reconnexion immédiate côté appareil. À n'appeler que
    lorsque le ping ICMP confirme l'appareil joignable ET que la télémétrie
    MQTT est muette depuis un moment (cf. device.py::check_telemetry_staleness)
    -- signature d'une connexion zombie, pas d'une vraie coupure réseau côté
    appareil (dans ce cas, tuer une connexion qui de toute façon n'existe
    plus/n'a jamais existé ne fait rien de nuisible, juste inutile).
    Retourne True si la commande s'est exécutée sans erreur (ne garantit pas
    qu'une connexion existait réellement à tuer -- ss -K sans match n'échoue
    pas)."""
    try:
        result = subprocess.run(
            ["sudo", "-n", KILL_ZOMBIE_SCRIPT, ip],
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            timeout=KILL_ZOMBIE_TIMEOUT_S,
        )
    except (FileNotFoundError, subprocess.TimeoutExpired) as exc:
        log.warning("échec appel auto-heal (ss -K via sudo) pour %s : %s", ip, exc)
        return False
    if result.returncode != 0:
        log.warning(
            "auto-heal (ss -K via sudo) pour %s a échoué (code %d) : %s",
            ip, result.returncode, result.stderr.decode("utf-8", errors="ignore").strip(),
        )
        return False
    log.info("auto-heal : connexion TCP vers %s coupée (%s)", ip, result.stdout.decode("utf-8", errors="ignore").strip() or "aucune connexion à couper")
    return True
