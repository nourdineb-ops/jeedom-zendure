# Handoff — session Mac → session VM Jeedom

Ce dépôt a été démarré depuis Claude Code sur le Mac de l'utilisateur, sans accès à un
environnement Jeedom réel ni à `doc.jeedom.com`. Tu (instance Claude Code tournant
maintenant **sur la VM Jeedom, en root**) as ce que le Mac n'avait pas : le vrai
code du core Jeedom, une vraie install pour tester, et probablement un accès internet
pour consulter `doc.jeedom.com` et les dépôts de référence. Utilise-les.

## Lire dans cet ordre

1. `docs/brief_cadrage_plugin_zendure_jeedom.md` — brief de cadrage initial (§1–10) :
   objectif, contexte matériel, choix Cloud (A) vs Local (B), points de conception
   critiques (pas d'écriture flash, latence).
2. `docs/brief_zendure_addendum_11-15.md` — addendum (§11–15) : principe
   "configuration over code", les 3 étages de config, le mécanisme réel du listener
   Jeedom, multi-équipement, IHM/dashboards, parité de commandes avec l'ancien
   widget Home Assistant.
3. `README.md` — vue d'ensemble du plugin tel que scaffoldé, section
   "Points ouverts (à confirmer avant de figer)" : **c'est ta feuille de route
   prioritaire**, chaque point y est un endroit où le code a été écrit par
   déduction/best-effort faute de pouvoir vérifier contre un Jeedom réel.

## Ce qui existe déjà (v0.1.0-dev, commit `f5a9832`)

- `plugin_info/info.json` — métadonnées plugin.
- `core/class/zendure.class.php` — eqLogic + cmd, `dependancy_info/install`,
  `postSave` (écrit la config du démon + enregistre le listener sur la pince),
  `registerGridPowerListener` / `onGridPowerEvent` (chaîne listener → socket
  démon), `toHtml()` surchargé pour le dashboard "Condensé".
- `core/ajax/zendure.ajax.php` — sélecteur de commande maison (`cmd::all()` +
  filtre), utilisé en attendant confirmation d'un widget natif équivalent.
- `core/php/callback.php` — canal retour démon → cœur (télémétrie).
- `core/template/dashboard/condense/` — dashboard "Condensé" v1 (anneau SOC, 4
  flux, jauge intensité, gain €). **Flux et Historique ne sont pas implémentés.**
- `desktop/php/config.php` + `desktop/php/zendure.php` — config plugin globale +
  config eqLogic sur les 3 étages (transport / sources / comportement).
- `resources/zendure_daemon/` — démon Python :
  - `transport/mqtt_transport.py` + `factory.py` : une seule implémentation MQTT,
    paramétrée différemment pour Cloud vs Local (pas de duplication).
  - `regulation/anti_injection.py` : régulateur proportionnel (marge, cooldown,
    hystérésis, bypass d'urgence sous injection avérée).
  - `jeedom/socket_server.py` + `callback_client.py` : canaux PHP↔démon.
  - `device.py`, `zendure_daemon.py` : assemblage, rechargement à chaud SIGHUP.
  - **Pas de tests, pas d'exécution réelle** — compile (`py_compile`) mais jamais
    lancé contre un vrai broker MQTT Zendure.
- `scripts/deploy.sh` — rsync Mac → VM (n'a plus vraiment d'utilité si tu
  développes désormais directement ici).

## Priorités à traiter en premier sur la VM

Dans l'ordre suggéré :

1. **Valider la classe `listener` du core** (`core/class/zendure.class.php::registerGridPowerListener`)
   contre le vrai code source du core (`core/class/listener.class.php` sur cette VM) —
   c'est actuellement une implémentation "best effort", jamais vérifiée.
2. **Confirmer la structure réelle des topics MQTT Zendure** en lisant les dépôts de
   référence (§6/15 des briefs) et en sniffant si possible le trafic HA↔Zendure
   existant sur cette installation avant de couper Home Assistant.
3. **Tester le démon en conditions réelles** : installer les deps
   (`resources/install.sh`), lancer `zendure_daemon.py` à la main avec un fichier de
   config de test, vérifier la connexion MQTT (Cloud d'abord, plus simple à tester
   sans le hack Bluetooth/DNS du Chemin B).
4. Une fois la télémétrie confirmée, avancer sur le Chemin B (relais DNS + Bluetooth)
   qui est la cible v1 retenue (zéro-injection strict, cf. §9 du brief).

## Contexte d'environnement VM (important)

- Tu tournes **en root** sur cette VM (choix explicite de l'utilisateur, malgré le
  risque signalé : une erreur d'agent en root casse potentiellement tout le système,
  pas seulement le plugin). Reste prudent sur les commandes destructrices.
- Jeedom tourne sous `www-data:www-data`, plugins dans `/var/www/html/plugins/`.
- Le dépôt Git de dev est ici (rsyncé depuis le Mac via l'utilisateur `nou`, clé SSH
  dédiée). Il n'est **pas encore** déployé dans `/var/www/html/plugins/zendure` —
  à faire toi-même (copie ou lien symbolique) quand tu es prêt à tester dans
  l'interface Jeedom.
- Le mot de passe root a été tapé une fois dans le chat Mac pour la mise en place
  initiale (installation de Node/Claude Code) ; il est recommandé à l'utilisateur de
  le changer depuis que c'est fait — ne pas s'appuyer dessus comme secret durable.
