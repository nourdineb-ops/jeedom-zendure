"""Profils d'appareil Zendure -- isole ce qui varie réellement d'un modèle à
l'autre dans le mécanisme de pilotage (deviceAutomation).

Confirmé par lecture directe du code source de plusieurs classes de device
dans `Zendure/Zendure-HA` (hyper2000.py, hub1200.py, hub2000.py, aio2400.py,
superbasev4600.py, superbasev6400.py, ace1500.py, 2026-07-30 puis 2026-08-04)
-- pas une supposition, une différence réelle et documentée :
- La famille Hub (Hub1200, Hub2000) et l'AIO2400 n'ont **aucune capacité de
  charge AC** -- leur `charge()` met l'appareil en idle, quel que soit ce
  qu'on lui demande.
- Même quand deviceAutomation s'applique, la forme d'`autoModelValue` diffère
  réellement d'un modèle à l'autre : objet imbriqué pour le Hyper2000/AIO2400/
  SuperBase (`{"chargingType":..., "outPower":...}`, avec en plus `price`/
  `prices` uniquement chez le Hyper2000 en charge), simple nombre pour les Hub
  (`autoModelValue: power`). Le SuperBase utilise même `autoModelProgram: 2`
  en charge (le Hyper2000 utilise `1`) -- ne jamais extrapoler la forme d'un
  modèle non vérifié par analogie avec un autre, toujours relire sa classe
  source avant d'ajouter un profil.

Deux familles de modèles Zendure PAS couvertes par cette abstraction, à ne
pas tenter d'ajouter comme un profil de plus sans travail préalable côté
transport (cf. `Zendure/Zendure-HA`, récupéré le 2026-08-04) :
- **ACE1500** : mode par défaut ("paired") nécessite un vrai Hub Zendure qui
  émette un battement périodique -- sans lui, deviceAutomation ne fait rien.
  Le mode "standalone" utilisable sans Hub ne passe PAS par deviceAutomation
  mais par `properties/write` direct sur `inputLimit`, avec quantification
  50W et throttle 30s pour ménager la flash (écritures répétées = usure) --
  mécanisme différent de tout ce que `mqtt_transport.py` sait faire
  aujourd'hui.
- **Famille SolarFlow** (800/1600/2400/4000) : hérite de `ZendureZenSdk`, pas
  `ZendureLegacy` -- protocole entièrement différent, jamais examiné sur ce
  projet.

Profils implémentés à ce jour : `hyper2000.py` (seul modèle réellement
possédé/testé sur ce projet -- charge et décharge confirmées avec effet réel
en conditions réelles), et cinq profils dérivés de la lecture du code source
officiel mais **jamais testés contre un appareil réel** (`hub1200.py`,
`hub2000.py`, `aio2400.py`, `superbasev4600.py`, `superbasev6400.py`) --
à considérer comme un point de départ à valider, pas une garantie de bon
fonctionnement. Cette interface existe pour qu'un futur modèle soit un ajout
(un nouveau fichier + une entrée dans REGISTRY), pas une réécriture de
transport/mqtt_transport.py.
"""

from .aio2400 import Aio2400Profile
from .base import DeviceProfile
from .hub1200 import Hub1200Profile
from .hub2000 import Hub2000Profile
from .hyper2000 import Hyper2000Profile
from .superbasev4600 import SuperBaseV4600Profile
from .superbasev6400 import SuperBaseV6400Profile

REGISTRY = {
    "hyper2000": Hyper2000Profile,
    "hub1200": Hub1200Profile,
    "hub2000": Hub2000Profile,
    "aio2400": Aio2400Profile,
    "superbasev4600": SuperBaseV4600Profile,
    "superbasev6400": SuperBaseV6400Profile,
}

DEFAULT_PROFILE_NAME = "hyper2000"


def build_profile(name: str) -> DeviceProfile:
    """Repli silencieux sur le profil par défaut (pas d'exception) si `name`
    est vide/inconnu : un nom vide/inattendu ne peut venir que d'une install
    pas encore migrée ou d'un modèle non couvert (cf. docstring de tête de
    fichier), pas d'une vraie ambiguïté de choix parmi les profils connus."""
    cls = REGISTRY.get(name, REGISTRY[DEFAULT_PROFILE_NAME])
    return cls()


__all__ = [
    "DeviceProfile",
    "Hyper2000Profile",
    "Hub1200Profile",
    "Hub2000Profile",
    "Aio2400Profile",
    "SuperBaseV4600Profile",
    "SuperBaseV6400Profile",
    "REGISTRY",
    "DEFAULT_PROFILE_NAME",
    "build_profile",
]
