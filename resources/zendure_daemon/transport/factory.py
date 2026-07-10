"""Construit le transport MQTT à partir de la config eqLogic (mode_connexion = cloud|local).

Aiguillage par config, pas par déploiement (brief §4/§11) : c'est le seul endroit
où "cloud" et "local" divergent, sur les paramètres de connexion uniquement.
"""

from .mqtt_transport import MqttTransport

# Topics confirmés contre le code source de l'intégration Home Assistant zendure_ha
# (custom_components/zendure_ha/device.py), en production sur ce même Hyper 2000.
# Souscription en wildcard : le filtrage sur properties/report se fait dans
# MqttTransport._on_message.
DEFAULT_TOPIC_TELEMETRY = "iot/{product_key}/{device_id}/#"
DEFAULT_TOPIC_FUNCTION = "iot/{product_key}/{device_id}/function/invoke"
DEFAULT_TOPIC_WRITE = "iot/{product_key}/{device_id}/properties/write"
DEFAULT_TOPIC_READ = "iot/{product_key}/{device_id}/properties/read"


def build_transport(eq_config: dict) -> MqttTransport:
    mode = eq_config["mode_connexion"]
    if mode == "cloud":
        conn = {
            "host": eq_config.get("cloud_host", "mqtteu.zen-iot.com"),
            "port": eq_config.get("cloud_port", 1883),
            "tls": eq_config.get("cloud_tls", False),
            "username": eq_config.get("cloud_username"),
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
            "topic_function": eq_config.get("topic_function", DEFAULT_TOPIC_FUNCTION),
            "topic_write": eq_config.get("topic_write", DEFAULT_TOPIC_WRITE),
            "topic_read": eq_config.get("topic_read", DEFAULT_TOPIC_READ),
        }
    )
    return MqttTransport(conn)
