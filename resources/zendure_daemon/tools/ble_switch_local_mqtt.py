#!/usr/bin/env python3
"""Reconfigure un Hyper 2000 (ou autre appareil Zendure "legacy") pour qu'il se
connecte à un broker MQTT local plutôt qu'au cloud Zendure -- en Bluetooth,
sans DNS trafiqué.

Rejoue exactement le mécanisme de l'intégration officielle Home Assistant
(github.com/Zendure/Zendure-HA, `device.py::bleMqtt()`) : deux commandes JSON
écrites sur la même caractéristique GATT que nos lectures BLE déjà confirmées
en direct sur cet appareil (cf. transport/ble_fallback.py, protocole confirmé
le 2026-07-22) :

1. "token" : pousse la nouvelle URL du broker (`iotUrl`) -- redemande AUSSI le
   SSID/mot de passe WiFi actuels (le firmware traite ça comme une commande
   générale de reconfiguration réseau, pas juste MQTT). Mauvaise valeur ici =
   risque de perdre le WiFi de l'appareil en plus de rater le changement MQTT.
2. "station" : bascule/valide le mode station (fonctionnement normal).

Le port MQTT n'est pas dans le payload : le firmware est câblé sur 1883 (non
configurable), cf. retours communauté (iobroker.zendure-solarflow,
solarflow-control) -- le broker local doit donc écouter là, sans TLS,
authentification anonyme (mot de passe appareil codé en dur, non configurable
non plus).

INCERTITUDE ASSUMÉE : nos précédentes écritures BLE (propriétés
deviceAutomation, cf. docs/brief_strategie_charge.md) ont été acceptées sans
erreur mais sans effet confirmé sur l'appareil. Ce script rejoue une commande
différente ("token"/"station"), reprise du code officiel Zendure -- plus de
raisons d'y croire, mais pas encore vérifié sur CET appareil. D'où le
dry-run par défaut et la vérification post-écriture (écoute du broker local).

Usage :
    # 1. Dry-run (aucune connexion BLE, affiche juste ce qui serait envoyé) :
    python3 ble_switch_local_mqtt.py --address AA:BB:CC:DD:EE:FF \\
        --host 192.168.1.12 --ssid MonWifi --password-file /chemin/vers/mdp.txt

    # 2. Exécution réelle (écrit vraiment sur l'appareil) :
    python3 ble_switch_local_mqtt.py --address AA:BB:CC:DD:EE:FF \\
        --host 192.168.1.12 --ssid MonWifi --password-file /chemin/vers/mdp.txt \\
        --execute

    # 3. Vérifie ensuite (avec ou sans --execute juste avant) que le broker
    #    local voit vraiment passer de la télémétrie de l'appareil :
    python3 ble_switch_local_mqtt.py --verify-only --verify-seconds 60 \\
        --product-key gDa3tb --device-id 3HbS2U4m
"""

import argparse
import asyncio
import json
import sys
import time
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from bleak import BleakClient  # noqa: E402
from transport.ble_fallback import CHAR_WRITE  # noqa: E402

TIMEZONE = "GMT+01:00"
CONNECT_TIMEOUT_S = 20.0
DELAY_BETWEEN_COMMANDS_S = 2.0


def build_token_command(host: str, ssid: str, password: str) -> dict:
    return {
        "iotUrl": host,
        "messageId": 1002,
        "method": "token",
        "password": password,
        "ssid": ssid,
        "timeZone": TIMEZONE,
        "token": "abcdefgh",
    }


def build_station_command() -> dict:
    return {"messageId": 1003, "method": "station"}


def redact(command: dict) -> dict:
    redacted = dict(command)
    if "password" in redacted:
        redacted["password"] = "***" if redacted["password"] else "(vide !)"
    return redacted


