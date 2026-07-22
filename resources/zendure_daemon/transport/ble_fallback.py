"""Secours BLE local, optionnel (config `ble_failover_active` + `ble_address`,
cf. desktop/php/zendure.php) : quand la télémétrie MQTT/cloud devient muette
(WiFi du boîtier instable, cf. échange utilisateur 2026-07-22 -- voir aussi
device.py::TELEMETRY_STALE_S), on tente une lecture ponctuelle en direct par
Bluetooth plutôt que de rester aveugle.

Volontairement PAS une connexion permanente : un second consommateur de
`hci0` (TheengsGateway, scan passif pour les thermomètres BLE existants,
cf. échange utilisateur) tourne déjà sur cette VM. Une connexion GATT
occasionnelle et bornée dans le temps, calée sur la cadence du cron HP
(5 min, cf. zendure_daemon.py::BLE_FAILOVER_INTERVAL_S), minimise la
contention sur le radio -- pas de connexion permanente qui monopoliserait
le temps d'écoute du scan passif.

Protocole confirmé en direct contre un Hyper 2000 le 2026-07-22 (cf.
ble_probe.py, qui réutilise ce module) et documenté par la communauté
(`epicRE/zendure_ble`) : mêmes UUID GATT et même format JSON que le
protocole MQTT déjà géré par ce plugin (telemetry_map.translate_properties()
sait donc directement interpréter ce qu'on récupère ici).

Ne fait AUCUNE écriture : lecture seule tant que le mécanisme d'écriture
sans flash n'est pas confirmé côté BLE (cf. commentaire ble_probe.py) --
un secours qui rendrait la télémétrie visible pendant une panne WiFi n'a
pas besoin d'écrire quoi que ce soit sur l'appareil.
"""

import asyncio
import json
import time
from typing import Optional

from bleak import BleakClient

SERVICE_UUID = "0000a002-0000-1000-8000-00805f9b34fb"
CHAR_WRITE = "0000c304-0000-1000-8000-00805f9b34fb"
CHAR_NOTIFY = "0000c305-0000-1000-8000-00805f9b34fb"


class BleSnapshotFetcher:
    """Une session = une connexion BLE bornée dans le temps, du handshake à la
    déconnexion. Accumule toutes les propriétés vues dans les trames "report"
    reçues (getAll en déclenche plusieurs, cf. doc epicRE/zendure_ble) plutôt
    que de s'arrêter à la première -- sinon la plupart des clés manqueraient."""

    def __init__(self, address: str):
        self.address = address
        self.client: Optional[BleakClient] = None
        self._rx_buffer = b""
        self._handshake_done = asyncio.Event()
        self.properties: dict = {}
        self.pack_data: list = []

    def _on_notify(self, _char, data: bytearray) -> None:
        self._rx_buffer += bytes(data)
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
                break
            idx += skip + end
            consumed_bytes = len(text[:idx].encode("utf-8"))
            self._handle_message(obj)
        self._rx_buffer = self._rx_buffer[consumed_bytes:]

    def _handle_message(self, obj: dict) -> None:
        method = obj.get("method")
        if method == "BLESPP":
            asyncio.create_task(self._send_blespp_ok())
            return
        if method == "report":
            self.properties.update(obj.get("properties") or {})
            for pack in obj.get("packData") or []:
                self.pack_data.append(pack)

    async def _write_json(self, payload: dict) -> None:
        data = json.dumps(payload).encode("utf-8")
        await self.client.write_gatt_char(CHAR_WRITE, data, response=False)

    async def _send_blespp_ok(self) -> None:
        await self._write_json({"messageId": str(int(time.time())), "method": "BLESPP_OK"})
        self._handshake_done.set()

    async def _send_get_all(self) -> None:
        await self._write_json({
            "messageId": str(int(time.time() * 1000)),
            "timestamp": int(time.time()),
            "properties": ["getAll"],
            "method": "read",
        })

    async def fetch(self, timeout_s: float, settle_s: float = 3.0) -> None:
        """Se connecte, déclenche un getAll, laisse `settle_s` secondes pour
        que les trames "report" arrivent, puis se déconnecte -- borné par
        `timeout_s` au total (connexion incluse) pour ne jamais monopoliser
        le radio plus que prévu si le device traîne à répondre."""
        self.client = BleakClient(self.address)
        await asyncio.wait_for(self.client.connect(), timeout=timeout_s)
        try:
            await self.client.start_notify(CHAR_NOTIFY, self._on_notify)
            try:
                await asyncio.wait_for(self._handshake_done.wait(), timeout=5.0)
            except asyncio.TimeoutError:
                pass  # certains firmwares n'envoient pas BLESPP -- on tente quand même
            await self._send_get_all()
            await asyncio.sleep(settle_s)
        finally:
            try:
                await self.client.disconnect()
            except Exception:
                pass


async def fetch_ble_snapshot(address: str, timeout_s: float = 20.0) -> tuple[dict, list]:
    fetcher = BleSnapshotFetcher(address)
    await fetcher.fetch(timeout_s)
    return fetcher.properties, fetcher.pack_data
