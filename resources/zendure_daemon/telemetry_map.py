"""Traduction d'une trame Zendure brute (topic properties/report) -> dict plat
{logicalId: valeur} consommé par callback.php.

Changement de stratégie (échange avec l'utilisateur) : on ne filtre plus sur une
liste figée de clés connues. La trame complète est aplatie (properties, packData,
cluster, wifiName/mac/ip... tout ce qui n'est pas de la plomberie protocole) et
CHAQUE clé devient une commande "info" (créée à la volée côté callback.php si elle
n'existe pas encore). Ça permet à l'utilisateur de choisir lui-même, via l'onglet
Sources, la commande la plus fiable pour un usage donné (ex. injection : notre nom
"injected_power" curé, ou la clé brute "outputHomePower", ou une source externe).

Les logicalId "curés" (solar_power, injected_power, grid_power, soc, output_limit,
input_limit, mode) restent aussi produits en plus des clés brutes, en alias : le
reste du plugin (widgets, valeurs par défaut) continue de fonctionner sans
configuration Sources supplémentaire.
"""

CURATED_ALIASES = {
    "solarInputPower": "solar_power",
    "outputHomePower": "injected_power",
    "gridInputPower": "grid_power",
    "electricLevel": "soc",
    # outputLimit PAS aliasé sur output_limit (contrairement à inputLimit) : ce
    # champ télémétrie dérive spontanément côté appareil, sans rapport avec la
    # valeur réellement commandée (constaté, cf. README "Points ouverts" +
    # reproduit en direct : après une action de la boucle rapide, un écho
    # tardif de ce champ écrasait la valeur qu'on venait tout juste de pousser
    # nous-mêmes, cf. Device.on_grid_power()/runOptimisationHP()). output_limit
    # est désormais alimentée UNIQUEMENT par nos propres pushs (source de
    # vérité = ce qu'on a réellement commandé), jamais par cet écho appareil.
    # La clé brute "outputLimit" reste quand même créée à la volée (comme
    # toute télémétrie non aliasée) si besoin de la consulter à part.
    "inputLimit": "input_limit",
    "acMode": "mode",
}

# Clés de plomberie protocole (pas des mesures) : jamais transformées en commande.
_ENVELOPE_KEYS = {"messageId", "product", "deviceId", "timestamp"}


def _flatten(prefix: str, value, out: dict) -> None:
    if isinstance(value, dict):
        for key, sub in value.items():
            _flatten(f"{prefix}_{key}" if prefix else str(key), sub, out)
    elif isinstance(value, list):
        for i, sub in enumerate(value):
            _flatten(f"{prefix}{i}" if prefix else str(i), sub, out)
    else:
        out[prefix] = value


def translate_properties(frame: dict) -> dict:
    """Aplatit toute la trame (hors clés de plomberie) en {logicalId: valeur},
    en ajoutant les alias curés pour les clés reconnues."""
    flat: dict = {}
    for key, value in frame.items():
        if key in _ENVELOPE_KEYS:
            continue
        if key == "properties":
            # Le wrapper "properties" est transparent : ses enfants remontent
            # directement (solarInputPower, pas properties_solarInputPower).
            _flatten("", value, flat)
        else:
            _flatten(key, value, flat)

    values = dict(flat)
    for raw_key, curated_id in CURATED_ALIASES.items():
        if raw_key in flat:
            values[curated_id] = flat[raw_key]
    return values
