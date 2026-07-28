# Brief de cadrage — Stratégie de charge nuit (v2)

Document de passation, écrit pour permettre à un autre outil/session (ex. Codex
Desktop) de reprendre ce chantier sans avoir vécu la session qui a mené à ce
cadrage. Deuxième plus-value forte du plugin après l'anti-injection : décider
intelligemment combien charger la batterie la nuit, plutôt que la logique
actuelle, trop grossière.

## Où ça vit dans le code

- `core/class/zendure.class.php::runStrategieNuit()` — décide le SOC cible de
  charge nocturne, appelée par le cron `cronStrategieNuit` (00h00, PHP,
  Jeedom).
- `core/class/zendure.class.php::runOptimisationHP()` — cron `cronOptimisationHP`
  (toutes les 5 min) : anti-injection (boucle lente) + réveil automatique qui
  repasse l'appareil en décharge + SOC max 100% dès le passage en Heures
  Pleines (ajouté aujourd'hui, cf. section "Ce qui vient d'être corrigé").
- `core/class/zendure.class.php::resolveForecastKwh()` — résout la prévision
  solaire (Solcast) en gérant le décalage de cache. Fiable, ne pas retoucher.
- `core/class/zendure.class.php::isHeuresPleines()` — détecte le passage HP/HC
  via la vraie source tarifaire (PTEC/Tempo), avec repli sur une heure de
  config si aucun tarif HP/HC n'est configuré. Fiable, ne pas retoucher.
- Config équipement : `desktop/php/zendure.php`, onglet Comportement, fieldset
  "Stratégie nuit" (`strategie_nuit_active`, `strategie_nuit_dry_run`,
  `heure_fin_charge_nuit`, `charge_power_nuit_w`) + fieldset "Sources" pour
  `src_prevision_solaire`/`src_prevision_solaire_j1`/`src_tempo_j1`.

## Logique actuelle (à remplacer)

```
Tempo demain = Rouge                          -> SOC cible = 100%
Tempo demain = Bleu ET prévision solaire ≥ 4kWh -> SOC cible = 60%
sinon (cas standard)                           -> SOC cible = 80%
```

Trois seuils fixes, aucune notion de kWh réels, aucune prise en compte de la
capacité de la batterie ni de la consommation réelle du foyer. Reprise telle
quelle d'un ancien scénario Jeedom, jamais repensée depuis.

## Ce qui vient d'être corrigé aujourd'hui (contexte important, ne pas re-régresser)

**Incident réel du 2026-07-22** : injection réseau de 600-700W (jusqu'à
-3000W certains jours) constatée en pleine journée, batterie pleine et
inactive. Analyse de l'historique archivé (table `historyArch`, données
Zendure depuis le 10/07, PAPP/Teleinfo depuis décembre 2025) :

- 13-14 juillet (plafond SOC ~99-100%) : réseau **toujours positif**, même
  batterie à 100%. Aucune injection.
- 16-21 juillet + aujourd'hui (plafond SOC resté bloqué à **80%** depuis un
  test le 13/07, jamais remonté) : injection systématique chaque jour
  ensoleillé dès que la batterie touchait ce plafond.

**Conclusion** : ce n'est pas un comportement de l'appareil qui aurait changé,
c'est mécanique — un plafond bas laisse structurellement plusieurs heures de
surplus solaire non absorbable chaque jour ensoleillé. Corrigé aujourd'hui :
le réveil HP (`runOptimisationHP()`) remonte maintenant le plafond
(`set_soc_max`) à 100% en même temps qu'il repasse l'appareil en décharge —
donc la stratégie nuit peut baisser le plafond la nuit sans risque que ça
reste bloqué bas toute la journée suivante.

**Implication pour ce chantier** : le nouveau modèle décidera d'un plafond
nocturne (probablement < 100% dans certains cas, ex. beaucoup de solaire
prévu) — c'est voulu et sain, tant que le réveil HP continue de tout
remettre à 100% le matin. Ne pas supprimer ce réveil en construisant le
nouveau modèle.

## Ce qui est fiable et réutilisable tel quel

