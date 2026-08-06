# Documentation utilisateur — plugin Zendure

Pilotage direct d'une batterie **Zendure Hyper 2000** depuis Jeedom, sans passer par
Home Assistant, avec une boucle de régulation rapide qui évite d'injecter dans le
réseau public (anti-injection).

## Compatibilité / prérequis

- **Modèle d'appareil** : **Hyper 2000** validé en conditions réelles. Hub 1200,
  Hub 2000, AIO 2400, SuperBase V4600/V6400 ont un profil de pilotage dérivé du
  code source de l'intégration Home Assistant `Zendure/Zendure-HA`, mais jamais
  testés contre un appareil réel — voir l'onglet Équipement pour le détail.
  ACE1500 et la famille SolarFlow (800/1600/2400/4000) ne sont pas supportés :
  leur mécanisme de pilotage diffère trop (nécessite un Hub Zendure physique ou
  un protocole entièrement différent).
- **Identifiants appareil** (`device_id`/`product_key`) : identifiants internes
  Zendure de votre Hyper 2000, pas le numéro de série visible sur l'étiquette. Ils
  ne sont pas exposés par l'app officielle — la façon la plus fiable de les
  récupérer est de sniffer les infos d'une intégration existante qui pilote déjà
  l'appareil (ex. Home Assistant : fichier `.storage/zendure_ha.storage`, champs
  `productKey`/`deviceKey`).
- **Tarifs (onglet Comportement)** : le contrat **Base** fonctionne partout sans
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
  - **Cloud** : le démon se connecte au broker MQTT cloud de Zendure. Seul mode
    fonctionnel avec un vrai appareil à ce jour (latence télémétrie ~90s côté
    cloud communautaire).
  - **Simulation** : aucun appareil ni broker requis. Un scénario synthétique de
    consommation/production solaire, généré par le démon, pilote la même boucle
    anti-injection et le même dashboard qu'en conditions réelles — utile pour
    découvrir le plugin ou tester un réglage sans matériel. La capacité batterie
    utilisée par le scénario reprend le champ "Capacité batterie" (onglet
    Comportement, section Stratégie nuit) ; 5 kWh par défaut si ce champ est vide.
  - Le mode **Local** (Chemin B, broker MQTT sur site) n'apparaît plus dans ce
    menu — abandonné après plusieurs tentatives sans succès, voir
    `docs/brief_chemin_b_local.md`. Le code transport reste en place si une
    nouvelle piste le rend viable un jour.
- **Identifiant appareil (device_id) / Product key** : voir "Compatibilité" ci-dessus.
- **Modèle d'appareil** : Hyper 2000 (validé), Hub 1200/2000, AIO 2400, SuperBase
  V4600/V6400 (profils dérivés du code source Home Assistant, jamais testés
  contre un appareil réel — voir "Compatibilité" en tête de doc).
- **Identifiants Cloud** (visibles seulement en mode Cloud) : le nom
  d'utilisateur/mot de passe MQTT ne sont **pas** le compte de l'app Zendure — ce
  sont des identifiants de session MQTT (obtenus par ex. en récupérant ceux
  utilisés par une intégration Home Assistant existante). Le champ "Client ID MQTT"
  est optionnel : à laisser vide sauf besoin de diagnostic, certains brokers
  limitent la réception de télémétrie à un clientId précis lié au compte.

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

| Source | Valeur par défaut si vide |
|---|---|
| Pince ampèremétrique (intensité) | Aucune — la jauge d'intensité du dashboard reste à 0. |
| Imax abonnement | Champ "Imax (A)" de l'onglet Comportement ; 30A si ce champ est lui aussi vide. |

### Option tarifaire

Un sélecteur **Type de contrat** (Base / Heures Pleines-Creuses / Tempo) — le
même réglage que celui de l'onglet Comportement, les deux restent synchronisés —
détermine quelles sources apparaissent :

| Type de contrat | Sources affichées | Valeur par défaut si vide |
|---|---|---|
| Base | Aucune | — |
| Heures Pleines / Heures Creuses | Période tarifaire (PTEC / HP-HC) | Aucune |
| Tempo | Tempo — période courante, couleur du jour, couleur de demain (J+1) | Aucune |

Utilisées pour le calcul du gain (€) et la stratégie de charge nocturne (charger
davantage si demain est en jour Tempo Rouge).

### Coût

| Source | Valeur par défaut si vide |
|---|---|
| Dépense jour (€) | Calculée en interne à partir des tarifs configurés (onglet Comportement) et de la télémétrie. |
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
  vers ce boîtier — utile pour cohabiter avec un autre pilote du même compte cloud
  (ex. Home Assistant) : deux clients connectés simultanément avec les mêmes
  identifiants se coupent mutuellement la session.
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
- **Imax (A) / Réseau (mono/tri) / Seuils jauge ambre/rouge** : alimentent
  uniquement la jauge d'intensité du dashboard, aucun impact sur la régulation.
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

### Tarifs

Ces prix alimentent le calcul du gain/dépense (€). Toujours éditables à la main :
la mise à jour auto (si activée) les écrase une fois par mois depuis une source
externe qui couvre Base/HP-HC/Tempo (marché français), avec un bémol de fiabilité
assumé. En cas d'échec (réseau, format inattendu), les prix existants ne sont
jamais effacés ni écrasés par une valeur invalide : la saisie manuelle reste le
filet de sécurité.

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
  - **Historique** : pas encore implémenté — en le sélectionnant, l'équipement
    retombe sur l'affichage générique Jeedom (liste de commandes), sans plantage
    mais sans le rendu visuel prévu à terme.
- **Animations** : désactivez si vous préférez un rendu statique (utile sur
  mobile/tablette).
- **Panneau debug** (widget Flux) : ajoute un bandeau repliable en bas du widget
  avec les dernières lignes des logs démon + plugin, actualisées automatiquement.
  Pensez à le désactiver une fois le diagnostic terminé (appels réseau
  périodiques tant qu'il est ouvert).

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
  cas de conflit de port sur la machine.
- **Défauts Chemin A (Cloud)** : hôte/port du broker cloud repris par tout
  nouvel équipement.
- **Tarifs (€/kWh) communs** : prix HP/HC et Tempo Bleu/Blanc/Rouge par défaut —
  spécificités du marché français, voir "Compatibilité" en tête de doc.
- **Défauts anti-injection** : marge et cooldown repris par tout nouvel
  équipement.
- **Défauts fréquence de mise à jour** : intervalle minimum et tolérance de
  bruit repris par tout nouvel équipement.
