# Documentation utilisateur — plugin Zendure

Brouillon de documentation utilisateur, amorcé à partir des encarts d'aide
retirés des pages de configuration du plugin (revue écran par écran avant
publication sur le forum Jeedom). Pas encore mis en forme pour le forum —
sert de base à compléter au fil de la revue.

## Onglet Équipement (fusionné avec Transport)

- **Nom** : libre, sert juste à identifier l'équipement dans Jeedom.
- **Objet parent** : la pièce où se trouve physiquement la batterie (ex.
  Extérieur, Garage). Purement organisationnel.
- **Activé** : démarre réellement le pilotage (connexion MQTT + boucle
  anti-injection). Désactivé = l'équipement existe mais ne fait rien.
- **Visible** : affiche le dashboard "Condensé" sur la page d'accueil / la
  vue de l'objet.
- **Catégorie** : cosmétique (icône/filtre Jeedom), Énergie coché par défaut.
- **Multi-équipement** : un eqLogic Zendure = un Hyper 2000. Créer un 2e
  eqLogic pour un 2e appareil ne demande aucun code supplémentaire —
  vérifié en conditions réelles le 2026-07-27 (connexion, télémétrie,
  crons, tout est déjà isolé par équipement). Prévu pour des installations
  indépendantes (transport/sources/tarifs propres à chacune) ; faire
  pointer 2 équipements sur la même mesure réseau (même maison, 2 Zendure)
  n'est pas géré — l'anti-injection de chaque équipement calculerait sa
  cible sur l'écart réseau complet, sans se répartir la correction.

### Connexion (transport)

- **Identifiant appareil (device_id) et Product key** : identifiants
  internes Zendure de VOTRE Hyper 2000 (pas le numéro de série visible sur
  l'étiquette). Ils ne sont pas exposés par l'app officielle — la façon la
  plus fiable de les récupérer est de sniffer les infos d'une intégration
  existante qui pilote déjà l'appareil (ex. Home Assistant : fichier
  `.storage/zendure_ha.storage`, champs `productKey`/`deviceKey`).
- **Chemin A (Cloud)** : le démon se connecte au broker MQTT cloud de
  Zendure — simple, mais latence ~90s côté cloud communautaire. C'est le
  seul chemin validé en conditions réelles à ce jour sur ce projet.
- **Chemin B (Local)** : cible v1 initiale, **abandonnée** (2026-07-29) après
  plusieurs tentatives concrètes sans succès — voir
  `docs/brief_chemin_b_local.md` pour le détail. Rester sur Cloud (A).
- **Modèle d'appareil** : un seul supporté à ce jour, le Hyper 2000. Le
  mécanisme de pilotage (charge/décharge) diffère réellement d'un modèle
  Zendure à l'autre (vérifié dans le code source de l'intégration officielle
  Home Assistant) — utiliser ce plugin avec un autre modèle (Hub, ACE1500...)
  ne pilotera probablement rien, même si la télémétrie s'affiche.
- **En Cloud** : le nom d'utilisateur/mot de passe MQTT ne sont PAS le
  compte de l'app Zendure — ce sont des identifiants de session MQTT
  (obtenus par ex. en récupérant ceux utilisés par une intégration Home
  Assistant existante).
