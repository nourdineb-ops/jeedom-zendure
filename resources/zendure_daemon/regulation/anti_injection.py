"""Boucle rapide anti-injection (brief §9/§9bis/§12).

Convention de signe : grid_power_w > 0 = import réseau (OK), < 0 = injection.
Objectif : maintenir grid_power_w proche de +marge_anti_injection_w (jamais < 0),
en agissant sur la limite de sortie Zendure (setDeviceAutomationInOutLimit, pas de
flash). "Jamais" est borné au cycle de reporting de la pince, pas un vrai zéro
continu (cf. reformulation §12) : la marge absorbe l'écart entre deux échantillons.

Calcul en absolu, PAS en incrémental (corrigé le 2026-07-11 après relecture
précise du scénario Jeedom historique, qui fait référence — cf. les deux
branches FAST et HP de scenarioSubElement_id=1534, mathématiquement
identiques une fois réduites) :

    target = clamp(0, limit_max_w, grid_power_w + injected_power_w - marge_w)

où injected_power_w est la puissance ACTUELLEMENT délivrée par Zendure à la
maison (télémétrie réelle, pas une valeur qu'on a nous-même commandée). La
cible est recalculée intégralement à chaque appel à partir de ces deux mesures
réelles — jamais "dernière limite envoyée + ajustement". Une version antérieure
de ce fichier faisait `proposed = current_limit + error` (incrémental) : ça
fonctionne tant que l'appareil obéit fidèlement, mais dérive dès qu'il n'obéit
pas exactement (constaté en conditions réelles : la limite réellement
appliquée par l'appareil restait bloquée à 285W pendant que notre compteur
interne grimpait jusqu'à 1200W puis en redescendait sans aucun rapport avec la
réalité). Se baser sur la télémétrie réelle à chaque calcul rend le régulateur
auto-correcteur : peu importe ce que l'appareil a réellement fait de la
dernière commande, le prochain calcul repart de faits mesurés, pas d'un
souvenir.

Décharge uniquement (vérifié dans le même scénario de référence) : les
branches FAST et HP ne poussent jamais mode=charge, seulement mode=output,
plancher à 0. Le seul mode=charge de tout le scénario est dans le bloc nuit
(00h-06h), une décision programmée une fois par nuit, hors périmètre de cette
boucle rapide (cf. addendum §9bis : "la boucle lente reste côté scénario
Jeedom").

Cooldown et hystérésis limitent le nombre de commandes envoyées (comparaison
sur la DERNIÈRE VALEUR ENVOYÉE, pas sur la formule elle-même — le calcul reste
toujours absolu), sauf en cas d'injection avérée où la sécurité prime sur le
cooldown (urgent_injection_w). Heartbeat (refresh_interval_s) : renvoie la
cible courante même inchangée au-delà de ce délai — sans ça, un plateau
prolongé (cible stable pendant plusieurs minutes) ne renvoie plus rien et
l'appareil semble reprendre la main tout seul (deviceAutomation expire côté
firmware si non rafraîchi, constaté en conditions réelles le 2026-07-11).
"""

import time
from dataclasses import dataclass
from typing import Optional


@dataclass
class AntiInjectionConfig:
    marge_w: float = 30.0
    cooldown_s: float = 2.0
    hysteresis_w: float = 15.0
    limit_min_w: float = 0.0
    limit_max_w: float = 1200.0
    # En dessous de ce seuil (W, peut être négatif), on shunte le cooldown :
    # la sécurité zéro-injection prime sur la limitation du nombre de commandes.
    urgent_injection_w: float = -20.0
    # Heartbeat : renvoie la cible courante (même inchangée) si ce délai est
    # dépassé depuis le dernier envoi. Volontairement bien en dessous du poll
    # télémétrie (60s).
    refresh_interval_s: float = 30.0

    @classmethod
    def from_dict(cls, d: dict) -> "AntiInjectionConfig":
        return cls(**{k: v for k, v in d.items() if k in cls.__dataclass_fields__})


@dataclass
class RegulatorAction:
    power_w: int


class AntiInjectionRegulator:
    def __init__(self, config: AntiInjectionConfig):
        self._cfg = config
        # Dernière valeur ENVOYÉE (pas la cible calculée) : sert uniquement à
        # l'hystérésis/heartbeat, jamais de base pour le prochain calcul.
        self._last_sent_value: Optional[float] = None
        self._last_sent_at: float = 0.0

    def reload_config(self, config: AntiInjectionConfig) -> None:
        self._cfg = config

    def update(self, grid_power_w: float, injected_power_w: float = 0.0, now: Optional[float] = None) -> Optional[RegulatorAction]:
        """Retourne la nouvelle limite de sortie à envoyer, ou None si rien à changer.

        grid_power_w : lecture instantanée de la pince/Tableau_GRID.
        injected_power_w : puissance ACTUELLEMENT délivrée par Zendure à la
        maison (télémétrie réelle — cf. docstring module, jamais une valeur
        qu'on a nous-même commandée)."""
        now = now if now is not None else time.monotonic()
        cfg = self._cfg

        target = self._clamp(grid_power_w + injected_power_w - cfg.marge_w)

        if self._last_sent_value is None:
            change = float("inf")
        else:
            change = abs(target - self._last_sent_value)
        significant = change >= cfg.hysteresis_w
        stale = (now - self._last_sent_at) >= cfg.refresh_interval_s

        if not significant and not stale:
            return None

        if significant:
            urgent = grid_power_w <= cfg.urgent_injection_w
            if not urgent and (now - self._last_sent_at) < cfg.cooldown_s:
                return None

        self._last_sent_value = target
        self._last_sent_at = now
        return RegulatorAction(int(round(target)))

    def _clamp(self, value: float) -> float:
        return max(self._cfg.limit_min_w, min(self._cfg.limit_max_w, value))
