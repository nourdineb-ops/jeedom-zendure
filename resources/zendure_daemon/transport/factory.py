"""Construit le transport MQTT à partir de la config eqLogic (mode_connexion = cloud|local).

Aiguillage par config, pas par déploiement (brief §4/§11) : c'est le seul endroit
où "cloud" et "local" divergent, sur les paramètres de connexion uniquement.
"""

from .mqtt_transport import MqttTransport

DEFAULT_TOPIC_TELEMETRY = "iot/{product_key}/{device_id}/properties/report"
DEFAULT_TOPIC_COMMAND = "iot/{product_key}/{device_id}/properties/write"
DEFAULT_PROPERTY_OUTPUT_LIMIT = "outputLimit"
DEFAULT_PROPERTY_INPUT_LIMIT = "inputLimit"


def build_transport(eq_config: dict) -> MqttTransport:
    mode = eq_config["mode_connexion"]
    if mode == "cloud":
        conn = {
            "host": eq_config.get("cloud_host", "mqtt-eu.zen-iot.com"),
            "port": eq_config.get("cloud_port", 1883),
            "tls": eq_config.get("cloud_tls", False),
            "username": eq_config.get("cloud_device_serial"),
            "password": eq_config.get("cloud_auth_key"),  # Clé Cloud d'Autorisation
        }
    elif mode == "local":
        conn = {
            "host": eq_config["local_host"],
            "port": eq_config.get("local_port", 1883),
            "tls": eq_config.get("local_tls", False),
            "username": eq_config.get("local_username") or None,
            "password": eq_config.get("local_password") or None,
        }
    else:
        raise ValueError(f"mode_connexion inconnu : {mode!r} (attendu cloud|local)")

    conn.update(
        {
            "client_id": f"jeedom-zendure-{eq_config['device_id']}",
            "device_id": eq_config["device_id"],
            "product_key": eq_config.get("product_key", ""),
            "topic_telemetry": eq_config.get("topic_telemetry", DEFAULT_TOPIC_TELEMETRY),
            "topic_command": eq_config.get("topic_command", DEFAULT_TOPIC_COMMAND),
            "property_output_limit": eq_config.get("property_output_limit", DEFAULT_PROPERTY_OUTPUT_LIMIT),
            "property_input_limit": eq_config.get("property_input_limit", DEFAULT_PROPERTY_INPUT_LIMIT),
        }
    )
    return MqttTransport(conn)
