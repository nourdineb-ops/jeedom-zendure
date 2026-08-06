# Plugin Jeedom — Zendure

Pilotage direct d'une batterie **Zendure Hyper 2000** depuis [Jeedom](https://www.jeedom.com/),
sans passer par Home Assistant. Objectif principal : **zéro-injection** (ne jamais
injecter dans le réseau public), avec une boucle de régulation temps réel au plus
près du matériel.

## Fonctionnalités

- **Anti-injection en temps réel** : régule en continu la limite de sortie de la
  batterie pour absorber juste ce qu'il faut, jamais plus, à partir de la mesure
  d'une pince/compteur externe.
- **Stratégie de charge nocturne** : cible de charge calculée à partir de la
  consommation habituelle du foyer et de la prévision solaire du lendemain (modèle
  en kWh réels), avec repli automatique sur une logique à seuils fixes si
  l'historique est insuffisant.
- **Calcul de gain (€)** au jour le jour, compatible tarifs Base / HP-HC / Tempo.
- **Trois dashboards** : une tuile compacte ("Condensé"), un diagramme de flux
  animé ("Flux") avec curseurs de pilotage directs, et un digest du jour
  ("Résumé", gain/dépense/SOC). Pour l'historique en courbes, la page Analyse
  native de Jeedom s'en charge très bien, pas de widget dédié pour ça.
- **Mode simulation** intégré : découvrez ou testez le comportement de la
  régulation sans aucun appareil ni broker MQTT — un scénario synthétique de
  consommation/production solaire pilote exactement la même boucle qu'en réel.
