"""Boucle rapide anti-injection (brief §9/§9bis/§12).

Convention de signe : grid_power_w > 0 = import réseau (OK), < 0 = injection.
Objectif : maintenir grid_power_w proche de +marge_anti_injection_w (jamais < 0),
en agissant sur la limite de sortie Zendure (setDeviceAutomationInOutLimit, pas de
flash). "Jamais" est borné au cycle de reporting de la pince, pas un vrai zéro
continu (cf. reformulation §12) : la marge absorbe l'écart entre deux échantillons.

Le régulateur est proportionnel (pas d'intégrateur) : l'écart entre puissance
réseau mesurée et la marge cible est reporté ~1:1 sur la limite de sortie, ce qui
suffit ici car la batterie répond quasi linéairement à la consigne. Cooldown et
hystérésis limitent le nombre de commandes envoyées, sauf en cas d'injection
avérée où la sécurité prime sur le cooldown (urgent_injection_w).
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

    @classmethod
    def from_dict(cls, d: dict) -> "AntiInjectionConfig":
        return cls(**{k: v for k, v in d.items() if k in cls.__dataclass_fields__})


class AntiInjectionRegulator:
    def __init__(self, config: AntiInjectionConfig, initial_limit_w: float = 0.0):
        self._cfg = config
        self._current_limit = initial_limit_w
        self._last_sent_at: float = 0.0

    def reload_config(self, config: AntiInjectionConfig) -> None:
        self._cfg = config

    def update(self, grid_power_w: float, now: Optional[float] = None) -> Optional[int]:
        """Retourne la nouvelle limite de sortie (W) à envoyer, ou None si rien à changer."""
        now = now if now is not None else time.monotonic()
        cfg = self._cfg

        error = grid_power_w - cfg.marge_w
        proposed = self._clamp(self._current_limit + error)

        change = abs(proposed - self._current_limit)
        if change < cfg.hysteresis_w:
            return None

        urgent = grid_power_w <= cfg.urgent_injection_w
        if not urgent and (now - self._last_sent_at) < cfg.cooldown_s:
            return None

        self._current_limit = proposed
        self._last_sent_at = now
        return int(round(proposed))

    def _clamp(self, value: float) -> float:
        return max(self._cfg.limit_min_w, min(self._cfg.limit_max_w, value))

    @property
    def current_limit(self) -> float:
        return self._current_limit
