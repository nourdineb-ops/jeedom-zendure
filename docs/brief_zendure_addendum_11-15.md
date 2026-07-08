# Addendum au brief de cadrage — Plugin Zendure pour Jeedom (§11 – §15)

*Handoff pour Claude Code. Consolide les décisions des sessions de cadrage (IHM, configurabilité, sources temps réel, financier). À lire **après** le brief initial (§1–10).*

> **Défauts appliqués faute d'arbitrage explicite (à corriger si besoin) :**
> - **Gain Zendure** : on **reprend la formule existante** du scénario actuel (« estimation gain € »), portée dans le plugin/démon. Ne pas réinventer le calcul en v1.
> - **Réseau** : **monophasé** — 1 jauge d'intensité. Si triphasé, prévoir 3 intensités (IINST1/2/3) et 3 aiguilles.

---

## 11. Principe directeur : « configuration over code »

**Règle d'architecture non négociable** : aucune valeur de comportement, d'endpoint, de source ou de seuil ne vit en dur dans le code. Tout descend de la config. Le démon **relit sa config au (re)démarrage** (idéalement rechargement à chaud sur signal). Objectif : ne jamais recoder pour changer un paramètre de comportement.

Trois étages de configuration, sur les deux niveaux de stockage natifs Jeedom :

- **Config plugin globale** (transport, secrets, défauts) : champs `configKey` + `data-l1key` dans `desktop/php`, relus par `config::byKey(PARAM, 'zendure')`.
- **Config par équipement (eqLogic)** (creds, seuils spécifiques appareil) : champs `eqLogicAttr` / `data-l1key="configuration"`, relus par `$this->getConfiguration("param")`.

### Étage 1 — Transport / connexion
- `mode_connexion` : *Cloud (A)* | *Local (B)* — aiguille l'implémentation de transport (abstraction §4 du brief : interface commune, 2 implémentations, **choisi par config, pas par déploiement**).
- **Cloud (A)** : URL broker (`mqtt-eu.zen-iot.com`), port, TLS on/off, **Clé Cloud d'Autorisation** (token), identifiants device (serial / productKey).
- **Local (B)** : IP Mosquitto, port (1883 / 8883 TLS), credentials éventuels.
- Champ `mode_connexion` recommandé **au niveau eqLogic** (voir §13).

### Étage 2 — Sources de lecture (références de commandes, jamais d'ID en dur)
Chaque entrée logique du plugin est **désignée** par un champ de sélection de commande Jeedom (stocke un `cmdId`, résolu en PHP par `cmd::byId($id)->execCmd()`). Repointer une source (changement de plugin téléinfo, recâblage pince) = changer un champ, pas le code.

Entrées à mapper :
- `src_grid_papp`, `src_injecte`, `src_conso_maison`, `src_solaire`
- `src_intensite` (pince amp — source rapide, voir §12), `src_imax_abonnement`
- `src_periode_tarif` (PTEC / HP-HC — soleil/lune)
- `src_tempo_now`, `src_tempo_j`, `src_tempo_j1`
- `src_prevision_solaire` (Forecast.Solar / Solcast / Open-Meteo)

> Syntaxe exacte du widget sélecteur de commande : **à confirmer sur `doc.jeedom.com/fr_FR/dev`** (mécanisme standard `jeedom.cmd` / select `data-l1key="value"` ; principe sûr, signature à valider).

### Étage 3 — Comportement (seuils / horaires / tarifs)
Tous paramétrables, défauts repris des scénarios actuels :
- **Anti-injection** : `marge_anti_injection` **en W** (défaut ~30 W), cooldown (défaut 2 s), hystérésis, période de boucle, limites min/max de sortie (W).
- **Stratégie nuit** : SOC cible par couleur Tempo J+1, fenêtres HP/HC, seuils de bascule.
- **Tarifs** (pour le gain, §14) : prix HP / HC, prix par couleur Tempo (Bleu/Blanc/Rouge), prix injection éventuel.
- **Jauge intensité** : `Imax` (défaut à saisir), seuils de couleur (défaut vert < 70 %, ambre 70–90 %, rouge > 90 %).
- **IHM** : `template_dashboard` (Flux | Condensé | Historique), `animations_actives` (on/off).

---

## 12. Source rapide & zéro-injection (correction des tours de cadrage)