- **Prévision solaire** : Solcast déjà câblé (eqLogic "Vitry", id 3690),
  commandes `d0_total_watt`/`d1_total_watt` (id 28081/28082) en **Wh**.
  `resolveForecastKwh()` gère déjà le décalage de rafraîchissement du cache
  (Solcast se met à jour ~6h, donc un cron à minuit doit parfois lire J+1 au
  lieu de J+0) — logique déjà correcte, bug de sélecteur de source corrigé
  aujourd'hui (pointait sur la mauvaise commande), ne pas re-questionner.
- **Détection HP/HC** : `isHeuresPleines()`, tri-état (true/false/null si pas
  de tarif HP/HC configuré), avec repli sur `heure_fin_charge_nuit`.
- **Consommation maison reconstituable depuis l'historique réel** :
  `house = grid + abs(injected)` (formule déjà utilisée par le widget
  Condensé, `toHtmlCondense()`). PAPP (`src_grid_papp`, cmd id 26868 sur
  l'eqLogic "Energy_info" id 3644, plugin `virtual`) a un historique long
  (depuis décembre 2025, table `historyArch`) — largement de quoi calculer
  des moyennes de consommation réelles plutôt que deviner un chiffre.

## RÉSOLU le 2026-07-28 (MQTT) — historique de l'incertitude ci-dessous

**La commande de charge MQTT a désormais un effet confirmé, sans ambiguïté.**
Première nuit en live avec le modèle kWh (cf. section "État d'avancement") :
cible calculée à 00h00 = 53% (`[cronStrategieNuit] ... -> cible 53%`). SOC
réel passé de 25% (23h55 le 27/07) à 53% pile (01h12 le 28/07), palier stable
ensuite. Télémétrie `socSet` = 530 (×10 -> 53.0%, exactement la cible) et
`packInputPower` retombé à 0W une fois la cible atteinte -- **l'appareil a
chargé jusqu'au plafond envoyé puis s'est arrêté tout seul**, comportement
firmware cohérent avec un vrai plafond de charge respecté. Plus la moindre
ambiguïté sur ce point précis (commande MQTT `set_input_limit` +
`set_soc_max`, mode charge `set_mode`) : ça marche.

Nuance importante : ceci valide le chemin **MQTT**. Le test d'écriture BLE
(`deviceAutomation`, ci-dessous, 2026-07-24) reste, lui, non confirmé -- ne
pas déduire de cette réussite MQTT que les écritures BLE fonctionnent aussi.
Les deux mécanismes sont distincts côté firmware.

## Ce qui est un vrai point d'incertitude — À VALIDER AVANT DE CONSTRUIRE DESSUS (historique, MQTT résolu ci-dessus)

**La commande de charge (`set_input_limit`, automation `autoModelProgram=1`)
n'a montré aucun effet réel lors de deux tests en direct aujourd'hui**, y
compris après avoir corrigé un vrai bug de code (elle envoyait auparavant
`set_output_limit(0)` au lieu d'une vraie commande charge — corrigé, cf. git
log) et après avoir désactivé le "Mode intelligent" de l'appareil (nouveau
bouton ajouté, `set_smart_mode`, désactivé sans effet observable). L'appareil
tourne en permanence en "mode automatique" côté app officielle Zendure, et il
n'est pas exclu que ce mode automatique prenne le pas sur nos commandes
manuelles de charge (hypothèse non confirmée, pas invalidée non plus).

**Mise à jour 2026-07-24** : même symptôme reproduit côté BLE. Un test d'écriture
directe (`deviceAutomation`, payload JSON compact pour tenir sous la limite de
244 octets d'écriture BLE de ce device — sinon rejet silencieux au niveau
transport) a été accepté sans erreur, mais `outputHomePower` n'a pas bougé
d'un watt après coup (vérifié par une seconde lecture BLE juste après,
pendant une vraie coupure WiFi). Donc ce n'est pas spécifique à MQTT/au
mécanisme charge : les commandes manuelles n'ont pas d'effet confirmé sur cet
appareil, quel que soit le transport. Ne pas construire de logique de secours
qui dépend d'une commande d'écriture (MQTT ou BLE) tant que ce point n'est
pas éclairci — le risque est une fausse sécurité (la commande "réussit" sans
rien corriger réellement).

