"""Profil SuperBase V4600 -- dérivé de SuperBaseV4600.charge()/discharge()
dans Zendure/Zendure-HA (custom_components/zendure_ha/devices/superbasev4600.py,
récupéré le 2026-08-04). PAS testé en conditions réelles sur ce projet (aucun
SuperBase V4600 possédé) -- à valider avant tout usage en production.

Différence confirmée par la source face au Hyper 2000 : la charge utilise
`autoModelProgram: 2` (pas 1) et un `autoModelValue` sans les clés
`price`/`prices` -- ne pas réutiliser telle quelle la forme du Hyper 2000 par
analogie, cette source montre que ça varie réellement d'un modèle à l'autre
même au sein de la famille "Legacy" (deviceAutomation).
"""

from typing import Tuple

from .base import DeviceProfile


class SuperBaseV4600Profile(DeviceProfile):
    name = "superbasev4600"

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
        # chargingPower positif, PAS -watts malgré `-power` côté source HA :
        # leur paramètre `power` a son propre signe interne à cet appel, le
        # nôtre (watts, cf. mqtt_transport.set_input_limit) arrive toujours
        # positif -- même convention que Hyper2000Profile.charge_automation
        # (cf. commentaire équivalent là-bas).
        return {
            "autoModelProgram": 2,
            "autoModelValue": {"chargingType": 1, "chargingPower": int(watts), "freq": 0, "outPower": 0},
            "msgType": 1,
            "autoModel": 8,
        }

    def default_limits(self) -> Tuple[int, int]:
        return (800, 900)
