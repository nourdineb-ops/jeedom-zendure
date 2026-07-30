"""Profils d'appareil Zendure -- isole ce qui varie réellement d'un modèle à
l'autre dans le mécanisme de pilotage (deviceAutomation).

Confirmé par lecture directe du code source de plusieurs classes de device
dans `Zendure/Zendure-HA` (hyper2000.py, hub1200.py, hub2000.py, ace1500.py,
2026-07-30) -- pas une supposition, une différence réelle et documentée :
- La famille Hub (Hub1200, Hub2000) n'a **aucune capacité de charge AC** --
  leur `charge()` met l'appareil en idle, quel que soit ce qu'on lui demande.
- L'ACE1500 utilise un mécanisme entièrement différent (properties/write
  direct, mode standalone/paired, quantification 50W + throttle 30s pour
  ménager la flash) plutôt que function/invoke deviceAutomation.
- Même quand deviceAutomation s'applique, la forme d'`autoModelValue` diffère :
  objet imbriqué pour le Hyper2000 (`{"chargingType":..., "outPower":...}`),
  simple nombre pour les Hub (`autoModelValue: power`).

Un seul profil implémenté à ce jour : `hyper2000.py` -- c'est le seul modèle
réellement possédé/testé sur ce projet. Cette interface existe pour qu'un
futur modèle soit un ajout (un nouveau fichier + une entrée dans REGISTRY),
pas une réécriture de transport/mqtt_transport.py.
"""

from .base import DeviceProfile
from .hyper2000 import Hyper2000Profile

REGISTRY = {
    "hyper2000": Hyper2000Profile,
}

DEFAULT_PROFILE_NAME = "hyper2000"


def build_profile(name: str) -> DeviceProfile:
    """Repli silencieux sur le profil par défaut (pas d'exception) si `name`
    est vide/inconnu : à ce jour un seul profil existe, et la config PHP ne
    propose que celui-là -- un nom vide/inattendu ne peut venir que d'une
    install pas encore migrée, pas d'une vraie ambiguïté de choix."""
    cls = REGISTRY.get(name, REGISTRY[DEFAULT_PROFILE_NAME])
    return cls()


__all__ = ["DeviceProfile", "Hyper2000Profile", "REGISTRY", "DEFAULT_PROFILE_NAME", "build_profile"]
