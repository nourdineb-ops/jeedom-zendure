"""Prototype d'exploration BLE (Chemin B alternatif au WiFi/MQTT, cf. échange
utilisateur du 2026-07-22 sur l'instabilité WiFi du boîtier) -- PAS un Transport
de production, volontairement autonome et hors de zendure_daemon.py :

- Protocole confirmé en direct contre ce Hyper 2000 (scan + connexion BLE
  réussis sans pairing) et documenté par la communauté (`epicRE/zendure_ble`,
  vérifié : mêmes UUID de service/caractéristiques que trouvées en réel) :
  service 0000A002, écriture 0000C304, notifications 0000C305, JSON quasi
  identique au protocole MQTT déjà géré par ce plugin (méthodes report/read/
  write/getInfo, mêmes clés `properties`).
- Ce script sert à valider PORTÉE et FIABILITÉ dans les conditions réelles de
  cette installation, PAS encore à piloter l'appareil pour de vrai : la doc
  communautaire documentée montre les écritures de réglages (ex. outputLimit)
  faites via `properties/write` classique -- le même mécanisme qu'on évite
  déjà en MQTT car il écrit en flash (brief §5, cf. function/invoke
  deviceAutomation utilisé à la place pour la boucle rapide). Tant qu'un
  équivalent BLE de ce mécanisme "sans flash" n'est pas confirmé, ce script
  ne fait que LIRE la télémétrie -- pas question d'y brancher la boucle
  anti-injection (qui écrirait potentiellement en flash toutes les 1-2s).

Usage : resources/venv/bin/python3 resources/zendure_daemon/ble_probe.py
        --address AA:BB:CC:DD:EE:FF [--duration 120]
"""

import argparse
import asyncio
import json
import time
from datetime import datetime, timezone

from bleak import BleakClient, BleakScanner

SERVICE_UUID = "0000a002-0000-1000-8000-00805f9b34fb"
CHAR_WRITE = "0000c304-0000-1000-8000-00805f9b34fb"
CHAR_NOTIFY = "0000c305-0000-1000-8000-00805f9b34fb"



def now_str() -> str:
    return datetime.now(timezone.utc).astimezone().strftime("%H:%M:%S.%f")[:-3]


