"""Interface de transport commune (Chemin A cloud / Chemin B local).

Les deux chemins parlent le même protocole (MQTT, mêmes topics/charges utiles côté
appareil Zendure) : seule la connexion change (broker, auth). Voir MqttTransport,
qui implémente cette interface pour les deux modes via des paramètres de connexion
différents (pas de duplication de logique).
"""

from abc import ABC, abstractmethod
from typing import Callable, Optional


class TelemetryFrame(dict):
    """Trame de télémétrie brute décodée (clés = champs Zendure, voir référentiels §6/§15)."""


class Transport(ABC):
    @abstractmethod
    def connect(self) -> None:
        ...

    @abstractmethod
    def disconnect(self) -> None:
        ...

    @property
    @abstractmethod
    def is_connected(self) -> bool:
        ...

    @abstractmethod
    def set_output_limit(self, watts: int) -> None:
        """Pousse la limite de puissance de sortie via setDeviceAutomationInOutLimit (pas d'écriture flash)."""

    @abstractmethod
    def set_input_limit(self, watts: int) -> None:
        """Pousse la limite de puissance d'entrée solaire via le même mécanisme non-flash."""

    @abstractmethod
    def set_mode(self, mode: int) -> None:
        """Bascule le mode de fonctionnement (1=input/charge, 2=output/décharge), propriété acMode."""

    @abstractmethod
    def set_soc_min(self, percent: int) -> None:
        """Pousse le seuil SOC minimum (%), propriété minSoc."""

    @abstractmethod
    def set_soc_max(self, percent: int) -> None:
        """Pousse le seuil SOC maximum/cible de charge (%), propriété socSet."""

    @abstractmethod
    def set_smart_mode(self, enabled: bool) -> None:
        """Pousse la propriété smartMode (0/1). Le firmware Zendure semble déjà la
        déduire lui-même dès qu'une commande deviceAutomation arrive (constaté via
        l'app mobile), mais zendure_ha l'écrit aussi explicitement (device.py,
        power_charge/power_discharge) — on fait pareil, et surtout on la repasse à 0
        à l'arrêt propre du démon pour que l'app ne reste pas indéfiniment sur
        "Mode intelligent" après qu'on ait rendu la main à l'appareil."""

    @abstractmethod
    def request_telemetry(self) -> None:
        """Demande une trame de télémétrie à jour (getAll). L'appareil ne pousse pas
        spontanément en continu : sans ce ping périodique (cf. dataRefresh dans
        zendure_ha, toutes les 60s), la télémétrie ne remonte quasiment jamais."""

    @abstractmethod
    def on_telemetry(self, callback: Callable[[TelemetryFrame], None]) -> None:
        """Enregistre le callback appelé à chaque trame de télémétrie reçue."""

    @abstractmethod
    def on_connection_change(self, callback: Callable[[bool], None]) -> None:
        """Enregistre le callback appelé quand l'état de connexion change (pour supervision)."""

    @abstractmethod
    def on_connection_issue(self, callback: Callable[[str, str], None]) -> None:
        """Enregistre le callback appelé pour un problème de connexion significatif
        (ex. reconnexions en rafale) qui mérite une alerte utilisateur, pas juste un
        log -- (issue_id, message). issue_id sert de clé de dédoublonnage côté Jeedom
        (message::add), message est le texte affiché. Un second appel avec le même
        issue_id et un message contenant "rétabli"/"résolu" signale un retour à la
        normale."""