async def send_commands(address: str, token_cmd: dict, station_cmd: dict) -> None:
    print(f"Connexion BLE à {address} (timeout {CONNECT_TIMEOUT_S}s)...")
    client = BleakClient(address)
    await asyncio.wait_for(client.connect(), timeout=CONNECT_TIMEOUT_S)
    try:
        print("Connecté. Envoi de la commande 'token'...")
        await client.write_gatt_char(
            CHAR_WRITE, json.dumps(token_cmd).encode("utf-8"), response=False
        )
        await asyncio.sleep(DELAY_BETWEEN_COMMANDS_S)
        print("Envoi de la commande 'station'...")
        await client.write_gatt_char(
            CHAR_WRITE, json.dumps(station_cmd).encode("utf-8"), response=False
        )
        print("Les deux commandes ont été envoyées (écriture sans réponse -- pas de "
              "confirmation GATT que l'appareil les a traitées). Utiliser --verify-only "
              "pour vérifier si la télémétrie apparaît sur le broker local.")
    finally:
        await client.disconnect()


async def verify_local_broker(product_key: str, device_id: str, seconds: float) -> None:
    import paho.mqtt.client as mqtt

    seen = asyncio.Event()
    loop = asyncio.get_event_loop()

    def on_message(_client, _userdata, msg):
        if product_key in msg.topic and device_id in msg.topic:
            print(f"  reçu sur {msg.topic} : {msg.payload[:200]!r}")
            loop.call_soon_threadsafe(seen.set)

    client = mqtt.Client()
    client.on_message = on_message
    client.connect("127.0.0.1", 1883, keepalive=30)
    client.subscribe("#")
    client.loop_start()
    print(f"Écoute du broker local pendant {seconds:.0f}s pour {product_key}/{device_id}...")
    try:
        await asyncio.wait_for(seen.wait(), timeout=seconds)
        print("OK : télémétrie vue sur le broker local, la bascule a fonctionné.")
    except asyncio.TimeoutError:
        print("RIEN VU : soit la bascule n'a pas pris, soit l'appareil n'a pas encore "
              "retenté sa connexion (certains firmwares attendent le prochain cycle de "
              "poll/reboot). Pas forcément un échec définitif après un seul essai court.")
    finally:
        client.loop_stop()
        client.disconnect()


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--address", help="Adresse MAC BLE de l'appareil (cf. config ble_address)")
    parser.add_argument("--host", help="IP/hôte du broker MQTT local cible (ex. 192.168.1.12)")
    parser.add_argument("--ssid", help="SSID WiFi actuel de l'appareil (redemandé par le firmware)")
    parser.add_argument("--password-file", help="Fichier contenant le mot de passe WiFi (une ligne)")
    parser.add_argument("--execute", action="store_true", help="Écrit réellement sur l'appareil (sinon dry-run)")
    parser.add_argument("--yes", action="store_true", help="Ne pas demander de confirmation interactive avant --execute")
    parser.add_argument("--verify-only", action="store_true", help="Ne rien écrire, juste écouter le broker local")
    parser.add_argument("--verify-seconds", type=float, default=30.0)
    parser.add_argument("--product-key", help="product_key de l'équipement (config transport), pour --verify-only")
    parser.add_argument("--device-id", help="device_id de l'équipement (config transport), pour --verify-only")
    args = parser.parse_args()

    if args.verify_only:
        if not args.product_key or not args.device_id:
            parser.error("--verify-only requiert --product-key et --device-id")
        asyncio.run(verify_local_broker(args.product_key, args.device_id, args.verify_seconds))
        return 0

    if not args.address or not args.host or not args.ssid or not args.password_file:
        parser.error("--address, --host, --ssid et --password-file sont requis pour (dry-run ou) --execute")

    password = Path(args.password_file).read_text().strip()
    if not password:
        parser.error(f"{args.password_file} est vide")

    token_cmd = build_token_command(args.host, args.ssid, password)
    station_cmd = build_station_command()

    print("Commandes qui seront envoyées :")
    print("  1.", json.dumps(redact(token_cmd)))
    print("  2.", json.dumps(station_cmd))

    if not args.execute:
        print("\nDry-run (aucune connexion BLE). Relancer avec --execute pour écrire réellement.")
        return 0

    if not args.yes:
        reply = input(
            f"\nConfirmer l'écriture réelle sur l'appareil {args.address} "
            f"(bascule MQTT vers {args.host} + réaffirmation WiFi {args.ssid}) ? [y/N] "
        )
        if reply.strip().lower() != "y":
            print("Annulé.")
            return 1

    asyncio.run(send_commands(args.address, token_cmd, station_cmd))
    print(f"\nTerminé le {time.strftime('%Y-%m-%d %H:%M:%S')}. Lancer --verify-only pour confirmer.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
