# Brief — Chemin B (MQTT local), tentatives et constat (2026-07-28/29)

Document de passation sur l'état réel du Chemin B (pilotage local, zéro
latence cloud) après plusieurs tentatives concrètes en conditions réelles.
**Conclusion : abandonné pour l'instant**, pas de mécanisme fiable trouvé
pour faire basculer l'appareil vers un broker MQTT local. Ne pas re-tenter
les mêmes approches sans lire ce document d'abord.

## Ce qui est prêt et fonctionne (infrastructure, pas le mécanisme)

- **Broker MQTT local** : Mosquitto tourne déjà sur la VM Jeedom (installé
  par le plugin jMQTT), port 1883, `allow_anonymous true` — exactement ce
  qu'il faut (l'appareil a un mot de passe codé en dur, pas d'auth
  configurable). Testé en direct (pub/sub anonyme via deux clients paho
  distincts) : fonctionne. Rien à installer.
- **Code du démon déjà complet pour `mode_connexion=local`**
  (`transport/factory.py`) : mêmes templates de topics que le cloud, même
  mécanisme de client_id. `local_host`/`local_port` déjà renseignés sur
  l'eqLogic réel (`192.168.1.100:1883`) — il ne manque que le device qui se
  connecte réellement dessus.
- **Le mécanisme BLE de bascule existe et est documenté** : intégration
  officielle `Zendure/Zendure-HA` (`custom_components/zendure_ha/device.py::bleMqtt()`),
  écriture GATT sur la caractéristique `0000c304-0000-1000-8000-00805f9b34fb`
  (même caractéristique que nos lectures BLE déjà confirmées, cf.
  `ble_fallback.py`). Deux commandes JSON :
  ```json
  {"iotUrl": "<broker>", "messageId": 1002, "method": "token",
   "password": "<wifi>", "ssid": "<ssid>", "timeZone": "GMT+01:00", "token": "abcdefgh"}
  {"messageId": 1003, "method": "station"}
  ```
  Implémenté dans `resources/zendure_daemon/tools/ble_switch_local_mqtt.py`
  (dry-run par défaut, confirmation avant écriture réelle, mode
  `--verify-only` pour observer le broker local après coup).

## Découverte utile en cours de route : le BLE ne s'active que si le WiFi est coupé

Confirmé en direct à plusieurs reprises : l'appareil n'annonce/n'est
détectable en BLE **que lorsque son WiFi est indisponible** (coupé côté
routeur, ex. blocage MAC sur une Unifi). Avec un WiFi fonctionnel, aucun
scan (VM ou Raspberry Pi dédié) ne le voit, quelle que soit la durée. Ça
recoupe un rapport communautaire non confirmé (`Zendure/Zendure-HA#951`,
sur un SolarFlow 2400 AC : *"blocking Wi-Fi seems to enable Bluetooth"*) —
mais chez nous c'est vérifié empiriquement, pas juste un rapport tiers.
**Implication pratique** : toute manipulation BLE de cet appareil nécessite
de couper son WiFi au préalable (routeur), le reconnecter ensuite pour
qu'il puisse effectivement joindre un broker (local ou cloud).

## Trois tentatives de bascule, trois échecs, deux hypothèses éliminées

| # | Date | Radio BLE | RSSI | Résultat |
|---|------|-----------|------|----------|
| 1 | 2026-07-28 | VM (onboard) | -68 dBm | Écriture acceptée sans erreur, mais aucune connexion sur le broker local observée ; l'appareil est resté ~20 min sans MQTT du tout (ni cloud ni local) avant de retrouver le cloud tout seul après activation de "HEMS" côté app -- lien de cause à effet jamais établi |
| 2 | 2026-07-29 | VM (onboard) | -68 dBm | Même écriture, même résultat : zéro connexion locale, cloud repris tout seul en quelques minutes |
| 3 | 2026-07-29 | Raspberry Pi Zero 2W, posé à côté de l'appareil | **-38 dBm** (signal fort) | Même résultat exact malgré un signal nettement meilleur |

