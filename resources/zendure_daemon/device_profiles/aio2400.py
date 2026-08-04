"""Profil AIO 2400 -- dérivé de AIO2400.charge()/discharge() dans
Zendure/Zendure-HA (custom_components/zendure_ha/devices/aio2400.py,
récupéré le 2026-08-04). PAS testé en conditions réelles sur ce projet
(aucun AIO2400 possédé) -- à valider avant tout usage en production.

Décharge : même forme d'`autoModelValue` imbriqué que le Hyper 2000
(chargingType/chargingPower/freq/outPower), contrairement à la famille Hub
(entier nu, cf. hub1200.py/hub2000.py). Pas de charge AC (commentaire source :
"AIO 2400 cannot charge using AC") -- la source ne publie même aucune
commande dans ce cas (juste un log), reproduit ici en renvoyant l'automation
"idle" plutôt que rien, pour rester cohérent avec l'interface DeviceProfile
qui doit toujours retourner un dict exploitable.
"""

from typing import Tuple

from .base import DeviceProfile


class Aio2400Profile(DeviceProfile):
    name = "aio2400"

    def supports_ac_charge(self) -> bool:
        return False

    def discharge_automation(self, watts: int) -> dict:
        return {
            "autoModelProgram": 2,
            "autoModelValue": {"chargingType": 0, "chargingPower": 0, "freq": 0, "outPower": int(watts)},
            "msgType": 1,
            "autoModel": 8,
        }

    def charge_automation(self, watts: int) -> dict:
        return {
            "autoModelProgram": 0,
            "autoModelValue": {"chargingType": 0, "chargingPower": 0, "freq": 0, "outPower": 0},
            "msgType": 1,
            "autoModel": 0,
        }

    def default_limits(self) -> Tuple[int, int]:
        return (1200, 0)
