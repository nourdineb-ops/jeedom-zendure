"""Filtre les valeurs de télémétrie avant envoi au callback Jeedom.

Sans ce filtre, cmd::event() (core Jeedom) écrit une ligne d'historique à
CHAQUE appel, changement ou pas, dès qu'une commande est historisée (vérifié
dans core/class/cmd.class.php::addHistoryValue) — avec la fréquence réelle de
l'appareil (~1-2s en activité), ça remplit `history` pour rien.

Règle (demande explicite, affinée après un premier test en conditions
réelles) :
- une clé n'est renvoyée que si sa valeur a "changé" depuis le dernier envoi —
  pour les valeurs numériques, "changé" veut dire un écart supérieur à
  `noise_threshold` (sans ça, une puissance instantanée qui frémit de 1-2W en
  permanence traverse le filtre à chaque trame, constaté en direct : 52
  écritures/90s sur solar_power malgré le filtre, contre 2 sur soc/grid_power
  qui restent stables plus longtemps),
- sauf si plus de `min_interval_s` secondes se sont écoulées depuis le dernier
  envoi de cette clé (heartbeat, pour ne jamais laisser une commande "morte"
  au-delà d'un délai raisonnable même si la valeur ne bouge pas),
- sauf en mode debug (enable_debug_capture()) : tout passe, sans filtre,
  pendant une durée bornée (expire tout seul, pas besoin de penser à le
  désactiver).

La référence de comparaison (`_last_value`) n'est mise à jour QUE quand une
valeur est effectivement envoyée — sinon une dérive lente par petits pas
sous le seuil de bruit ne serait jamais détectée (chaque pas individuel reste
sous le seuil même si le cumul le dépasse largement).
"""

import time
from typing import Any, Dict


def _values_differ(old: Any, new: Any, noise_threshold: float) -> bool:
    """Écart significatif ? Comparaison numérique tolérante au bruit si les
    deux valeurs sont des nombres, sinon égalité stricte (string, packState...)."""
    try:
        return abs(float(new) - float(old)) > noise_threshold
    except (TypeError, ValueError):
        return old != new


class TelemetryThrottle:
    def __init__(self, min_interval_s: float = 300.0, noise_threshold: float = 0.0):
        self.min_interval_s = min_interval_s
        self.noise_threshold = noise_threshold
        self._last_value: Dict[str, Any] = {}
        self._last_sent_ts: Dict[str, float] = {}
        self._debug_until: float = 0.0

    def enable_debug_capture(self, duration_s: float) -> None:
        self._debug_until = time.monotonic() + duration_s

    @property
    def debug_capture_active(self) -> bool:
        return time.monotonic() < self._debug_until

    def filter(self, values: Dict[str, Any]) -> Dict[str, Any]:
        now = time.monotonic()
        debug = self.debug_capture_active
        out: Dict[str, Any] = {}
        for key, value in values.items():
            changed = key not in self._last_value or _values_differ(self._last_value[key], value, self.noise_threshold)
            stale = (now - self._last_sent_ts.get(key, 0.0)) >= self.min_interval_s
            if debug or changed or stale:
                out[key] = value
                self._last_value[key] = value
                self._last_sent_ts[key] = now
        return out