### Mécanisme réel (correction importante)
Jeedom **n'a pas** de primitive « le démon s'abonne à une commande ». Le chemin natif pour réagir au changement d'une commande d'un **autre** plugin (la pince) est le **`listener`** (classe du core). Chaîne réelle :

```
pince (Zigbee/Zwave) change → listener enregistré sur le cmdId → callback PHP du plugin
→ push vers le démon via son socket interne → décision → écriture MQTT locale (setDeviceAutomationInOutLimit)
```

Le démon communique avec le cœur via callback URL + `socketport` + `apikey` du plugin. L'abonnement se fait **côté PHP (listener)**, pas côté démon.

### Plancher physique du zéro-injection
La source rapide étant une **pince Zigbee/Zwave**, le plancher n'est plus la cadence téléinfo mais **l'intervalle de reporting du module Zigbee/Zwave** (souvent « sur changement + intervalle mini », parfois 5–10 s par défaut).
- **Action prioritaire** : vérifier / régler cet intervalle de reporting (ex. `reporting` Zigbee2MQTT). C'est lui qui borne la fenêtre de transitoire d'injection.
- Une pince (mesure bidirectionnelle réelle) est un **meilleur** capteur d'injection que la PAPP téléinfo (puissance apparente mono-quadrant).

### Reformulation honnête du « jamais injecter »
Un contrôleur échantillonné dépasse toujours zéro sur une chute brutale de charge jusqu'à l'échantillon suivant : « jamais » strict (0 s) est physiquement impossible. Ce qui est garanti : **borner le transitoire à ~1 cycle de reporting**. Pour ne pas franchir zéro *entre* échantillons → **marge anti-injection en W** (garder la sortie X W sous la demande mesurée). C'est le rôle du paramètre `marge_anti_injection`.

