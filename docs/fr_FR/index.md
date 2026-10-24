# Plugin Zendure pour Jeedom

Pilotage direct d'une batterie **Zendure Hyper 2000** depuis Jeedom, sans passer par
Home Assistant, avec une boucle de régulation rapide qui évite d'injecter dans le
réseau public (anti-injection).

> **Statut : bêta.** Développé et validé sur un seul Hyper 2000, en mode Cloud.
> Les autres modèles ne sont pas testés (voir « Limites connues »).

## Installation

1. Installez le plugin depuis le Market (ou depuis un dépôt Git), puis activez-le.
2. Sur la page du plugin, cliquez sur **Installer les dépendances** : un
   environnement Python isolé est créé dans `resources/venv/` (aucun impact sur le
   Python du système). Il nécessite `python3-venv`, installé automatiquement sur
   Debian/Ubuntu si absent.
3. Démarrez le **démon** (il est unique et partagé par tous les équipements Zendure).
4. Créez un équipement Zendure. Pour essayer sans appareil, choisissez le mode de
   connexion **Simulation**. Pour un vrai appareil, choisissez **Cloud** et suivez
   « Identifiants appareil et Cloud » ci-dessous.
5. Onglet **Sources** : renseignez au minimum « Puissance prélevée sur le réseau ».

## Compatibilité / prérequis

- **Modèle d'appareil** : **Hyper 2000** validé en conditions réelles. Hub 1200,
  Hub 2000, AIO 2400, SuperBase V4600/V6400 ont un profil de pilotage dérivé du
  code source de l'intégration Home Assistant `Zendure/Zendure-HA`, mais jamais
  testés contre un appareil réel — voir l'onglet Équipement pour le détail.
  ACE1500 et la famille SolarFlow (800/1600/2400/4000) ne sont pas supportés :
  leur mécanisme de pilotage diffère trop (nécessite un Hub Zendure physique ou
  un protocole entièrement différent).
- **Identifiants appareil et Cloud** (`device_id`/`product_key`/identifiants MQTT) :
  onglet Équipement, bloc "Récupération assistée" — collez le **Token ZendureApp**
  (appli mobile Zendure → réglages du compte **principal**, pas un compte invité →
  option "token"/"développeur"/"intégration") et cliquez sur "Récupérer via Token" :
  tous ces champs se remplissent automatiquement. Aucune installation Home
  Assistant requise : ce bouton reproduit l'appel que fait l'intégration
  Home Assistant officielle `Zendure/Zendure-HA` auprès de l'API Zendure. Si plusieurs appareils sont liés au compte, un sélecteur
  apparaît pour choisir lequel appliquer à cet équipement.
  **Avertissement :** l'API Zendure n'est pas documentée publiquement. Le plugin
  s'y présente comme l'intégration Home Assistant, ce qui peut cesser de fonctionner
  sans préavis si Zendure modifie son service.
- **Tarifs (onglet Sources)** : le contrat **Base** fonctionne partout sans
  rien de plus. Les contrats **Heures Pleines/Creuses** et **Tempo** sont des
  spécificités du marché français (EDF/Linky) — ils nécessitent en plus une source
  de données tarifaires externe (ex. plugin Téléinfo, RTE Tempo) branchée via
  l'onglet Sources pour être exploités automatiquement.
- **Prévision solaire** (stratégie nuit) : nécessite un plugin externe qui expose
  une prévision en Wh (ex. Solcast), branché via l'onglet Sources. Sans lui, la
  stratégie nuit retombe sur une logique à seuils fixes plus grossière.

## Onglet Équipement (fusionné avec Transport)

- **Nom** : libre, sert juste à identifier l'équipement dans Jeedom.
- **Objet parent** : la pièce où se trouve physiquement la batterie (ex.
  Extérieur, Garage). Purement organisationnel.
- **Activé** : démarre réellement le pilotage (connexion + boucle anti-injection).
  Désactivé = l'équipement existe mais ne fait rien.
- **Visible** : affiche le dashboard sur la page d'accueil / la vue de l'objet.
- **Catégorie** : cosmétique (icône/filtre Jeedom), Énergie coché par défaut.
- **Multi-équipement** : un eqLogic Zendure = un Hyper 2000. Créer un 2e eqLogic
  pour un 2e appareil ne demande aucun code supplémentaire (connexion, télémétrie,
  crons, tout est déjà isolé par équipement). Prévu pour des installations
  indépendantes (transport/sources/tarifs propres à chacune) ; faire pointer 2
  équipements sur la même mesure réseau (même maison, 2 Zendure) n'est pas géré —
  l'anti-injection de chaque équipement calculerait sa cible sur l'écart réseau
  complet, sans se répartir la correction.