**Hypothèses éliminées par ces tests** :
- ~~Portée/qualité du signal BLE~~ — éliminée par la tentative #3 (signal fort, même échec).
- ~~Payload trop gros pour la limite ~244 octets~~ — le payload token/station
  fait 129-150 octets selon le SSID, largement sous la limite ; ce n'est
  pas un problème de taille comme pour le test de pilotage (`deviceAutomation`,
  258 octets, rejeté par le transport lui-même -- cf. section suivante,
  problème différent).

**Conclusion** : le protocole `token`/`station` tel que reconstruit à
partir du code source de `Zendure-HA` (et confirmé identique dans
`solarflow-bt-manager`, autre projet communautaire) est soit incomplet
(il manque une étape/un champ qu'aucun des deux projets open-source ne
documente), soit spécifique à une version de firmware différente de celle
de cet appareil. Écriture BLE acceptée sans erreur ≠ effet réel sur la
config stockée -- même constat que pour toutes les autres écritures BLE
tentées sur ce projet depuis le début (cf. [[project_ble_exploration]],
docs/brief_strategie_charge.md section BLE).

## Pilotage direct par BLE (indépendamment de la bascule locale) : non plus

Testé séparément (2026-07-29, WiFi coupé, donc BLE actif) : une commande
`deviceAutomation` (décharge, `autoModelProgram=2`, même payload que
`mqtt_transport.py::_publish_automation()`) envoyée en BLE.
- Payload complet (258 octets) → **rejeté au niveau transport**
  (`org.bluez.Error.Failed: Failed to initiate write`), cohérent avec la
  limite ~244 octets déjà documentée.
- Payload réduit au minimum (183 octets, juste `outPower`) → accepté sans
  erreur, mais **aucun effet observé** (`outputHomePower` inchangé,
  variations dans le bruit normal de mesure).
- Recherché dans le code source de `Zendure-HA` et de `solarflow-bt-manager` :
  **aucun des deux ne pilote jamais l'appareil en écriture BLE directe**
  pour charge/décharge -- seulement lecture + le mécanisme `token`/`station`
  ci-dessus. Le manuel officiel affirme que l'appli mobile peut "contrôler"
  l'appareil en BLE quand le WiFi est coupé, mais rien ne prouve que ce
  soit un canal d'écriture BLE direct plutôt que le même mécanisme
  "bascule vers un broker local, puis pilotage MQTT normal sur ce broker" --
  ce qui ramène exactement au problème ci-dessus (la bascule qui ne prend
  pas), pas une voie de contournement.

## Pour aller plus loin (non fait, effort différent)

Les deux pistes testées (BLE direct, redirection via reconfiguration BLE)
sont épuisées avec les moyens de reverse-engineering à disposition (lire du
code source tiers). Étape suivante si on veut vraiment percer ça : capturer
le trafic BLE réel de l'appli officielle Zendure pendant qu'elle bascule un
appareil en local (sniff HCI depuis un téléphone rooté/jailbreaké, ou un
adaptateur BLE en mode moniteur à proximité) -- un chantier de nature
différente, pas juste "encore un essai" du protocole déjà reconstruit.

**Piste DNS seule (sans BLE)** : jamais testée sur ce projet, et aucune
confirmation trouvée dans la communauté qu'elle suffise seule (le souvenir
de l'utilisateur de "pilotage local réussi" recoupe très probablement les
mêmes outils BLE testés ici, pas une redirection DNS indépendante -- les
fils de discussion qui la mentionnent la présentent comme théorique/non
validée). Resterait à essayer si jamais reprise : rediriger
`mqtteu.zen-iot.com` vers le broker local via le DNS Unifi, sans toucher au
BLE, et observer si l'appareil (déjà configuré cloud, jamais reprovisionné)
tente quand même de s'y connecter tel quel.

## Décision actuelle

Chemin B abandonné pour l'instant. Le plugin reste sur Cloud (A) + secours
BLE en lecture seule (fiable, déjà en place) + les corrections de
robustesse du 2026-07-28/29 (bascule sur 0 quand la télémétrie Zendure est
périmée, cf. `project_night_charge_kwh_model` et le commit
"Corrige la zone morte du cron..."). Ne pas reprendre le Chemin B sans une
nouvelle piste concrète (sniff HCI, ou contact direct Zendure).
