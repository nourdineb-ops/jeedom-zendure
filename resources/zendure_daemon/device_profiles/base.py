"""Interface commune à tous les profils d'appareil -- cf. docstring du package."""

from abc import ABC, abstractmethod
from typing import Tuple


class DeviceProfile(ABC):
    name: str = "generic"

    @abstractmethod
    def supports_ac_charge(self) -> bool:
        """Certains modèles (famille Hub) n'ont aucune capacité de charge AC --
        appeler charge_automation() dessus n'a pas de sens, pas juste un risque
        d'échec silencieux. L'appelant doit vérifier ce booléen avant d'envoyer
        une commande de charge, pas se fier à une exception."""

    @abstractmethod
    def discharge_automation(self, watts: int) -> dict:
        """Construit le dict `arguments[0]` complet (autoModelProgram,
        autoModelValue, msgType, autoModel) pour une commande de décharge via
        function=deviceAutomation."""

    @abstractmethod
    def charge_automation(self, watts: int) -> dict:
        """Comme discharge_automation(), pour une commande de charge. Ne pas
        appeler si supports_ac_charge() est False -- laissé à l'appelant de
        vérifier, cette méthode ne lève pas d'exception elle-même pour rester
        simple à implémenter côté profils futurs."""

    @abstractmethod
    def default_limits(self) -> Tuple[int, int]:
        """(limite décharge W, limite charge W) par défaut pour ce modèle --
        reste substituable par la config utilisateur (limite_min_w/limite_max_w
        côté PHP), sert seulement de valeur de repli raisonnable."""