### Connexion (transport)

- **Mode de connexion** :
  - **Cloud** : le démon se connecte au broker MQTT cloud de Zendure. C'est le seul
    mode validé avec un vrai appareil.
  - **Simulation** : aucun appareil ni broker requis. Un scénario synthétique de
    consommation/production solaire, généré par le démon, pilote la même boucle
    anti-injection et le même dashboard qu'en conditions réelles — utile pour
    découvrir le plugin ou tester un réglage sans matériel. La capacité batterie
    utilisée par le scénario reprend le champ "Capacité batterie" (onglet
    Comportement, section Stratégie nuit) ; 5 kWh par défaut si ce champ est vide.
- **Identifiant appareil (device_id) / Product key** : voir "Compatibilité" ci-dessus.
- **Modèle d'appareil** : Hyper 2000 (validé), Hub 1200/2000, AIO 2400, SuperBase
  V4600/V6400 (profils dérivés du code source Home Assistant, jamais testés
  contre un appareil réel — voir "Compatibilité" en tête de doc).
- **Identifiants Cloud** (visibles seulement en mode Cloud) : le nom
  d'utilisateur/mot de passe MQTT ne sont **pas** le compte de l'app Zendure — ce
  sont des identifiants de session MQTT, normalement remplis automatiquement par
  le bouton "Récupérer via Token" (voir "Compatibilité" en tête de doc). Le champ
  "Client ID MQTT" est optionnel : à laisser vide sauf besoin de diagnostic,
  certains brokers limitent la réception de télémétrie à un clientId précis lié
  au compte.
- **TLS** / **TLS sans vérification du certificat** : le port 8883 du cloud Zendure
  répond en TLS avec un certificat auto-signé générique. Pour l'utiliser, cochez
  les deux cases et renseignez le port 8883. Par défaut (port 1883, sans TLS), rien
  à cocher : c'est la configuration qui a toujours fonctionné.

## Onglet Sources

Chaque source pointe vers une commande "info" **existante ailleurs dans Jeedom**
(pince, téléinfo, Tempo, prévision solaire...) via le sélecteur natif Jeedom —
jamais un identifiant écrit en dur dans la configuration. Toutes doivent déjà
exister comme commandes "info" ailleurs dans Jeedom (téléinfo, RTE Tempo,
prévision solaire...) — ce plugin ne les crée pas, il les référence. Exception :
les commandes de télémétrie brute Zendure, elles, sont créées automatiquement par
ce plugin lui-même (une par valeur, au premier signalement, visible dans l'onglet
Commandes) — si une clé brute vous semble plus fiable qu'une valeur curée pour un
usage donné (ex. `outputHomePower`), vous pouvez la sélectionner ici aussi.

Regroupées par usage :

### Pilotage

La source réseau accepte une commande Jeedom, ou une **formule** combinant
plusieurs commandes, par exemple `#[Maison][Pince][Puissance]#` ou une expression
comme `#[A][Etat][Mode]# == "producing" ? -1 * #[A][Pince][Valeur]# : #[A][Pince][Valeur]#`.
Utile avec une pince qui ne remonte que la valeur absolue de la puissance et un
second indicateur donnant le sens. Convention : positif = import, négatif = injection.

| Source | Valeur par défaut si vide |
|---|---|
| Puissance prélevée sur le réseau (W) | Commande curée `grid_power` (télémétrie Zendure). **Entrée principale de la boucle anti-injection rapide.** Idéalement une pince/compteur dédié plutôt qu'un Téléinfo : le PAPP Téléinfo est une puissance apparente mono-quadrant, qui ne distingue pas bien l'injection réelle — une pince à mesure bidirectionnelle est un meilleur capteur pour cet usage. Sans source configurée, le pilotage retombe sur la télémétrie interne Zendure, moins fiable pour ça. |

**Avancé** (laisser vide sauf besoin spécifique — le Zendure connaît déjà sa
propre injection et sa propre production solaire) :

