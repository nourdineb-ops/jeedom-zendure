# Brief de cadrage — Plugin Zendure pour Jeedom

*Document de handoff pour démarrer la session de développement (Claude Code). Issu de la phase de cadrage.*

---

## 1. Objectif

Développer un plugin Jeedom pilotant un **Zendure Hyper 2000**, pour **supprimer la dépendance à Home Assistant** (actuellement simple passerelle d'échange avec Zendure). Le pilotage vise l'équilibrage de charge batterie/maison, déclenché en événementiel par une pince ampèremétrique sur la sortie EDF (côté Jeedom).

## 2. Contexte matériel / logiciel

- **Appareil** : Zendure Hyper 2000. Pas de menu « contrôle local » dans l'appli — pas de MQTT local natif.
- **HEMS** : non activé (bien — évite les conflits de commandes).
- **Accès actuel** : HA parle très probablement au **broker cloud** Zendure (`mqtt-eu.zen-iot.com`).
- **Jeedom** : VM 8 Go RAM / 4 vCPU. Scénario d'équilibrage en événementiel (pince amp sortie EDF).

## 3. Distinction structurante : « direct » vs « local »

- **Direct** (objectif principal) = supprimer HA. → Faisable proprement (Chemin A).
- **Local** (no internet) = plus fragile, nécessite un contournement (Chemin B).

## 4. Architecture retenue

### Chemin A — Cloud-direct (recommandé pour la v1)
- Plugin Jeedom avec **démon** (Python, indépendant du cœur PHP) qui se connecte au broker cloud `mqtt-eu.zen-iot.com:1883`.
- Auth via **Clé Cloud d'Autorisation** (appli Zendure → Profil → Clé Cloud d'Autorisation). Méthode officielle.
- Supprime HA sans hack matériel. Dépend d'Internet. Rafraîchissement cloud possiblement lent (~90 s en lecture selon source communautaire — **à vérifier sur l'install réelle**).

### Chemin B — Full local (évolution possible)
- Relais DNS : rediriger `mq.zen-iot.com` vers un Mosquitto local, après reconfiguration de l'URL MQTT interne de l'appareil via outil Bluetooth (Solarflow Bluetooth Manager / Zendure Cloud Disconnector).
- Contraintes : port 1883 (ou 8883 SSL), **auth désactivée** sur le broker (mot de passe codé en dur côté appareil).
- Plus rapide, 100 % local, mais plus invasif/fragile.

> **A & B = deux options configurables du plugin** (open source, partageable). Le cœur (télémétrie + commande via `setDeviceAutomationInOutLimit`) est identique ; seule la couche transport MQTT change. Paramètre `mode_connexion` : *Cloud* (broker `mqtt-eu.zen-iot.com:1883` + Clé Cloud d'Autorisation) ou *Local* (Mosquitto, IP saisie). README : documenter le compromis (Cloud = simple/latent ; Local = réactif/invasif) + procédure relais DNS & outils Bluetooth. Abstraire le transport dès le départ (interface commune, 2 implémentations).

## 5. Deux points de conception CRITIQUES

1. **Usure flash** : pour piloter charge/décharge, utiliser le paramètre **`setDeviceAutomationInOutLimit`** — il contrôle l'appareil **sans écrire dans la flash**. Impératif vu la fréquence des commandes de l'équilibrage. Ne PAS utiliser de paramètre qui écrit en flash pour du pilotage récurrent.
2. **Latence vs temps réel** : la boucle pince → décision → commande vers Zendure subit la latence cloud en Chemin A. Si la réactivité requise est de quelques secondes → A suffit. Si quasi-instantané requis → viser B dès la v1. **→ ARBITRAGE À CONFIRMER AVANT DE FIGER.**

## 6. Références à faire lire au démarrage (structure des topics de commande Hyper 2000)

- `iobroker.zendure-solarflow` (Nograx) — npmjs.com/package/iobroker.zendure-solarflow
- `reinhard-brandstaedter/solarflow-control` — github.com (pilotage charge/décharge, bypass, adaptation à la demande)
- `Zendure/developer-device-data-report` — github.com (format JSON des métriques ; confirme « data downlink and device control »)
- Doc développeur plugin Jeedom : doc.jeedom.com/fr_FR/dev/ (structure plugin + démon)

## 7. Structure cible du plugin (rappel)

`plugin_info/info.json` · `core/class/*.class.php` · `core/ajax/` · `core/php/` · `desktop/` (config, panel) · `resources/` (démon Python) · commandes Jeedom info (télémétrie) + action (charge/décharge/limites).

## 8. Où développer

**Claude Code**, sur dépôt Git local (Mac) + script de déploiement (rsync vers la VM Jeedom), Jeedom restant en « production ». Alternative : SSH direct dans la VM si Node.js installable.

## 9. Arbitrage réactivité — TRANCHÉ

**Objectif confirmé : ne jamais injecter dans l'arrivée maison (zéro feed-in).**

Conséquence : le Chemin A (cloud) injecterait à chaque transitoire pendant la fenêtre de latence cloud (retour télémétrie ~90 s). Incompatible avec un « jamais » strict.

→ **Viser le Chemin B (MQTT local via relais DNS) dès la v1.** Prérequis : Mosquitto local + reconfiguration Bluetooth de l'URL MQTT de l'appareil (Solarflow Bluetooth Manager / Zendure Cloud Disconnector), port 1883 (ou 8883 TLS), auth désactivée (mot de passe codé en dur côté appareil).

Le Chemin A ne resterait acceptable que si l'objectif était relâché en « minimiser » l'injection.

**Design cible** : déporter la boucle rapide au plus près du matériel — le démon pousse fréquemment `setDeviceAutomationInOutLimit` en local (pas d'écriture flash). Jeedom garde supervision + stratégie lente.

## 9 bis. Séparation des deux couches de contrôle

- **Couche rapide (anti-injection)** : démon local, boucle courte, régulation de la limite de sortie. Sécurité zéro-injection.
- **Couche lente (décision économique)** : SOC cible nocturne selon couleur Tempo J+1 + prévision solaire (Forecast.Solar / Solcast / Open-Meteo à brancher sur azimut/puissance crête réels). Peut rester en scénario Jeedom ou logique quotidienne du démon.

## 9 ter. Constats sur les scénarios existants (base de reprise)

- Scénario `Zendure` (scenario.txt) : logique HEMS déjà avancée (branche FAST anti-export cooldown 2 s sur `Tableau_GRID`, stratégie nuit SOC/Tempo, optim import HP, estimation gain €). **MAIS logique dupliquée ~250 lignes entre branches ALORS/SINON** — argument fort pour le passage en plugin propre.
- Anti-injection actuel = heuristique par proxy (`injected - (marge - PAPP)`) sur cron `*/5` + événement. À remplacer par une régulation continue dans le démon.
- Bug à nettoyer dans scenario-3 : virgule littérale parasite dans plusieurs `value` (`...[Total from EDF]#,`).
- Commandes Jeedom déjà mappées à réutiliser : mode `#26879#` (input/output), limite sortie W `#26781#`, SOC cible `#26784#`, prévision kWh `#26882#`, injecté W `#26768#`, PAPP `#26868#`, Tempo now `#17848#` / J+1 `#15453#` / J `#15452#`.

## 10. Lien avec l'audit Jeedom (chantier parallèle)

Audit à mener AVANT conversion massive de scénarios en plugins : instrumenter (sysstat/sar, slow query log MySQL, historique scénarios), collecter 5-7 j, analyser (durées/fréquences scénarios, tables `history`/`historyArch`, charge démons). Vérifier en priorité la fréquence réelle des événements de la pince amp.