class BleProbe:
    def __init__(self, address: str):
        self.address = address
        self.client: BleakClient | None = None
        self._rx_buffer = b""
        self._handshake_done = asyncio.Event()
        self.frames_received = 0
        self.disconnects = 0
        self.last_values: dict = {}
        self.pack_serials: list[str] = []

    def _on_disconnect(self, _client: BleakClient) -> None:
        self.disconnects += 1
        print(f"[{now_str()}] DÉCONNECTÉ (occurrence #{self.disconnects})")

    def _on_notify(self, _char, data: bytearray) -> None:
        self._rx_buffer += bytes(data)
        # Les messages Zendure sont des objets JSON complets, potentiellement
        # fragmentés sur plusieurs notifications ATT si > MTU -- on accumule et
        # on tente un décodage incrémental (json.JSONDecoder.raw_decode gère
        # plusieurs objets concaténés sans délimiteur explicite, pas observé
        # de \n ni de préfixe de longueur dans la doc communautaire).
        decoder = json.JSONDecoder()
        text = self._rx_buffer.decode("utf-8", errors="ignore")
        idx = 0
        consumed_bytes = 0
        while idx < len(text):
            stripped = text[idx:].lstrip()
            if not stripped:
                break
            skip = len(text[idx:]) - len(stripped)
            try:
                obj, end = decoder.raw_decode(stripped)
            except json.JSONDecodeError:
                break  # message incomplet, on attend la suite
            idx += skip + end
            consumed_bytes = len(text[:idx].encode("utf-8"))
            self._handle_message(obj)
        self._rx_buffer = self._rx_buffer[consumed_bytes:]

    def _handle_message(self, obj: dict) -> None:
        self.frames_received += 1
        method = obj.get("method")
        if method == "BLESPP":
            print(f"[{now_str()}] Handshake BLESPP reçu -> envoi BLESPP_OK")
            asyncio.create_task(self._send_blespp_ok())
            return
        if method == "getInfo-rsp":
            print(f"[{now_str()}] getInfo-rsp : deviceSn={obj.get('deviceSn')} firmwares={obj.get('firmwares')}")
            return
        if method == "report":
            props = obj.get("properties") or {}
            self.last_values.update(props)
            for pack in obj.get("packData") or []:
                sn = pack.get("sn")
                if sn and sn not in self.pack_serials:
                    self.pack_serials.append(sn)
            if props:
                print(f"[{now_str()}] report {props}")
            return
        if method in ("read_reply", "write_reply"):
            print(f"[{now_str()}] {method} success={obj.get('success')} {obj.get('properties')}")
            return
        if method == "error":
            print(f"[{now_str()}] ERREUR device: {obj}")
            return
        print(f"[{now_str()}] (non géré) {obj}")

    async def _write_json(self, payload: dict) -> None:
        data = json.dumps(payload).encode("utf-8")
        await self.client.write_gatt_char(CHAR_WRITE, data, response=False)

    async def _send_blespp_ok(self) -> None:
        await self._write_json({"messageId": str(int(time.time())), "method": "BLESPP_OK"})
        self._handshake_done.set()

    async def _send_get_info(self) -> None:
        await self._write_json({
            "messageId": str(int(time.time() * 1000)),
            "method": "getInfo",
            "timestamp": int(time.time()),
        })

    async def _send_get_all(self) -> None:
        await self._write_json({
            "messageId": str(int(time.time() * 1000)),
            "deviceId": "",
            "timestamp": int(time.time()),
            "properties": ["getAll"],
            "method": "read",
        })

    async def run(self, duration_s: float) -> None:
        print(f"[{now_str()}] Scan/connexion vers {self.address}...")
        t_connect_start = time.monotonic()
        self.client = BleakClient(self.address, disconnected_callback=self._on_disconnect)
        await self.client.connect(timeout=20.0)
        connect_elapsed = time.monotonic() - t_connect_start
        print(f"[{now_str()}] Connecté en {connect_elapsed:.1f}s (RSSI non exposé post-connexion par bleak)")

        await self.client.start_notify(CHAR_NOTIFY, self._on_notify)

        # Le device envoie BLESPP spontanément après souscription -- on laisse
        # une fenêtre courte avant de forcer la suite si rien n'arrive (défensif,
        # cf. commentaire module : pas garanti que ce soit systématique).
        try:
            await asyncio.wait_for(self._handshake_done.wait(), timeout=5.0)
        except asyncio.TimeoutError:
            print(f"[{now_str()}] Pas de BLESPP reçu sous 5s, on tente directement getInfo/read")

        await self._send_get_info()
        await asyncio.sleep(1.0)
        await self._send_get_all()

        t_start = time.monotonic()
        last_report = t_start
        while time.monotonic() - t_start < duration_s:
            await asyncio.sleep(1.0)
            if time.monotonic() - last_report >= 15.0:
                last_report = time.monotonic()
                connected = self.client.is_connected
                print(
                    f"[{now_str()}] --- statut : connected={connected} "
                    f"frames={self.frames_received} disconnects={self.disconnects} ---"
                )

        await self.client.stop_notify(CHAR_NOTIFY)
        await self.client.disconnect()

        print("\n=== Résumé du test BLE ===")
        print(f"Adresse         : {self.address}")
        print(f"Durée de test   : {duration_s:.0f}s (connexion établie en {connect_elapsed:.1f}s)")
        print(f"Trames reçues   : {self.frames_received}")
        print(f"Déconnexions    : {self.disconnects}")
        print(f"N° série packs  : {self.pack_serials or '(aucun reçu)'}")
        print(f"Dernières valeurs connues : {json.dumps(self.last_values, indent=2, ensure_ascii=False)}")


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--address", required=True, help="Adresse MAC BLE du boîtier (scan préalable ou app Zendure)")
    parser.add_argument("--duration", type=float, default=120.0, help="Durée du test en secondes")
    args = parser.parse_args()

    probe = BleProbe(args.address)
    try:
        asyncio.run(probe.run(args.duration))
    except KeyboardInterrupt:
        pass


if __name__ == "__main__":
    main()