| Source | Valeur par défaut si vide |
|---|---|
| Injection maison (Zendure) | Commande curée `injected_power`. |
| Production solaire (dashboard) | Commande curée `solar_power`. |

### Prévision solaire

| Source | Rôle |
|---|---|
| Prévision solaire J+0 (Wh) | Utilisée par la stratégie nuit pour moduler le SOC cible. |
| Prévision solaire J+1 (Wh) | **Pas redondante avec J+0** : sert de repli automatique tant que J+0 n'a pas encore été recollectée aujourd'hui (typique avant le rafraîchissement matinal d'une source comme Solcast, souvent après le cron de minuit) — dans ce cas J+1 correspond alors au bon jour calendaire. |

Aucune valeur par défaut si vide — la stratégie nuit retombe sur une logique à
seuils fixes plus grossière (cf. onglet Comportement, section Stratégie nuit).

### Compteur

Alimentent l'affichage jauge (intensité) du dashboard.

| Source | Valeur par défaut si vide |
|---|---|
| Intensité instantanée | Aucune — la jauge d'intensité du dashboard reste à 0. |
| Imax abonnement | Champ "Imax (A)" de l'onglet IHM (section Jauge d'intensité) ; 30A si ce champ est lui aussi vide. |

### Option tarifaire

Un sélecteur **Type de contrat** (Base / Heures Pleines-Creuses / Tempo)
détermine à la fois quels **prix** (€/kWh) et quelles **sources** apparaissent :

| Type de contrat | Prix affichés | Sources affichées | Valeur par défaut si vide |
|---|---|---|---|
| Base | Prix du kWh | Aucune | — |
| Heures Pleines / Heures Creuses | Prix HC, Prix HP | Période tarifaire (PTEC / HP-HC) | Aucune |
| Tempo | Bleu/Blanc/Rouge — HC/HP | Tempo — période courante, couleur du jour, couleur de demain (J+1) | Aucune |

Ces prix alimentent le calcul du gain (€) et la stratégie de charge nocturne
(charger davantage si demain est en jour Tempo Rouge). Toujours éditables à la
main : la case **Mise à jour auto (mensuelle)**, si cochée, les écrase une fois
par mois depuis une source externe qui couvre Base/HP-HC/Tempo (marché
français), avec un bémol de fiabilité assumé. En cas d'échec (réseau, format
inattendu), les prix existants ne sont jamais effacés ni écrasés par une valeur
invalide : la saisie manuelle reste le filet de sécurité.

### Coût

| Source | Valeur par défaut si vide |
|---|---|
| Dépense jour (€) | Calculée en interne à partir des tarifs configurés (ci-dessus, section Option tarifaire) et de la télémétrie. |
| Dépense veille (€) | Idem. |

Exemple pour ces deux champs avec Teleinfo : `STAT_TODAY_INDEX00_COUT` /
`STAT_YESTERDAY_INDEX00_COUT`.

## Onglet Comportement

### Logique de la boucle anti-injection

```
target = clamp(0, limite_max, grid_power + injected_power - marge)
```
recalculé en absolu à chaque mesure de la pince, jamais depuis une valeur
mémorisée. Convention : `grid_power > 0` = import réseau (normal), `< 0` =
injection (à éviter).

Deux sens, deux cadences : côté injection (`grid < marge`), réactivité maximale —
cooldown court, pas de zone morte, bypass total en cas d'injection avérée. Côté
import (`grid >= marge`), cadence volontairement plus lente (cooldown import) +
zone morte en % autour de la dernière valeur envoyée, pour laisser l'appareil se
stabiliser entre deux corrections et ne pas re-déclencher une commande pour une
variation négligeable.

Cette boucle rapide ne joue que sur la **décharge** (plancher 0W) : elle ne
bascule jamais en charge — la charge programmée reste une décision distincte
(stratégie nuit HC, hors périmètre de cette boucle).

- **Connexion active** : décochez pour couper complètement la connexion du démon
  vers ce boîtier. À faire si un autre pilote utilise le même compte cloud (par
  exemple Home Assistant) : deux clients connectés avec les mêmes identifiants se
  coupent mutuellement la session.
- **Anti-injection active** : décochez pour couper la boucle rapide ET le cron HP
  (mais pas la connexion elle-même) — le plugin continue de recevoir la
  télémétrie et d'afficher le dashboard, mais ne commande plus jamais la limite de
  sortie.
