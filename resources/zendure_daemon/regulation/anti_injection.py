"""Boucle rapide anti-injection (brief §9/§9bis/§12).

Convention de signe : grid_power_w > 0 = import réseau (OK), < 0 = injection.
Objectif : maintenir grid_power_w proche de +marge_anti_injection_w (jamais < 0),
en agissant sur la limite de sortie Zendure (setDeviceAutomationInOutLimit, pas de
flash). "Jamais" est borné au cycle de reporting de la pince, pas un vrai zéro
continu (cf. reformulation §12) : la marge absorbe l'écart entre deux échantillons.

Historique (jusqu'au 2026-07-28) : cette boucle ne réagissait QUE côté
injection (`grid_power_w < marge_w`) — repris trait pour trait de la branche
FAST du scénario Jeedom historique (scenarioSubElement_id=1534, vérifié ligne
à ligne le 2026-07-11), qui délègue toute correction "à la hausse" (import
excessif) au cron HP périodique (5 min à l'époque, cf. `zendure::cronOptimisationHP()`
côté PHP). Une version antérieure réagissait déjà dans les deux sens et
"a coïncidé avec une oscillation réseau plus sévère en conditions réelles"
(cause exacte jamais tranchée). Incident réel du 2026-07-28 (remonté par
l'utilisateur) : un pic PAPP aberrant (-1683W, quasi certainement un glitch
de mesure) a déclenché une coupure d'urgence à 0W ; le sens import étant
totalement muet, `output_limit` est resté bloqué à 0W pendant les 5 minutes
complètes jusqu'au cron suivant, pendant que tout le solaire partait charger
la batterie et que la maison tournait entièrement sur le réseau.

**Corrigé le 2026-07-28** : la boucle réagit maintenant aussi côté import,
mais avec deux garde-fous distincts du sens injection pour ne pas reproduire
l'oscillation historique :
- `cooldown_import_s` (défaut 15s, pas `cooldown_s`) : plus lent que côté
  injection, calé sur le temps de stabilisation réel de l'appareil observé le
  2026-07-28 (grid retombé ~10-15s après une nouvelle commande) -- laisse le
  temps à la commande précédente de faire effet avant d'en recalculer une
  autre par-dessus, plutôt que de réagir à une mesure qui n'a pas encore
  rattrapé la dernière action.
- `import_tolerance_pct` (défaut 10%) : zone morte autour de la dernière
  valeur commandée, pour ne pas renvoyer une commande quasi identique à
  chaque cycle. Uniquement côté import -- le sens injection/urgence reste
  sans zone morte, la réactivité y prime toujours sur la stabilité.

Repris du reste de la logique de référence :
    target = clamp(0, limit_max_w, grid_power_w + injected_power_w - marge_w)
        recalculé en absolu à chaque fois depuis la télémétrie réelle, jamais
        depuis un état interne mémorisé (cf. incident du 2026-07-11 : notre
        compteur interne grimpait à 1200W pendant que la limite réellement
        appliquée par l'appareil restait bloquée à 285W).

Pas d'hystérésis côté injection (retirée le 2026-07-11) : le scénario de
référence n'en a aucune, il renvoie la commande à chaque exécution qui passe
le cooldown, même si la valeur ne change presque pas. C'est justement
l'interaction hystérésis + silence prolongé qui avait causé un incident réel
(commande jamais renvoyée pendant 7 minutes). Sans hystérésis, ce problème ne
peut plus se produire côté injection : dès qu'un événement passe le gate
`grid_power_w < marge_w` et le cooldown, on envoie, point. La zone morte
`import_tolerance_pct` ci-dessus n'est PAS une hystérésis au même sens
(pas de mémoire d'état, juste un écart accepté autour de la dernière valeur
envoyée) et ne s'applique qu'au sens import, jamais concerné par cet incident.

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
    # Cadence du sens import (grid >= marge), distincte et plus lente que
    # cooldown_s -- cf. docstring de tête de fichier.
    cooldown_import_s: float = 15.0
    # Zone morte en % autour de la dernière valeur commandée, sens import
    # uniquement (jamais côté urgence/injection).
    import_tolerance_pct: float = 10.0
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
        self._last_sent_import_at: float = 0.0
        # Dernière valeur RÉELLEMENT envoyée (les deux sens confondus) --
        # sert de référence à la zone morte du sens import. None tant
        # qu'aucune commande n'a encore été envoyée (pas de zone morte à
        # appliquer sur du "rien").
        self._last_target_w: Optional[float] = None

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

        target = self._clamp(grid_power_w + injected_power_w - cfg.marge_w)

        if grid_power_w >= cfg.marge_w:
            # Import, pas de risque d'injection immédiat : cadence dédiée
            # (cooldown_import_s) + zone morte (import_tolerance_pct), cf.
            # docstring de tête de fichier -- corrige l'incident du
            # 2026-07-28 (output_limit resté bloqué 5 min après une coupure
            # d'urgence) sans reproduire l'oscillation d'une tentative
            # antérieure qui réagissait aussi vite que côté injection.
            if (now - self._last_sent_import_at) < cfg.cooldown_import_s:
                return None
            if self._last_target_w is not None:
                tolerance = (cfg.import_tolerance_pct / 100.0) * abs(self._last_target_w)
                if abs(target - self._last_target_w) <= tolerance:
                    return None
            self._last_sent_import_at = now
            self._last_sent_at = now
            self._last_target_w = target
            return RegulatorAction(int(round(target)))

        urgent = grid_power_w <= cfg.urgent_injection_w
        if not urgent and (now - self._last_sent_at) < cfg.cooldown_s:
            return None

        self._last_sent_at = now
        self._last_target_w = target
        return RegulatorAction(int(round(target)))

    def _clamp(self, value: float) -> float:
        return max(self._cfg.limit_min_w, min(self._cfg.limit_max_w, value))
