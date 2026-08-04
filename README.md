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
- **Deux dashboards** : une tuile compacte ("Condensé") et un diagramme de flux
  animé ("Flux") avec curseurs de pilotage directs.
- **Mode simulation** intégré : découvrez ou testez le comportement de la
  régulation sans aucun appareil ni broker MQTT — un scénario synthétique de
  consommation/production solaire pilote exactement la même boucle qu'en réel.
- Aucune valeur de comportement en dur : tout se configure depuis l'IHM Jeedom
  (voir [Configuration](#configuration--configuration-over-code)).

## Compatibilité

- **Modèle d'appareil** : seul le **Hyper 2000** est supporté à ce jour (le
  mécanisme de pilotage diffère réellement d'un modèle Zendure à l'autre).
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
4. Configurer au minimum une source "PAPP réseau" (onglet Sources) — c'est
   l'entrée de la boucle anti-injection, sans elle le pilotage automatique ne
   peut pas fonctionner.

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
2. **Sources** (par équipement) : chaque donnée d'entrée (pince, PAPP, tarif,
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

- Dashboard "Historique" pas encore implémenté (retombe sur l'affichage
  générique Jeedom, sans plantage).
- Mode de connexion local (broker MQTT sur site) non fonctionnel — voir
  [`docs/brief_chemin_b_local.md`](docs/brief_chemin_b_local.md).
- Un seul modèle d'appareil supporté (Hyper 2000).
- Multi-équipement géré pour des installations indépendantes ; deux Zendure
  partageant la même mesure réseau ne se répartissent pas la correction
  anti-injection entre eux.

## Références

- `iobroker.zendure-solarflow` (Nograx) — npmjs.com/package/iobroker.zendure-solarflow
- `reinhard-brandstaedter/solarflow-control` (GitHub)
- `Zendure/developer-device-data-report` (GitHub)
- `Zendure/Zendure-HA` — intégration Home Assistant officielle (référence pour le
  protocole MQTT/BLE)
- `doc.jeedom.com/fr_FR/dev/` — doc développeur plugin Jeedom
