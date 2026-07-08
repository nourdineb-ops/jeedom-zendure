"""Chargement de la config du démon depuis un fichier JSON écrit par PHP.

Principe "configuration over code" (brief §11) : le démon ne connaît aucune valeur
de comportement en dur, il relit ce fichier au démarrage et à chaud sur SIGHUP
(voir zendure_daemon.py). PHP réécrit ce fichier à chaque sauvegarde de config
plugin/eqLogic (config::byKey / getConfiguration), avant d'envoyer le signal.

Format attendu (un objet par eqLogic Zendure configuré) :
{
  "equipments": [
    {
      "eq_id": 12,
      "device_id": "...",
      "product_key": "...",
      "mode_connexion": "local",
      "local_host": "192.168.1.50",
      "local_port": 1883,
      "anti_injection": {"marge_w": 30, "cooldown_s": 2, "hysteresis_w": 15, ...},
      "loop_period_s": 1.0
    }
  ],
  "jeedom": {"callback_url": "...", "apikey": "...", "socketport": 55071}
}
"""

import json
import logging
from pathlib import Path
from typing import Any, Dict

log = logging.getLogger("zendure.config")


def load_config(path: str) -> Dict[str, Any]:
    p = Path(path)
    with p.open("r", encoding="utf-8") as f:
        config = json.load(f)
    log.info("Config chargée depuis %s (%d équipement(s))", path, len(config.get("equipments", [])))
    return config
