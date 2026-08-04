"""Profil Hub1200 -- dérivé de Hub1200.charge()/discharge() dans
Zendure/Zendure-HA (custom_components/zendure_ha/devices/hub1200.py,
récupéré le 2026-08-04). PAS testé en conditions réelles sur ce projet
(aucun Hub1200 possédé) -- à valider avant tout usage en production.

Différences confirmées par la source face au Hyper 2000 :
- Pas de charge AC (famille Hub entière, cf. commentaire du fichier source :
  "The HUB family does not have AC charging possibility (even with
  ACE 1500), so set it to idle").
- `autoModelValue` est un entier nu (la puissance directement), pas l'objet
  imbriqué {chargingType, chargingPower, freq, outPower} du Hyper 2000.
"""

from typing import Tuple

from .base import DeviceProfile


class Hub1200Profile(DeviceProfile):
    name = "hub1200"

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
        # Ne lève pas d'exception (cf. base.py) : reprend le payload "idle"
        # que la source envoie elle-même quand charge() est appelée malgré
        # l'absence de charge AC (autoModelProgram=0, "None"), plutôt que de
        # planter -- l'appelant est censé vérifier supports_ac_charge() en
        # amont, ceci n'est qu'un filet de sécurité.
        return {"autoModelProgram": 0, "autoModelValue": 0, "msgType": 1, "autoModel": 0}

    def default_limits(self) -> Tuple[int, int]:
        return (1200, 0)
