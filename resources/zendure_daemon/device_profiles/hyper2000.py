"""Profil Hyper 2000 -- reprend exactement la logique historique de ce plugin,
inchangée par ce refactor. Charge confirmée avec effet réel en conditions
réelles le 2026-07-28 ; décharge (anti-injection) en usage continu depuis le
début du projet. Cf. Hyper2000.charge()/discharge() dans Zendure/Zendure-HA
pour la même forme de payload côté intégration officielle."""

from typing import Tuple

from .base import DeviceProfile


class Hyper2000Profile(DeviceProfile):
    name = "hyper2000"

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
        # chargingPower est positif côté device même si watts représente une
        # limite de charge (cf. commentaire historique de mqtt_transport.py).
        return {
            "autoModelProgram": 1,
            "autoModelValue": {
                "chargingType": 1,
                "price": 2,
                "chargingPower": int(watts),
                "prices": [1] * 24,
                "outPower": 0,
                "freq": 0,
            },
            "msgType": 1,
            "autoModel": 8,
        }

    def default_limits(self) -> Tuple[int, int]:
        return (1200, 1200)
