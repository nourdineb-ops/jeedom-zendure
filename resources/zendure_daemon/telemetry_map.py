"""Traduction clé Zendure brute -> logicalId de commande Jeedom (brief addendum §14.1 :
"mapper 1:1 sur les topics du Hyper 2000"). Noms de propriété Zendure confirmés contre
le code source de l'intégration Home Assistant zendure_ha (custom_components/zendure_ha/device.py).

Les commandes total_output_kwh / total_solar_kwh / total_from_edf_kwh / forecast_today_kwh
ne sont volontairement pas ici : ce ne sont pas des lectures brutes Zendure (agrégats /
prévision solaire, cf. §14.2), elles restent hors de ce mapping.
"""

PROPERTY_TO_LOGICAL_ID = {
    "solarInputPower": "solar_power",
    "outputHomePower": "injected_power",
    "gridInputPower": "grid_power",
    "electricLevel": "soc",
    "outputLimit": "output_limit",
    "inputLimit": "input_limit",
    "acMode": "mode",
}


def translate_properties(properties: dict) -> dict:
    """Filtre + traduit les clés reconnues ; les clés Zendure sans commande Jeedom
    correspondante (packState, hemsState, remainOutTime...) sont ignorées ici."""
    return {
        PROPERTY_TO_LOGICAL_ID[key]: value
        for key, value in properties.items()
        if key in PROPERTY_TO_LOGICAL_ID
    }