- **Marge anti-injection (W)** : objectif de puissance importée du réseau à
  maintenir (jamais tout à 0, pour absorber les variations entre deux mesures de
  la pince). Défaut 30W.
- **Cooldown (s)** : délai minimum entre deux commandes côté injection. Défaut 2s.
- **Cooldown import (s)** : délai minimum entre deux commandes côté import.
  Défaut 15s.
- **Tolérance import (%)** : ignore une correction côté import si la nouvelle
  cible reste à +/- X% de la dernière valeur commandée. Défaut 10%.
- **Limites sortie min/max (W)** / **Limite entrée max (W)** : bornes physiques de
  la limite de sortie/entrée envoyée à la batterie (ex. 0 à 1200W pour un
  Hyper 2000).
- **Cron HP en simulation** : reproduit la branche périodique du scénario (toutes
  les minutes) mais se contente de logger ce qu'il ferait, sans jamais toucher à
  l'appareil tant que cette case est cochée.

### Fréquence de mise à jour

- **Intervalle minimum (s)** : le démon ne pousse une valeur de télémétrie vers
  Jeedom que si elle a changé depuis le dernier envoi, sauf si ce délai est
  dépassé (heartbeat). N'affecte pas l'anti-injection elle-même (qui reste temps
  réel). Défaut 300s.
- **Tolérance de bruit** : un écart numérique en dessous de cette valeur n'est pas
  considéré comme un changement (ex. 3 = les puissances qui frémissent de
  quelques W en permanence ne déclenchent plus un envoi à chaque trame).
- **Capture télémétrie complète (1h)** : désactive temporairement ce filtre — tout
  est poussé sans filtrage pendant 1h (diagnostic), puis le filtrage normal
  reprend automatiquement.

### Secours Bluetooth (BLE)

Désactivé par défaut. Quand la télémétrie MQTT/cloud devient muette (WiFi du
boîtier instable) et que cette option est cochée, le démon tente une lecture
ponctuelle en direct par Bluetooth (adresse MAC à trouver via l'app Zendure ou un
scan BLE) — **lecture seule**, aucune commande n'est jamais envoyée par ce canal.
Cadencé sur le cron HP (5 min), pas une connexion permanente : volontairement
occasionnel pour ne pas monopoliser un adaptateur Bluetooth déjà utilisé par
ailleurs. Ne se déclenche de toute façon que si la télémétrie est déjà confirmée
muette, jamais en fonctionnement normal.

### Ping réseau local

Désactivé par défaut. Renseignez l'adresse IP de l'appareil (une **réservation DHCP**
sur votre box ou routeur est conseillée pour qu'elle ne change pas) et cochez
« Ping local actif ». Le démon envoie un ping (ICMP) toutes les minutes et crée deux
commandes : `lan_ping_online` et `lan_ping_ms`. C'est un diagnostic passif, il ne
pilote rien : il permet de distinguer une coupure WiFi totale de l'appareil
(plus de ping) d'un simple problème de liaison MQTT/cloud (ping OK, télémétrie
muette). Le message d'alerte s'adapte en conséquence.

### Alertes mail / push

- **Alerte mail/push active** : interrupteur général des alertes de ce plugin pour
  l'équipement. Les événements restent visibles dans les logs même si la case est
  décochée.
- **Délai avant alerte (s)** : défaut 300 s. Une coupure qui se résout avant ce délai
  ne génère aucune notification.

Les alertes passent par le centre de messages Jeedom : pour recevoir un mail ou un
push, configurez l'action sur message dans Réglages → Système → Configuration →
Logs → Messages. Alertes émises : télémétrie muette puis rétablie, reconnexions MQTT
en rafale, ping local hors ligne, démon injoignable, redémarrage forcé.

### Redémarrage forcé (dernier recours)

Désactivé par défaut. Si la télémétrie reste muette bien après l'alerte, le plugin
peut couper puis rallumer l'appareil via une **prise commandable** (Zigbee, Z-Wave,
etc.) que vous désignez : commande « Éteindre » et commande « Allumer » (sélecteur
de commande Jeedom).

- **Coupure avant relance (s)** : défaut 5 s.
- **Délai avant déclenchement (s)** : défaut 600 s, toujours relevé automatiquement
  au-dessus du délai d'alerte + 60 s.