- Aucune valeur de comportement en dur : tout se configure depuis l'IHM Jeedom
  (voir [Configuration](#configuration--configuration-over-code-)).

## Compatibilité

- **Modèle d'appareil** : **Hyper 2000** validé en conditions réelles. Hub 1200,
  Hub 2000, AIO 2400, SuperBase V4600/V6400 ont un profil de pilotage
  (`resources/zendure_daemon/device_profiles/`) dérivé du code source de
  l'intégration Home Assistant `Zendure/Zendure-HA`, mais **jamais testés contre
  un appareil réel** — à considérer comme un point de départ, pas une garantie.
  ACE1500 et la famille SolarFlow (800/1600/2400/4000) ne sont pas supportés
  (mécanisme de pilotage trop différent).
- **Tarifs Heures Pleines/Creuses et Tempo** : spécificités du marché français
  (EDF/Linky) — nécessitent une source externe (ex. plugin Téléinfo, RTE Tempo).
  Le contrat **Base** fonctionne partout sans rien de plus.
- **Prévision solaire** (stratégie nuit) : nécessite un plugin externe type
  Solcast. Sans lui, la stratégie nuit retombe sur une logique dégradée à seuils
  fixes.

Détail complet de chaque réglage : [`docs/documentation_utilisateur.md`](docs/documentation_utilisateur.md).

## Installation

1. Installer le plugin dans Jeedom (market, ou dépôt Git depuis Réglages →
   Plugins → Gérer les plugins → Ajouter depuis une archive/un dépôt).
2. Depuis la page du plugin, cliquer sur "Installer les dépendances" — crée un
   environnement Python isolé (`resources/venv/`) et installe les paquets du
   démon (`resources/zendure_daemon/requirements.txt`), sans rien toucher au
   Python système.
3. Créer un équipement Zendure, renseigner `device_id`/`product_key` (voir
   [Compatibilité](docs/documentation_utilisateur.md#compatibilité--prérequis)
   pour savoir où les trouver), et choisir le mode de connexion Cloud.
4. Configurer au minimum une source "Puissance prélevée sur le réseau" (onglet
   Sources, groupe Pilotage) — idéalement une pince/compteur dédié, plus fiable
   qu'un Téléinfo pour mesurer l'injection réelle. C'est l'entrée de la boucle
   anti-injection ; sans elle, le pilotage retombe sur la télémétrie interne
   Zendure, moins précise.

Pas d'appareil sous la main, ou juste envie de voir comment ça se comporte ?
Choisissez le mode de connexion **Simulation** à l'étape 3 : aucun matériel ni
identifiant requis.

## Architecture en un coup d'œil

```
pince (Zigbee/Zwave) --> listener PHP --> socket local --> démon Python
                                                              |
                                                    décision anti-injection
                                                              |
                                                       MQTT (cloud Zendure)
                                                              |
                                                       Zendure Hyper 2000
```

- **Boucle rapide** (démon Python, `resources/zendure_daemon/`) : régulation
  continue de la limite de sortie via le mécanisme d'automation embarqué de
  l'appareil (jamais d'écriture flash) — c'est la garantie zéro-injection.
- **Boucle lente** (cron Jeedom quotidien) : stratégie économique (SOC cible
  nocturne selon le tarif du lendemain, prévision solaire).
- **Transport** : trois modes configurables par équipement (`mode_connexion`),
  cf. `resources/zendure_daemon/transport/` :
  - **Cloud** : broker MQTT cloud Zendure + identifiants de session. Mode de
    référence, seul validé contre du matériel réel à ce jour.
  - **Simulation** : aucun réseau, un scénario synthétique conso/PV pilote la
    même boucle anti-injection/dashboard/calcul de gain.
  - **Local** (mode local via un broker Mosquitto sur site) : code présent mais
    **non fonctionnel actuellement** — voir
    [`docs/brief_chemin_b_local.md`](docs/brief_chemin_b_local.md) pour l'historique
    complet des tentatives avant d'y retoucher.

## Configuration (« configuration over code »)

Aucune valeur de comportement, endpoint ou seuil n'est en dur dans le code — tout
descend de la config Jeedom, sur trois étages :

1. **Transport** (par équipement) : mode cloud/simulation, credentials.
2. **Sources** (par équipement) : chaque donnée d'entrée (puissance réseau, tarif,
   prévision solaire...) est un sélecteur de commande Jeedom, jamais un ID en dur.
3. **Comportement** (par équipement) : marge anti-injection, cooldown, limites W,
   tarifs, seuils de jauge.

Le démon relit sa config à chaud sur signal (pas de redémarrage nécessaire pour
changer un seuil). Détail onglet par onglet : [`docs/documentation_utilisateur.md`](docs/documentation_utilisateur.md).

## Structure du dépôt

```
plugin_info/info.json          métadonnées plugin
core/class/zendure.class.php   eqLogic + cmd, listener pince, dashboards
core/ajax/zendure.ajax.php     sélecteur de commande (cmdList)
core/php/callback.php          canal retour démon -> Jeedom (télémétrie)
core/template/dashboard/       templates dashboard (Condensé, Flux)
desktop/php/config.php         config plugin globale (défauts)
desktop/php/zendure.php        config par équipement (3 étages)
resources/zendure_daemon/      démon Python (transport, régulation, socket, callback)
resources/install.sh           venv + pip install (appelé par Jeedom)
docs/documentation_utilisateur.md   documentation utilisateur complète
docs/brief_*.md                 notes de conception techniques (historique)
```

## Limitations connues

- Mode de connexion local (broker MQTT sur site) non fonctionnel — voir
  [`docs/brief_chemin_b_local.md`](docs/brief_chemin_b_local.md).
- Seul le Hyper 2000 est validé contre un appareil réel — les autres profils
  (Hub 1200/2000, AIO 2400, SuperBase V4600/V6400) n'ont jamais été testés.
  ACE1500 et la famille SolarFlow ne sont pas supportés du tout.
- Multi-équipement géré pour des installations indépendantes ; deux Zendure
  partageant la même mesure réseau ne se répartissent pas la correction
  anti-injection entre eux.

## Remerciements / Sources

Le protocole MQTT/BLE Zendure (topics, payload `deviceAutomation`, différences
entre modèles) n'étant pas documenté publiquement par Zendure, ce plugin s'appuie
sur la lecture du code source de projets communautaires qui l'ont déjà
reverse-engineered :

- **[`Zendure/Zendure-HA`](https://github.com/Zendure/Zendure-HA)** (licence MIT,
  © peteS-UK et contributeurs) — source principale : structure des topics MQTT,
  forme exacte du payload `deviceAutomation` par modèle (`custom_components/
  zendure_ha/devices/`), facteur d'échelle x10 sur `minSoc`/`socSet`, réglages de
  connexion MQTT cloud. Les profils d'appareil de ce plugin
  (`resources/zendure_daemon/device_profiles/`) sont directement dérivés de cette
  intégration.
- [`iobroker.zendure-solarflow`](https://npmjs.com/package/iobroker.zendure-solarflow) (Nograx)
- [`reinhard-brandstaedter/solarflow-control`](https://github.com/reinhard-brandstaedter/solarflow-control)
- [`Zendure/developer-device-data-report`](https://github.com/Zendure/developer-device-data-report)
- `doc.jeedom.com/fr_FR/dev/` — doc développeur plugin Jeedom

Ce plugin a été développé avec l'assistance d'une intelligence artificielle.