### Séparation des deux boucles (rappel §9bis)
- **Boucle rapide** (démon local) : régulation continue de la limite de sortie via `setDeviceAutomationInOutLimit` (**pas d'écriture flash**). Sécurité zéro-injection.
- **Boucle lente** (supervision / stratégie éco) : SOC cible nocturne selon Tempo J+1 + prévision solaire. Scénario Jeedom ou logique quotidienne du démon.

### Échappatoire à garder configurable
Si le hop `listener → PHP → socket` s'avère trop lent, prévoir dès le design un mode où le démon **s'abonne directement au topic MQTT de la source** (Zigbee2MQTT), en court-circuitant le cœur. Reste « configurable » (topic saisi), mais la source rapide devient un *topic* et non une *commande*.

---

## 13. Multi-équipement & secrets

### Multi-eqLogic (tranché)
Config transport + creds + seuils **au niveau eqLogic** (`eqLogicAttr` / `getConfiguration`), pas en config plugin globale.
- Aujourd'hui : 1 seul eqLogic « Hyper 2000 » — 1 jeu de params, zéro complexité ajoutée.
- Demain : 2ᵉ Zendure = créer un 2ᵉ eqLogic, sans recoder.
- Seuls les défauts vraiment globaux (broker par défaut) peuvent rester en config plugin.

### Secrets
Clé Cloud / token stockés en config Jeedom (BDD, comme les autres clés). Utiliser la classe **`inputPassword`** du core pour **masquer l'affichage** (le stockage reste en clair, l'IHM ne l'expose pas).

---

## 14. IHM — Option 2 (dashboard clé en main) + commandes exposées

### 14.1 Commandes à exposer (spec directe de l'ancien widget HA)

**Info (télémétrie brute Zendure — lecture)** : puissance solaire entrante, puissance injectée maison, puissance prélevée réseau, SOC batterie (%), limite puissance max de sortie (W), limite max d'entrée solaire, mode fonctionnement, Total_output / Total_Solaire / Total from EDF (kWh), forecast_today (kWh).
→ mapper 1:1 sur les topics du Hyper 2000 (réfs §6 : `iobroker.zendure-solarflow`, `developer-device-data-report`).

**Action (pilotage)** : mode Charge/Décharge (input/output), Puissance manuelle (W), Limite sortie AC (W), Limite entrée AC (W), SOC minimum. Pilotage récurrent **via `setDeviceAutomationInOutLimit`** (pas de flash).

### 14.2 Éléments calculés / dérivés (⚠️ pas des lectures brutes)
- **Gain Zendure** : valeur **calculée** (défaut = reprise de la formule existante). Dépend des tarifs paramétrés (Étage 3). Trois affichages : gain jour, dépense veille (T-1), dépense jour.
- **Jauge intensité** : `marge = Imax − IINST`. Source `IINST` (Étage 2), `Imax` + seuils couleur (Étage 3). Monophasé par défaut.
- **Période HP/HC (soleil/lune)** : dérivée d'une commande téléinfo de période tarifaire (Étage 2). Soleil = HP, lune = HC.

### 14.3 Trois templates de dashboard (sélecteur `template_dashboard`)
Widgets custom dans `core/template/` (desktop + mobile). Les trois sont livrés par le plugin ; l'utilisateur choisit sans recoder.

1. **Flux** — losange spatial animé (Solaire haut / Réseau gauche / Maison droite / Batterie bas + hub central). Particules animées **dans le sens réel du courant** (flux et couleurs s'inversent selon charge/décharge/injection). Le plus riche. Prévoir fallback CSS/JS si SMIL non tenable en template Jeedom. Débit/vitesse des particules peut encoder l'intensité. Respecte l'option `animations_actives`.
2. **Condensé** — tuile compacte (~410 px) : anneau SOC, 4 flux clés en liste, barre d'intensité colorée fine, euros en pied. Une seule animation légère. Idéal mobile / petite cellule.
3. **Historique** — courbes du jour (Solaire / Maison / Injection) + gain cumulé (€), totaux en tête. **Repose sur l'historisation** : commandes concernées avec `isHistorized` activé ; lecture via `history`/`historyArch`. Granularité **jour** en v1 ; sélecteur jour/semaine/mois = évolution.

> Le plugin fournit les briques ; l'ancien Design utilisateur « Energy_info » peut être conservé et recâblé sur les nouvelles commandes (Option 1 en coexistence).

### 14.4 Parité de commandes (continuité migration — obligatoire)
Table de correspondance **ancien → nouveau** dans le README, pour que la retraite de HA ne casse ni la vue ni les scénarios existants :

| Rôle | Ancien ID | Nouvelle commande plugin |
|---|---|---|
| Mode input/output | `#26879#` | action mode Charge/Décharge |
| Limite sortie W | `#26781#` | action Limite sortie AC (W) |
| SOC cible | `#26784#` | action SOC minimum / cible |
| Prévision kWh | `#26882#` | info forecast_today |
| Injecté W | `#26768#` | info puissance injectée |
| PAPP | `#26868#` | info (source `src_grid_papp`) |
| Tempo now / J+1 / J | `#17848#` / `#15453#` / `#15452#` | sources `src_tempo_*` |

---

## 15. Points ouverts, défauts, et références à lire au démarrage

### À confirmer avant de figer
- Syntaxe exacte : sélecteur de commande en config + templates de widget custom (`doc.jeedom.com/fr_FR/dev`).
- Structure réelle des topics de commande/télémétrie du Hyper 2000 (réfs ci-dessous).
- Intervalle de reporting réel de la pince Zigbee/Zwave (fréquence des événements — cf. §10 audit).
- Faisabilité SMIL dans un template Jeedom (sinon fallback CSS/JS).
- Mono / triphasé (défaut : mono).
- Reprise vs reconstruction de la formule de gain (défaut : reprise).

### Références à faire lire au démarrage
- `iobroker.zendure-solarflow` (Nograx) — structure topics Hyper 2000
- `reinhard-brandstaedter/solarflow-control` — pilotage charge/décharge, bypass, adaptation demande
- `Zendure/developer-device-data-report` — format JSON métriques, « data downlink and device control »
- Doc dev plugin Jeedom : `doc.jeedom.com/fr_FR/dev/` (structure plugin, démon, listener, config, templates)

### Structure cible (rappel)
`plugin_info/info.json` · `core/class/*.class.php` (eqLogic + cmd + listener) · `core/ajax/` · `core/php/` (callback démon) · `desktop/` (config plugin + eqLogic + js sélecteurs) · `core/template/` (3 dashboards) · `resources/` (démon Python : abstraction transport A/B + boucle rapide) · README (parité de commandes + procédure relais DNS/Bluetooth pour le mode B).

### Où développer
Claude Code sur dépôt Git local (Mac) + script de déploiement (rsync vers VM Jeedom), Jeedom en production. Alternative : SSH direct dans la VM si Node.js installable.
