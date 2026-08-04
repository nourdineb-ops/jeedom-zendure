"""Profil SuperBase V6400 -- dérivé de SuperBaseV6400.charge()/discharge()
dans Zendure/Zendure-HA (custom_components/zendure_ha/devices/superbasev6400.py,
récupéré le 2026-08-04). PAS testé en conditions réelles sur ce projet (aucun
SuperBase V6400 possédé) -- à valider avant tout usage en production.

Mécanisme identique à SuperBase V4600 dans la source (même forme de payload,
mêmes limites -900/800) -- deux classes distinctes côté HA malgré un code
strictement équivalent, cf. superbasev4600.py pour le détail des différences
face au Hyper 2000.
"""

from typing import Tuple

from .base import DeviceProfile


class SuperBaseV6400Profile(DeviceProfile):
    name = "superbasev6400"

    def supports_ac_charge(self) -> bool:
        return True

    def discharge_automation(self, watts: int) -> dict:
        return {
            "autoModelProgram": 2,
            "autoModelValue": {"chargingType": 0, "chargingPower": 0, "freq": 0, "outPower": int(watts)},
            "msgType": 1,
            "autoModel": 8,
        }

    def charge_automation(self, watts: int) -> dict:
        return {
            "autoModelProgram": 2,
            "autoModelValue": {"chargingType": 1, "chargingPower": int(watts), "freq": 0, "outPower": 0},
            "msgType": 1,
            "autoModel": 8,
        }

    def default_limits(self) -> Tuple[int, int]:
        return (800, 900)
