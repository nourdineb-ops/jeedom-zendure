"""Profil Hub2000 -- dérivé de Hub2000.charge()/discharge() dans
Zendure/Zendure-HA (custom_components/zendure_ha/devices/hub2000.py,
récupéré le 2026-08-04). PAS testé en conditions réelles sur ce projet
(aucun Hub2000 possédé) -- à valider avant tout usage en production.

Même mécanisme que Hub1200 (cf. hub1200.py) : pas de charge AC, autoModelValue
en entier nu. Seule différence confirmée par la source : limite de décharge
par défaut (setLimits(0, 1200), identique à Hub1200 malgré maxSolar différent
côté HA -- maxSolar n'est pas repris ici, cf. limite_max_w déjà configurable
côté Comportement).
"""

from typing import Tuple

from .base import DeviceProfile


class Hub2000Profile(DeviceProfile):
    name = "hub2000"

    def supports_ac_charge(self) -> bool:
        return False

    def discharge_automation(self, watts: int) -> dict:
        return {
            "autoModelProgram": 2,
            "autoModelValue": int(watts),
            "msgType": 1,
            "autoModel": 8,
        }

    def charge_automation(self, watts: int) -> dict:
        return {"autoModelProgram": 0, "autoModelValue": 0, "msgType": 1, "autoModel": 0}

    def default_limits(self) -> Tuple[int, int]:
        return (1200, 0)