**Implication concrète** : ne pas supposer que "commander X% de charge"
fonctionnera de manière fiable sans re-tester en conditions réelles. Toute
nouvelle logique de charge active devrait :
1. Rester dérrière un flag dry-run par défaut (même pattern que
   `strategie_nuit_dry_run`/`cron_hp_dry_run` existants) tant qu'elle n'est
   pas validée en vrai.
2. Prévoir un moyen simple de vérifier après coup si la charge a réellement
   eu lieu (comparer SOC avant/après, pas juste supposer que la commande a
   été suivie d'effet).

## Ce qui manque pour le modèle "apprentissage" (vision long terme de l'utilisateur)

L'utilisateur veut un modèle inspiré d'un plugin thermostat qui "apprend tout
seul" (saison, température prévue/extérieure), avec deux facteurs identifiés
comme dominants : **chauffage actif ou non**, **charge de véhicule électrique
ou non**. Concrètement absent aujourd'hui de cette install Jeedom :

- **Pas de signal "chauffage actif" unifié.** 8 équipements `thermostat`
  distincts, chacun avec juste une commande `Mode` (info) — pas d'agrégation
  existante en un seul signal booléen.
- **Aucune intégration véhicule électrique** dans ce Jeedom (vérifié la liste
  des plugins installés).

Donc un modèle conditionné sur ces deux facteurs suppose soit de nouvelles
sources de config (sélecteurs "Source chauffage actif"/"Source charge VE",
sur le même principe que les autres sources du plugin — jamais d'ID en dur),
avec repli propre si non configurées, soit un report de cette conditionnalité
à une itération ultérieure.

## État d'avancement (2026-07-27)

**Phase 1 implémentée** (`kwhSocTarget()`/`legacySocTarget()`/
`estimateWindowConsumptionKwh()`/`resolveHpWindow()` dans
`zendure.class.php`, config `batterie_capacite_kwh` +
`heure_debut_hc_soir` sur l'onglet Comportement, fieldset "Stratégie
nuit"). Trois itérations le même jour avant d'arriver au modèle final
(détail dans l'historique git si besoin) :

1. Premier jet : plancher (conso matin 0h-9h) vs plafond (réserve solaire),
   le plancher gagnait toujours en cas de conflit -> réserve solaire
   inopérante dès que la batterie est petite face à la conso (remonté par
   l'utilisateur).
2. Fix intermédiaire : compromis 50/50 plancher/plafond en cas de conflit
   -> résolvait le problème mais de façon insensible à l'ampleur du
   déséquilibre (6.2kWh et 15kWh de prévision solaire donnaient la même
   cible 50%).
3. **Version retenue**, suite à l'échange avec l'utilisateur (principe :
   un électron solaire ne coûte rien, un électron HC stocké la nuit a un
   coût -- donc ne charger que ce que le solaire de demain ne couvrira
   pas, pas raisonner en plancher/plafond séparés) :

   `cible = (conso HP typique du foyer - prévision solaire lendemain)`,
   jamais négatif, ramenée en % de la capacité (mini 20%, jamais > 100%).

   - **Conso "HP typique"**, pas "conso du matin 0h-9h" (erreur de
     modélisation du premier jet) : la conso pendant la fenêtre HC
     elle-même coûte déjà le même prix qu'elle vienne du réseau ou de la
     batterie -- seule la conso pendant la fenêtre HP (réveil réel,
     `resolveMorningWindowEndH()` → retour HC du soir, nouvelle config
     `heure_debut_hc_soir`, défaut 22h) a un intérêt économique à être
     couverte par de l'énergie HC stockée plutôt que par un achat HP
     direct. Fenêtre repliée sur 0h-24h si aucun tarif HP/HC configuré
     (contrat Base) -- reste universel, cf. `resolveHpWindow()`.
   - Médiane (pas moyenne) glissante sur `strategie_nuit_hist_jours`
     jours (défaut 7) : sur cette installation, 2 jours/7 avec une grosse
     conso ponctuelle (probable VE) auraient tiré une moyenne de +30-40%
     vers le haut ; la médiane les ignore sans avoir besoin de détecter
     explicitement une charge VE (pas de source dispo, cf. Phase 2).
   - Repli sur `legacySocTarget()` (seuils fixes 100/60/80) si capacité
     vide ou historique encore insuffisant.
   - Tempo Rouge reste un cas à part (charge 100%) : le tarif Rouge HP est
     assez élevé pour préférer se couvrir plutôt que parier sur la
     prévision solaire ce jour-là précisément.

   Testé par réflexion PHP sur l'install réelle (capacité 3.84kWh, fenêtre
   HP réelle 06h-22h, conso médiane 7j = 8.37kWh sur cette fenêtre) :
   prévision réaliste (6.2kWh, celle de ce soir) -> besoin net 2.2kWh ->
   cible **57%** (proportionnel, contrairement au 50% plat de l'itération
   précédente) ; petite prévision (1kWh) -> cible 100% (quasi tout le
   besoin doit venir de la nuit) ; pas de prévision -> cible 100% (aucun
   crédit solaire, cohérent) ; grosse prévision (15kWh, largement au-dessus
   capacité+conso) -> cible 20% (plancher de sécurité minimal, correctement
   identifié comme journée où charger la nuit n'a plus d'intérêt).
   Comportement pas encore testé en conditions réelles sur une vraie nuit
   -- cf. section précédente sur l'effet non confirmé de la commande de
   charge, qui reste la vraie inconnue, indépendante de ce calcul de cible.

Pas touché : Phase 2 (chauffage/VE) et Phase 3 (apprenant) restent à faire,
cf. sections plus bas.

## Approche suggérée (à discuter/challenger, pas une décision figée)

**Phase 1 — modèle en kWh, sans apprentissage** (le plus gros gain pour le
moins d'effort, indépendant du reste) :
- Nouvelle config `batterie_capacite_kwh` (obligatoire pour activer ce
  modèle ; repli sur l'ancienne logique à seuils fixes si non renseignée —
  même pattern que `isHeuresPleines()` avec repli sur l'heure).
- Consommation à couvrir estimée par une moyenne glissante calculée depuis
  l'historique réel (`history`/`historyArch`, formule `house` ci-dessus) sur
  une fenêtre à définir (ex. les N derniers jours, ou seulement la fenêtre
  00h-09h avant que le solaire prenne le relais) plutôt qu'un chiffre saisi
  à la main.
- Formule à affiner, mais l'idée : cible SOC nuit = assez pour couvrir le
  manque prévu jusqu'à ce que le solaire prenne le relais, MAIS laisser
  suffisamment de marge libre pour absorber le surplus solaire prévu du
  lendemain (`resolveForecastKwh()`) sans re-toucher le plafond à 80%
  (le réveil HP s'en charge déjà de le remonter, donc l'objectif ici est
  seulement le bon compromis nocturne, pas la sécurité diurne).

**Phase 2 — conditionnement chauffage/VE** : une fois des sources de config
disponibles (à créer), segmenter la moyenne historique par état
chauffage/VE (moyenne conditionnelle simple, pas de ML) plutôt qu'une
moyenne globale.

**Phase 3 — vraie modélisation apprenante** (température prévue, saison) :
nécessite d'abord de comprendre comment le plugin thermostat de l'utilisateur
fait concrètement (quel algorithme, quelle interface) avant de s'en inspirer
— pas encore investigué.

## Pièges déjà identifiés, à ne pas re-découvrir

- Le plugin doit rester utilisable sans tarification HP/HC (contrat Base) —
  toujours prévoir un repli, jamais une dépendance dure à Tempo/HP-HC.
- `minSoc`/`socSet` sont en ×10 sur le fil MQTT (`minSoc: 400` = 40%).
- Ne jamais lire la valeur d'une commande *action* (`set_soc_max`, etc.) pour
  "afficher" l'état courant — `jeedom.cmd.execute()` déclenche l'action même
  sans valeur. Utiliser les commandes *info* télémétrie (`socSet`, `minSoc`)
  pour lire l'état réel.
- Dépôt de dev (`/home/nou/jeedom-zendure`, git) ≠ copie déployée
  (`/var/www/html/plugins/zendure`, copie plate, pas de sync auto) — copier
  et `chown www-data:www-data` après chaque modif, redémarrer le démon
  (`zendure::deamon_start()`) pour tout changement Python.