- **Anti-répétition (s)** : défaut 3600 s, pour ne pas couper l'appareil en boucle
  si le problème est matériel.

Attention : l'appareil perd son alimentation secteur pendant la coupure. La
batterie interne le maintient en partie, il peut donc ne pas redémarrer sur une
coupure brève ; ajustez la durée si besoin. Chaque déclenchement est tracé dans les
logs, le centre de messages et la commande `power_cycle_last_at`.

### Stratégie nuit

Cron à minuit : bascule l'appareil en charge et fixe un SOC cible selon la
couleur Tempo de demain et la prévision solaire du jour à venir (si ces sources
sont configurées, sinon la logique retombe sur un mode dégradé — voir
"Compatibilité" en tête de doc).

- **Tempo Rouge demain** → toujours charge à 100% (le tarif Rouge HP est assez
  élevé pour préférer se couvrir plutôt que parier sur la prévision solaire).
- **Sinon**, deux logiques possibles :
  1. **Capacité batterie (kWh) renseignée** → modèle en kWh réels : un électron
     solaire ne coûte rien, un électron HC stocké la nuit a un coût, donc on ne
     charge que ce que le solaire de demain ne couvrira pas. Cible = (consommation
     typique du foyer sur la fenêtre HP réelle, médiane glissante 7 jours
     d'historique) moins la prévision solaire du lendemain, jamais négatif, ramené
     en % de la capacité (mini 20%, jamais plus de 100%). Sans tarif HP/HC
     configuré (contrat Base), la fenêtre couvre toute la journée (0h-24h) faute
     de créneau "cher" identifiable — le plugin reste utilisable sans cet
     abonnement. Retombe automatiquement sur la logique (2) tant que l'historique
     est insuffisant (installation récente).
  2. **Capacité batterie vide, ou historique pas encore assez profond** →
     ancienne logique à seuils fixes (Tempo Bleu + prévision solaire ≥ 4 kWh →
     60% ; sinon 80%).
- **Puissance de charge nuit** (par défaut 1200W, la limite AC du Hyper 2000) :
  cible envoyée à l'automation de charge de l'appareil — sans cette commande
  explicite, l'appareil ne charge jamais réellement.
- **Stratégie nuit en simulation** : logue la décision sans jamais toucher à
  l'appareil tant que cette case est cochée.
- **Fin de la charge nuit** (défaut 06:00) : repli utilisé quand aucun tarif
  HP/HC/Tempo n'est configuré, pour déterminer quand repasser en décharge.
- **Retour HC le soir** (défaut 22:00) : ferme la fenêtre HP côté modèle kWh
  uniquement.

## Onglet IHM

- **Dashboard** :
  - **Condensé** : tuile compacte (anneau SOC, 4 flux, jauge intensité, gain €).
  - **Flux** : losange animé Solaire/Réseau/Maison/Batterie, jauge d'intensité,
    Tempo J/J+1, indicateurs financiers et curseurs de pilotage (limite de sortie
    AC, SOC minimum).
  - **Résumé** : digest du jour en gros chiffres (gain aujourd'hui/veille,
    répartition solaire/batterie, dépense jour/veille, SOC) — pas des courbes.
    Pour ça, la page **Analyse** native de Jeedom (Outils → Analyse →
    Historique) fait déjà bien mieux : superposition de plusieurs courbes,
    groupement par période, comparaison, et même une formule calculée
    combinant plusieurs commandes — sans aucun code à écrire. Choisissez par
    exemple `solar_power`, `grid_power`, `injected_power` et `soc` pour
    visualiser une journée complète.
- **Animations** : désactivez si vous préférez un rendu statique (utile sur
  mobile/tablette).
- **Panneau debug** (widget Flux) : ajoute un bandeau repliable en bas du widget
  avec les dernières lignes des logs démon + plugin, actualisées automatiquement.
  Pensez à le désactiver une fois le diagnostic terminé (appels réseau
  périodiques tant qu'il est ouvert).
- **Jauge d'intensité** — Imax (A) / Seuils jauge ambre-rouge (%) : alimentent
  uniquement la jauge d'intensité du dashboard, aucun impact sur la régulation
  elle-même (cf. onglet Comportement pour la régulation). Imax (A) est
  **facultatif** : utilisé seulement si la source "Imax abonnement" (onglet
  Sources) n'est pas renseignée.

Un aperçu statique de chaque dashboard est visible directement dans cet onglet du
formulaire de configuration.

## Onglet Commandes

Liste toutes les commandes de cet équipement : les "curées" (utilisées par le
plugin, ex. `solar_power`) et celles créées automatiquement par le démon à partir
de la télémétrie brute Zendure (ex. `outputHomePower`, `packData0_socLevel`...).
Table standard Jeedom (identique aux autres plugins) : Afficher/Historiser/type/
etc. directement éditables par commande.

## Configuration globale du plugin

Accessible depuis Réglages → Plugins → Zendure → Configuration. Définit les
valeurs par défaut reprises par chaque nouvel équipement (repli en cascade :
valeur de l'eqLogic → défaut global ici → défaut codé en dur) :

- **Port du socket local** (PHP → démon) : 55071 par défaut, à ne changer qu'en
  cas de conflit de port sur la machine ; redémarrez le démon après modification.
- **Défauts connexion Cloud** : hôte/port du broker cloud repris par tout
  nouvel équipement.
- **Tarifs (€/kWh) communs** : prix HP/HC et Tempo Bleu/Blanc/Rouge par défaut —
  spécificités du marché français, voir "Compatibilité" en tête de doc.
- **Défauts anti-injection** : marge et cooldown repris par tout nouvel
  équipement.
- **Défauts fréquence de mise à jour** : intervalle minimum et tolérance de
  bruit repris par tout nouvel équipement.

## Journaux et dépannage

- **Logs** : `zendure` (plugin) et `zendure_daemon` (démon), dans Analyse → Logs.
  Passez le niveau en Debug pour un diagnostic détaillé. Le panneau debug du widget
  Flux (onglet IHM) affiche les dernières lignes sans quitter le dashboard.
- **Journal d'incidents** : les avertissements et erreurs du démon sont aussi
  conservés dans `data/zendure/incidents.log` (dossier `data` de Jeedom, inclus dans
  les sauvegardes, avec rotation automatique).
- **« Transport connecté » = oui mais plus de données** : cet indicateur ne reflète
  que la liaison entre le démon et le broker, pas l'état de l'appareil. Vérifiez la
  fraîcheur de la télémétrie ; activez le ping local pour savoir si l'appareil est
  encore sur le réseau.
- **Commandes « abandonnée après N tentatives sans accusé de réception »** : un
  accusé manquant occasionnel est normal (liaison MQTT intermittente). À surveiller
  seulement si cela dure ou s'accompagne d'alertes de télémétrie muette.
- **Appareil qui ne remonte plus rien pendant des heures** : des blocages ont été
  observés sur l'Hyper 2000, sans cause identifiée côté plugin (probablement le
  firmware ou le WiFi de l'appareil). L'alerte, le secours BLE et le redémarrage
  forcé par prise sont là pour cela. Vérifiez aussi la stabilité de votre WiFi
  (une boucle de reconnexion « Lock to AP » a été observée avec des bornes UniFi).
- **Callbacks en erreur HTTP 500** dans le log du démon : Jeedom lui-même est
  indisponible (base de données, PHP). Le plugin n'est pas en cause.
- **Sauvegarde Jeedom** : pendant la sauvegarde nocturne, le démon peut signaler
  quelques délais dépassés (« ReadTimeout »). C'est sans conséquence.

## Limites connues

- Seul l'**Hyper 2000** est validé sur matériel réel. Les profils Hub 1200/2000,
  AIO 2400 et SuperBase V4600/V6400 viennent du code d'une intégration
  communautaire et n'ont jamais été testés ; ACE1500 et la famille SolarFlow ne
  sont pas supportés.
- Le pilotage passe uniquement par le **cloud Zendure** : il dépend de la
  disponibilité de ce service et de son API non documentée.
- Deux Zendure partageant la même mesure réseau ne se répartissent pas la
  correction anti-injection.
- Les tarifs Heures Pleines/Creuses et Tempo, ainsi que la mise à jour automatique
  des prix, concernent le marché français.
- Interface disponible en français uniquement.

## Crédits

Le protocole Zendure n'étant pas documenté publiquement, ce plugin s'appuie sur le
travail de la communauté, notamment `Zendure/Zendure-HA` (licence MIT),
`iobroker.zendure-solarflow`, `solarflow-control` et `epicRE/zendure_ble`. Ce
plugin n'est ni affilié ni approuvé par Zendure.
