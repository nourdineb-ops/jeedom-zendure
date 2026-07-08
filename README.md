# Plugin Jeedom — Zendure

Pilotage direct d'une batterie **Zendure Hyper 2000** depuis Jeedom, sans passer par
Home Assistant. Objectif produit : zéro-injection (ne jamais injecter dans
l'arrivée maison), avec une boucle de régulation rapide au plus près du matériel.

> Statut : squelette initial (v0.1.0-dev), non testé sur une installation Jeedom
> réelle. Voir [Points ouverts](#points-ouverts-à-confirmer-avant-de-figer).

## Architecture en un coup d'œil

```
pince (Zigbee/Zwave) --> listener PHP --> socket local --> démon Python
                                                              |
                                                    décision anti-injection
                                                              |
                                                   MQTT (Cloud A ou Local B)
                                                              |
                                                       Zendure Hyper 2000
```

- **Boucle rapide** (démon Python, `resources/zendure_daemon/`) : régulation continue
  de la limite de sortie via `setDeviceAutomationInOutLimit` (jamais d'écriture flash).
  C'est la garantie zéro-injection.
- **Boucle lente** (scénario Jeedom ou config quotidienne) : stratégie économique
  (SOC cible nocturne selon Tempo J+1, prévision solaire). Hors périmètre de ce plugin
  pour l'instant.
- **Transport** : deux modes configurables par eqLogic (`mode_connexion`), même code
  MQTT (`transport/mqtt_transport.py`), seuls les paramètres de connexion changent :
  - **Cloud (A)** : broker `mqtt-eu.zen-iot.com:1883` + Clé Cloud d'Autorisation.
    Simple, mais latence télémétrie ~90s côté cloud communautaire — **incompatible
    avec un zéro-injection strict**.
  - **Local (B)** — recommandé v1 : Mosquitto local + relais DNS + reconfiguration
    Bluetooth de l'appareil. Voir procédure ci-dessous.

## Procédure Chemin B (mode local)

1. Installer un broker Mosquitto local (port 1883, ou 8883 en TLS), **authentification
   désactivée** (l'appareil a un mot de passe codé en dur côté firmware, non
   configurable).
2. Rediriger la résolution DNS de `mq.zen-iot.com` (ou nom d'hôte configuré sur
   l'appareil) vers l'IP du Mosquitto local — via le DNS du réseau local ou
   `/etc/hosts` sur un relais dédié.
3. Reconfigurer l'URL MQTT interne du Hyper 2000 via un outil Bluetooth
   (Solarflow Bluetooth Manager ou Zendure Cloud Disconnector) pour qu'il se
   connecte à cette adresse.
4. Renseigner l'IP/port du Mosquitto local dans la config de l'eqLogic Jeedom
   (`mode_connexion = local`).

Ce chemin est plus invasif et plus fragile qu'un usage cloud standard (dépend d'un
firmware non documenté officiellement) : à faire en connaissance de cause.

## Configuration (« configuration over code »)

Aucune valeur de comportement, endpoint ou seuil n'est en dur dans le code — tout
descend de la config Jeedom, sur trois étages :

1. **Transport** (par eqLogic) : mode cloud/local, credentials, IP/port.
2. **Sources** (par eqLogic) : chaque donnée d'entrée (pince, PAPP, Tempo, prévision
   solaire...) est un sélecteur de commande Jeedom, jamais un ID en dur.
3. **Comportement** (par eqLogic) : marge anti-injection, cooldown, hystérésis,
   limites W, tarifs, seuils de jauge.

Le démon relit sa config à chaud sur signal (pas de redémarrage nécessaire pour
changer un seuil).

## Parité de commandes (migration Home Assistant)

| Rôle | Ancien ID (HA) | Nouvelle commande plugin |
|---|---|---|
| Mode input/output | `#26879#` | action `set_mode` |
| Limite sortie W | `#26781#` | action `set_output_limit` |
| SOC cible | `#26784#` | action `set_soc_min` |
| Prévision kWh | `#26882#` | info `forecast_today_kwh` |
| Injecté W | `#26768#` | info `injected_power` |
| PAPP | `#26868#` | source `src_grid_papp` (Étage 2) |
| Tempo now / J+1 / J | `#17848#` / `#15453#` / `#15452#` | sources `src_tempo_now` / `src_tempo_j1` / `src_tempo_j` |

## Structure du plugin

```
plugin_info/info.json          métadonnées plugin
core/class/zendure.class.php   eqLogic + cmd, listener pince, dashboard "Condensé"
core/ajax/zendure.ajax.php     sélecteur de commande (cmdList)
core/php/callback.php          canal retour démon -> Jeedom (télémétrie)
core/template/dashboard/       templates dashboard (condense livré ; flux/historique à venir)
desktop/php/config.php         config plugin globale (défauts)
desktop/php/zendure.php        config eqLogic (3 étages)
resources/zendure_daemon/      démon Python (transport, régulation, socket, callback)
resources/install.sh           venv + pip install
```

## Points ouverts (à confirmer avant de figer)

- Structure réelle des topics/payload MQTT du Hyper 2000 — voir références ci-dessous.
  `mqtt_transport.py` a des templates de topic **configurables** en attendant.
- Signature exacte de la classe `listener` du core Jeedom (utilisée dans
  `zendure.class.php::registerGridPowerListener`) — à valider contre
  `doc.jeedom.com/fr_FR/dev` ou le code source du core au premier déploiement.
- Convention exacte des templates de dashboard custom (`core/template/dashboard/`) —
  implémenté ici via `eqLogic::toHtml()` surchargé (extension point standard et sûr),
  à confirmer si une convention native de fichiers existe et serait préférable.
- Intervalle de reporting réel de la pince Zigbee/Zwave (borne le zéro-injection).
- Mono/triphasé : mono par défaut, à confirmer sur l'installation réelle.
- Formule de gain Zendure : reprise de l'ancien scénario en v1, pas de réinvention.

## Références à lire

- `iobroker.zendure-solarflow` (Nograx) — npmjs.com/package/iobroker.zendure-solarflow
- `reinhard-brandstaedter/solarflow-control` (GitHub)
- `Zendure/developer-device-data-report` (GitHub)
- `doc.jeedom.com/fr_FR/dev/` — doc développeur plugin Jeedom

## Déploiement

Développement sur ce dépôt Git local (Mac), déploiement vers la VM Jeedom (production)
via un script rsync (à ajouter, ex. `scripts/deploy.sh`).
