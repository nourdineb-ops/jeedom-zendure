"""Boucle rapide anti-injection (brief §9/§9bis/§12).

Convention de signe : grid_power_w > 0 = import réseau (OK), < 0 = injection.
Objectif : maintenir grid_power_w proche de +marge_anti_injection_w (jamais < 0),
en agissant sur la limite de sortie Zendure (setDeviceAutomationInOutLimit, pas de
flash). "Jamais" est borné au cycle de reporting de la pince, pas un vrai zéro
continu (cf. reformulation §12) : la marge absorbe l'écart entre deux échantillons.

Repris trait pour trait de la branche FAST du scénario Jeedom historique
(scenarioSubElement_id=1534, qui fait référence — vérifié ligne à ligne le
2026-07-11) :

    si grid_power_w >= marge_w : rien à faire ici, on importe assez, la
        correction "à la hausse" est du ressort du cron HP (périodique,
        cf. zendure::cronOptimisationHP() côté PHP), pas de cette boucle
        rapide. Réagir vite dans les DEUX sens (comme le faisait une version
        antérieure de ce fichier) a coïncidé avec une oscillation réseau plus
        sévère en conditions réelles.
    sinon : target = clamp(0, limit_max_w, grid_power_w + injected_power_w - marge_w)
        recalculé en absolu à chaque fois depuis la télémétrie réelle, jamais
        depuis un état interne mémorisé (cf. incident du 2026-07-11 : notre
        compteur interne grimpait à 1200W pendant que la limite réellement
        appliquée par l'appareil restait bloquée à 285W).

Pas d'hystérésis (retirée le 2026-07-11) : le scénario de référence n'en a
aucune, il renvoie la commande à chaque exécution qui passe le cooldown, même
si la valeur ne change presque pas. C'est justement l'interaction hystérésis +
silence prolongé qui avait causé un incident réel (commande jamais renvoyée
pendant 7 minutes). Sans hystérésis, ce problème ne peut plus se produire : dès
qu'un événement passe le gate `grid_power_w < marge_w` et le cooldown, on
envoie, point.

Cooldown : délai minimum entre deux commandes, sauf en cas d'injection avérée
où la sécurité prime (urgent_injection_w) — repris du scénario (FAST_COOLDOWN_S),
avec en plus le bypass "urgent" qui n'existe pas dans le scénario mais reste
jugé utile ici.
"""

import time
from dataclasses import dataclass
from typing import Optional


@dataclass
class AntiInjectionConfig:
    # Coupure complète de la boucle rapide (config équipement, cf. "enabled" ->
    # anti_injection_active côté PHP) : demande explicite pour permettre de
    # cohabiter avec un autre pilote du même appareil (ex. Home Assistant) sans
    # que ce démon ne continue à envoyer des limites de sortie en parallèle.
    # True par défaut : ne change rien au comportement des installs existantes.
    enabled: bool = True
    marge_w: float = 30.0
    cooldown_s: float = 2.0
    limit_min_w: float = 0.0
    limit_max_w: float = 1200.0
    # En dessous de ce seuil (W, peut être négatif), on shunte le cooldown :
    # la sécurité zéro-injection prime sur la limitation du nombre de commandes.
    urgent_injection_w: float = -20.0

    @classmethod
    def from_dict(cls, d: dict) -> "AntiInjectionConfig":
        return cls(**{k: v for k, v in d.items() if k in cls.__dataclass_fields__})


@dataclass
class RegulatorAction:
    power_w: int


class AntiInjectionRegulator:
    def __init__(self, config: AntiInjectionConfig):
        self._cfg = config
        self._last_sent_at: float = 0.0

    def reload_config(self, config: AntiInjectionConfig) -> None:
        self._cfg = config

    def update(self, grid_power_w: float, injected_power_w: float = 0.0, now: Optional[float] = None) -> Optional[RegulatorAction]:
        """Retourne la nouvelle limite de sortie à envoyer, ou None si rien à changer.

        grid_power_w : lecture instantanée de la pince/Tableau_GRID.
        injected_power_w : puissance ACTUELLEMENT délivrée par Zendure à la
        maison (télémétrie réelle, jamais une valeur qu'on a nous-même commandée)."""
        now = now if now is not None else time.monotonic()
        cfg = self._cfg

        if not cfg.enabled:
            return None

        if grid_power_w >= cfg.marge_w:
            # On importe assez (ou trop) : pas de risque d'injection immédiat,
            # on laisse le cron HP périodique gérer l'optimisation à la hausse.
            return None

        target = self._clamp(grid_power_w + injected_power_w - cfg.marge_w)

        urgent = grid_power_w <= cfg.urgent_injection_w
        if not urgent and (now - self._last_sent_at) < cfg.cooldown_s:
            return None

        self._last_sent_at = now
        return RegulatorAction(int(round(target)))

    def _clamp(self, value: float) -> float:
        return max(self._cfg.limit_min_w, min(self._cfg.limit_max_w, value))
