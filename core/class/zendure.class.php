<?php

/* This file is part of Jeedom.
 *
 * Plugin Zendure — pilotage direct d'un Zendure Hyper 2000 (sans Home Assistant).
 * Voir brief_cadrage_plugin_zendure_jeedom.md et l'addendum §11-§15 pour le contexte
 * de conception complet (config-over-code, transport Cloud/Local, anti-injection).
 */

class zendure extends eqLogic
{
    /*
     * Rôles logiques des commandes "info" (télémétrie brute, cf. brief §14.1).
     * logicalId => [nom affiché, sous-type, unité]
     */
    const INFO_COMMANDS = array(
        'solar_power' => array('Puissance solaire entrante', 'numeric', 'W'),
        'injected_power' => array('Puissance injectée maison', 'numeric', 'W'),
        'grid_power' => array('Puissance prélevée réseau', 'numeric', 'W'),
        'soc' => array('SOC batterie', 'numeric', '%'),
        'output_limit' => array('Limite puissance de sortie', 'numeric', 'W'),
        'input_limit' => array('Limite max entrée solaire', 'numeric', 'W'),
        'mode' => array('Mode de fonctionnement', 'string', ''),
        'total_output_kwh' => array('Total_output', 'numeric', 'kWh'),
        'total_solar_kwh' => array('Total_Solaire', 'numeric', 'kWh'),
        'total_from_edf_kwh' => array('Total from EDF', 'numeric', 'kWh'),
        'forecast_today_kwh' => array('forecast_today', 'numeric', 'kWh'),
        'transport_connected' => array('Transport connecté', 'binary', ''),
    );

    /*
     * Commandes "action" (pilotage récurrent via setDeviceAutomationInOutLimit,
     * jamais d'écriture flash — brief §5 point critique #1).
     */
    const ACTION_COMMANDS = array(
        'set_mode' => array('Mode Charge/Décharge', 'other'),
        'set_manual_power' => array('Puissance manuelle', 'slider'),
        'set_output_limit' => array('Limite sortie AC (W)', 'slider'),
        'set_input_limit' => array('Limite entrée AC (W)', 'slider'),
        'set_soc_min' => array('SOC minimum', 'slider'),
        'set_soc_max' => array('SOC maximum', 'slider'),
        // Exposé le 2026-07-22 (incident réel : "Mode intelligent" actif côté
        // app empêchait nos commandes deviceAutomation manuelles d'avoir le
        // moindre effet, y compris la charge) -- jusqu'ici seulement remis à 0
        // en interne à l'arrêt propre du démon (cf. Device.stop()), jamais
        // pilotable manuellement par l'utilisateur en cours de fonctionnement.
        'set_smart_mode' => array('Mode intelligent (0=off, 1=on)', 'slider'),
        'debug_capture_1h' => array('Capture télémétrie complète (1h)', 'other'),
    );

    /*
     * Commandes calculées côté plugin (brief §14.2), pas des lectures brutes.
     */
    const COMPUTED_COMMANDS = array(
        'gain_jour' => array('Gain Zendure (jour)', 'numeric', '€'),
        'gain_veille' => array('Gain veille (J-1)', 'numeric', '€'),
        'gain_solaire_jour' => array('Gain solaire (jour)', 'numeric', '€'),
        'gain_solaire_veille' => array('Gain solaire (veille)', 'numeric', '€'),
        'gain_batterie_jour' => array('Gain batterie (jour)', 'numeric', '€'),
        'gain_batterie_veille' => array('Gain batterie (veille)', 'numeric', '€'),
        'depense_veille' => array('Dépense veille (J-1)', 'numeric', '€'),
        'depense_jour' => array('Dépense jour', 'numeric', '€'),
        'jauge_intensite_marge' => array('Marge intensité (Imax - IINST)', 'numeric', 'A'),
        'periode_tarif' => array('Période HP/HC', 'string', ''),
    );

    /*     * *********************Attributs****************************** */

    /*     * *********************Methode static************************* */

    public static function dependancy_info()
    {
        $return = array();
        $return['log'] = 'dependancy';
        $return['progress_file'] = jeedom::getTmpFolder('zendure') . '/dependancy';

        if (!file_exists(dirname(__FILE__) . '/../../resources/venv/bin/python3')) {
            $return['state'] = 'nok';
        } else {
            $return['state'] = 'ok';
        }
        return $return;
    }

    public static function dependancy_install()
    {
        log::remove('zendure_update');
        return array(
            'script' => dirname(__FILE__) . '/../../resources/install.sh',
            'log' => log::getPathToLog('zendure_update'),
        );
    }

    public static function socketport()
    {
        return config::byKey('socketport', 'zendure', 55071);
    }

    private static function daemonPidFile()
    {
        return jeedom::getTmpFolder('zendure') . '/deamon.pid';
    }

    /**
     * Convention core Jeedom (utilisée par la page racine du plugin pour peupler
     * automatiquement la section "Démon") : cf. plugins/mqtt2/core/class/mqtt2.class.php
     * pour la référence. Un seul démon partagé pour tous les eqLogic zendure, comme mqtt2.
     */
    public static function deamon_info()
    {
        $return = array();
        $return['log'] = 'zendure_daemon';
        $return['state'] = 'nok';

        $dependancy = self::dependancy_info();
        $return['launchable'] = $dependancy['state'] == 'ok' ? 'ok' : 'nok';
        if ($return['launchable'] != 'ok') {
            $return['launchable_message'] = __('Merci d\'installer les dépendances avant de démarrer le démon', __FILE__);
        }

        $pidFile = self::daemonPidFile();
        if ($return['launchable'] == 'ok' && file_exists($pidFile)) {
            $pid = trim(file_get_contents($pidFile));
            if ($pid != '' && @posix_getsid((int) $pid)) {
                $return['state'] = 'ok';
            } else {
                @unlink($pidFile);
            }
        }
        return $return;
    }

    public static function deamon_start()
    {
        self::deamon_stop();
        $deamonInfo = self::deamon_info();
        if ($deamonInfo['launchable'] != 'ok') {
            throw new Exception(__('Veuillez installer les dépendances avant de démarrer le démon', __FILE__));
        }

        self::writeDaemonConfig();

        // Pas de realpath() sur le python du venv : ça résout le symlink vers l'interpréteur
        // système et perd le site-packages du venv (paho-mqtt introuvable au lancement).
        $python = dirname(__FILE__) . '/../../resources/venv/bin/python3';
        $daemonScript = realpath(dirname(__FILE__) . '/../../resources/zendure_daemon/zendure_daemon.py');
        $configPath = jeedom::getTmpFolder('zendure') . '/daemon_config.json';

        $cmd = $python . ' ' . $daemonScript;
        $cmd .= ' --config ' . $configPath;
        $cmd .= ' --callback ' . network::getNetworkAccess('internal') . '/plugins/zendure/core/php/callback.php';
        $cmd .= ' --apikey ' . jeedom::getApiKey('zendure');
        $cmd .= ' --socketport ' . self::socketport();
        $cmd .= ' --pid ' . self::daemonPidFile();
        $cmd .= ' --loglevel ' . log::convertLogLevel(log::getLogLevel('zendure'));
        log::add('zendure', 'info', 'Démarrage du démon Zendure : ' . $cmd);
        exec($cmd . ' >> ' . log::getPathToLog('zendure_daemon') . ' 2>&1 &');

        $i = 0;
        while ($i < 30) {
            $deamonInfo = self::deamon_info();
            if ($deamonInfo['state'] == 'ok') {
                break;
            }
            sleep(1);
            $i++;
        }
        if ($i >= 30) {
            log::add('zendure', 'error', __('Impossible de démarrer le démon Zendure, vérifiez les logs', __FILE__), 'unableStartDeamon');
            return false;
        }
        message::removeAll('zendure', 'unableStartDeamon');
        return true;
    }

    public static function deamon_stop()
    {
        $pidFile = self::daemonPidFile();
        if (file_exists($pidFile)) {
            $pid = intval(trim(file_get_contents($pidFile)));
            if ($pid > 0) {
                system::kill($pid);
            }
            @unlink($pidFile);
        }
        system::kill('zendure_daemon.py');
        system::fuserk(self::socketport());
    }

    /**
     * Écrit le fichier de config JSON consommé par le démon (resources/zendure_daemon/config/loader.py).
     * Appelé à chaque postSave d'un eqLogic zendure : c'est le point d'application central
     * du principe "configuration over code" (brief §11) côté PHP.
     */
    public static function writeDaemonConfig()
    {
        $equipments = array();
        foreach (self::byType('zendure', true) as $eqLogic) {
            /* @var zendure $eqLogic */
            // Équipement absent de la liste -> le démon (reload_config(), cf.
            // zendure_daemon.py) le détecte comme "disparu" et coupe proprement
            // sa connexion MQTT (Device.stop(), y compris le repli smartMode).
            // C'est le levier demandé pour cohabiter avec un autre pilote (Home
            // Assistant) sur le même compte cloud : décocher "Connexion active"
            // libère la session MQTT partagée sans désinstaller le plugin.
            if (!$eqLogic->getConfiguration('connexion_active', 1)) {
                continue;
            }
            $equipments[] = $eqLogic->toDaemonConfig();
        }
        $config = array(
            'equipments' => $equipments,
            'jeedom' => array(
                'callback_url' => network::getNetworkAccess('internal') . '/plugins/zendure/core/php/callback.php',
                'apikey' => jeedom::getApiKey('zendure'),
                'socketport' => self::socketport(),
            ),
        );
        $path = jeedom::getTmpFolder('zendure') . '/daemon_config.json';
        file_put_contents($path, json_encode($config, JSON_PRETTY_PRINT));
        log::add('zendure', 'debug', 'writeDaemonConfig : ' . count($equipments) . ' équipement(s) -> ' . $path);
        return $path;
    }

    /*     * *********************Methode d'instance************************* */

    public function toDaemonConfig()
    {
        // Chaîne de repli à 3 niveaux : valeur de cet eqLogic -> défaut global du
        // plugin (config::byKey, réglé sur la page racine) -> défaut codé en dur.
        $antiInjection = array(
            // Coupure complète de la boucle rapide + du cron HP (cf.
            // runOptimisationHP()) : demande explicite pour pouvoir cohabiter
            // avec un autre pilote du même appareil (ex. Home Assistant) sans
            // que ce plugin ne continue à commander la limite de sortie en
            // parallèle. Actif par défaut (1) : ne change rien aux installs
            // existantes tant que l'utilisateur ne décoche pas explicitement.
            'enabled' => (bool) $this->getConfiguration('anti_injection_active', 1),
            'marge_w' => $this->getConfiguration('marge_anti_injection', config::byKey('default_marge_anti_injection', 'zendure', 30)),
            'cooldown_s' => $this->getConfiguration('cooldown_anti_injection', config::byKey('default_cooldown_anti_injection', 'zendure', 2)),
            // Cadence dédiée au sens "import" (grid >= marge, ni urgence ni
            // risque d'injection immédiat) -- volontairement plus lente que
            // cooldown_s : réagir trop vite dans ce sens a un historique
            // d'oscillation (cf. commentaire de tête de fichier anti_injection.py),
            // le temps que l'appareil se stabilise sur la commande précédente
            // avant d'en recalculer une autre par-dessus. 15s par défaut,
            // calé sur le temps de stabilisation observé en réel le 2026-07-28
            // (grid retombé ~10-15s après une nouvelle limite envoyée par le cron).
            'cooldown_import_s' => $this->getConfiguration('cooldown_import_anti_injection', config::byKey('default_cooldown_import_anti_injection', 'zendure', 15)),
            // Zone morte en % : dans ce sens "import" uniquement (jamais côté
            // urgence/injection, qui doit rester maximalement réactif), ignore
            // une correction si la nouvelle cible reste à +/- X% de la dernière
            // valeur commandée -- évite de renvoyer une commande pour une
            // variation négligeable.
            'import_tolerance_pct' => $this->getConfiguration('tolerance_import_anti_injection', config::byKey('default_tolerance_import_anti_injection', 'zendure', 10)),
            'limit_min_w' => $this->getConfiguration('limite_min_w', 0),
            'limit_max_w' => $this->getConfiguration('limite_max_w', 1200),
            'urgent_injection_w' => $this->getConfiguration('urgent_injection_w', -20),
        );

        // Fallback 'cloud' (pas 'local', retiré de l'IHM, cf. ensureDefaultConfiguration()) :
        // ne devrait jamais servir en pratique puisque preSave() persiste déjà la valeur dès
        // la création, gardé pour les eqLogics existants créés avant ce correctif.
        $mode = $this->getConfiguration('mode_connexion', 'cloud');
        $conf = array(
            'eq_id' => $this->getId(),
            'device_id' => $this->getConfiguration('device_id'),
            // Profil d'appareil (cf. resources/zendure_daemon/device_profiles/) : isole ce
            // qui varie réellement d'un modèle Zendure à l'autre dans le pilotage
            // (deviceAutomation) -- un seul profil existe à ce jour (hyper2000), repli
            // automatique côté démon si vide/inconnu.
            'device_model' => $this->getConfiguration('device_model', 'hyper2000'),
            'product_key' => $this->getConfiguration('product_key'),
            'mode_connexion' => $mode,
            'anti_injection' => $antiInjection,
            'loop_period_s' => $this->getConfiguration('loop_period_s', 1),
            // Filtre "ne pousser que si ça change, au minimum toutes les X min" (cf.
            // échange sur le volume d'historique) — TelemetryThrottle côté démon.
            // noise_threshold : tolérance sur les valeurs numériques (une puissance
            // instantanée frémit de quelques W en permanence, sans tolérance le filtre
            // ne réduit presque rien sur ce type de valeur — constaté en direct).
            'telemetry_min_interval_s' => (int) $this->getConfiguration('telemetry_min_interval_s', config::byKey('default_telemetry_min_interval_s', 'zendure', 300)),
            'telemetry_noise_threshold' => (float) $this->getConfiguration('telemetry_noise_threshold', config::byKey('default_telemetry_noise_threshold', 'zendure', 3)),
            // Secours BLE (cf. device.py::maybe_ble_failover) : désactivé par défaut,
            // ne se déclenche de toute façon que si la télémétrie MQTT est déjà
            // muette -- ne concerne jamais l'écriture (lecture seule, cf.
            // transport/ble_fallback.py), et cadencé sur le cron HP (5 min), pas une
            // connexion permanente (demande explicite : cohabitation avec
            // TheengsGateway sur le même adaptateur Bluetooth).
            'ble_failover_active' => (bool) $this->getConfiguration('ble_failover_active', 0),
            'ble_address' => $this->getConfiguration('ble_address', ''),
        );

        if ($mode == 'cloud') {
            $conf['cloud_host'] = $this->getConfiguration('cloud_host', config::byKey('default_cloud_host', 'zendure', 'mqtteu.zen-iot.com'));
            $conf['cloud_port'] = $this->getConfiguration('cloud_port', config::byKey('default_cloud_port', 'zendure', 1883));
            $conf['cloud_tls'] = (bool) $this->getConfiguration('cloud_tls', false);
            $conf['cloud_username'] = $this->getConfiguration('cloud_username');
            $conf['cloud_auth_key'] = $this->getConfiguration('cloud_auth_key');
            $conf['cloud_client_id'] = $this->getConfiguration('cloud_client_id');
        } elseif ($mode == 'simulation') {
            // Aucun réseau : scénario synthétique conso/PV côté démon (cf.
            // transport/simulated_transport.py), pour voir tourner la boucle
            // anti-injection/le dashboard/le calcul de gain sans appareil réel.
            // Capacité batterie réutilisée depuis la config stratégie nuit
            // existante (batterie_capacite_kwh) plutôt que dupliquée.
            $conf['simulation'] = array(
                'capacity_kwh' => (float) $this->getConfiguration('batterie_capacite_kwh', 5),
                'cycle_period_s' => (int) $this->getConfiguration('simulation_cycle_period_s', 900),
                'initial_soc' => (float) $this->getConfiguration('simulation_initial_soc', 50),
            );
        } else {
            $conf['local_host'] = $this->getConfiguration('local_host');
            $conf['local_port'] = $this->getConfiguration('local_port', 1883);
            $conf['local_tls'] = (bool) $this->getConfiguration('local_tls', false);
            $conf['local_username'] = $this->getConfiguration('local_username');
            $conf['local_password'] = $this->getConfiguration('local_password');
        }
        return $conf;
    }

    /**
     * Point d'extension standard eqLogic pour personnaliser l'affichage dashboard.
     * Aiguille selon le sélecteur `template_dashboard` (addendum §14.3).
     * "Condensé" reste un override total (petit widget statique, pas d'animation
     * à préserver entre rafraîchissements). "Flux" retombe désormais sur le rendu
     * standard eqLogic (parent::toHtml) : le losange animé est un widget de
     * COMMANDE (zendure::flux_widget, cf. createOrUpdateFluxWidget()), pas un
     * override ici — nécessaire pour que ses animations SVG ne soient jamais
     * détruites/relancées par le remplacement intégral du HTML qu'effectue
     * Jeedom sur tout widget rendu au niveau eqLogic (jeedom.eqLogic.refreshValue).
     */
    public function toHtml($_version = 'dashboard')
    {
        $template = $this->getConfiguration('template_dashboard', 'condense');
        if ($template == 'condense') {
            return $this->toHtmlCondense();
        }
        return parent::toHtml($_version);
    }

    private function toHtmlCondense()
    {
        $path = dirname(__FILE__) . '/../template/dashboard/condense/condense.html';
        $html = file_get_contents($path);

        // (float) explicite : une commande jamais alimentée (eqLogic tout juste créé,
        // démon pas encore connecté au vrai appareil) renvoie '' via execCmd(), et
        // PHP 8 refuse l'arithmétique sur une chaîne non numérique ('' + '' fatal).
        $solar = (float) $this->getCmdValue('solar_power');
        // "Réseau" = la vraie mesure externe (pince/PAPP Linky) en priorité, pas la
        // télémétrie interne Zendure (grid_power/gridInputPower, qui ne reflète que
        // ce que le boîtier Zendure lui-même tire du réseau, souvent 0 en décharge)
        // — corrigé suite à un écart constaté avec la vraie pince EDF (~330-350W
        // affichés, 0W avant correction). Repli sur le curated grid_power (comme
        // injected_power ci-dessous) si src_grid_papp n'est pas configurée : sans
        // ça, "Réseau"/"Maison" restaient figés à 0W en permanence sur tout eqLogic
        // sans pince réelle configurée -- notamment le mode simulation, qui n'a par
        // nature aucune pince externe à configurer et où grid_power EST la valeur de
        // référence (pas un écho approximatif du boîtier). Signalé le 2026-08-04 :
        // la simulation semblait ne "rien faire" alors que le régulateur réagissait
        // bien en interne, juste invisible dans le widget.
        $grid = (float) $this->getSourceOrDefault('src_grid_papp', 'grid_power');
        $injected = (float) $this->getSourceOrDefault('src_injection', 'injected_power');
        // Signée, pas abs() : même formule que le widget Flux (déjà revue le
        // 2026-07-25, cf. flux_widget.html) -- injected_power/outputHomePower ne
        // peut structurellement pas être négatif côté appareil réel (retombe à 0
        // en charge), donc abs() n'y changeait rien en pratique, mais gardait une
        // fausse impression que ce champ pourrait être négatif. Root cause du
        // "Maison" incohérent signalé le 2026-08-04 en simulation : pas ce abs(),
        // mais un SimulatedTransport qui n'incarnait pas encore la vraie sémantique
        // d'outputHomePower (solaire direct + décharge) -- corrigé côté simulation.
        $house = $grid + $injected;

        $imax = $this->resolveImaxAmpere();
        $intensite = (float) $this->getConfiguredSourceValue('src_intensite');
        $pct = $imax > 0 ? min(100, round(($intensite / $imax) * 100)) : 0;
        $seuilAmbre = (float) $this->getConfiguration('seuil_intensite_ambre', 70);
        $seuilRouge = (float) $this->getConfiguration('seuil_intensite_rouge', 90);
        $color = '#4cd964';
        if ($pct >= $seuilRouge) {
            $color = '#ff3b30';
        } elseif ($pct >= $seuilAmbre) {
            $color = '#ff9500';
        }

        $animationsOn = $this->getConfiguration('animations_actives', 1);
        list(, $modeLabel, ) = $this->modeInfo($this->getCmdValue('mode'));

        // transport_connected (cf. Device._on_connection_change côté démon) : seul
        // signal de connectivité actuellement remonté comme commande interrogeable
        // (la détection de télémétrie muette/WiFi instable, elle, ne part que sous
        // forme d'alerte ponctuelle message::add(), pas d'un état permanent -- pas
        // couvert ici). '' == 1 vaut false en PHP : un eqLogic jamais encore
        // connecté (tout juste créé) affiche donc "Hors ligne" par défaut, ce qui
        // est le comportement prudent attendu plutôt que de prétendre "en ligne"
        // sans jamais l'avoir confirmé (signalé par l'utilisateur le 2026-08-04 :
        // l'état hors ligne n'était pas du tout pris en compte jusqu'ici).
        $connected = $this->getCmdValue('transport_connected') == 1;

        $tokens = array(
            '##EQ_ID##' => $this->getId(),
            '##NAME##' => $this->getName(),
            '##EQ_LINK##' => $this->getLinkToConfiguration(),
            '##MODE##' => $modeLabel,
            '##SOC##' => round((float) $this->getCmdValue('soc')),
            '##SOLAR_W##' => round($solar),
            '##GRID_W##' => round($grid),
            '##HOUSE_W##' => round($house),
            '##INJECTED_W##' => round($injected),
            '##INTENSITE_PCT##' => $pct,
            '##INTENSITE_A##' => round($intensite, 1),
            '##IMAX_A##' => round($imax, 1),
            '##INTENSITE_COLOR##' => $color,
            '##GAIN_JOUR##' => number_format((float) $this->getCmdValue('gain_jour'), 2),
            '##ANIMATIONS_CLASS##' => $animationsOn ? 'zc-animated' : '',
            '##OFFLINE_CLASS##' => $connected ? '' : 'zc-offline',
        );
        return str_replace(array_keys($tokens), array_values($tokens), $html);
    }

    /**
     * Traduit la commande "mode" (acMode brut de la télémétrie Zendure : 1=charge,
     * 2=décharge, cf. commentaire de set_mode dans mqtt_transport.py) en [icône
     * FontAwesome, libellé, flèche] pour l'affichage. acMode arrive en entier (1/2),
     * jamais en toutes lettres — un simple strpos('charge') ne matchait donc jamais
     * et affichait "2" tel quel (constaté sur le dashboard réel).
     */
    private function modeInfo($mode)
    {
        $mode = (string) $mode;
        if ($mode === '2') {
            return array('fa-arrow-down', 'Décharge', '↓');
        }
        if ($mode === '1') {
            return array('fa-arrow-up', 'Charge', '↑');
        }
        $modeLc = strtolower($mode);
        if (strpos($modeLc, 'décharge') !== false || strpos($modeLc, 'decharge') !== false || strpos($modeLc, 'discharg') !== false) {
            return array('fa-arrow-down', 'Décharge', '↓');
        }
        if (strpos($modeLc, 'charge') !== false) {
            return array('fa-arrow-up', 'Charge', '↑');
        }
        return array('fa-pause', $mode !== '' ? $mode : 'Veille', '↔');
    }

    // tempoColorInfo() a été porté en JS (cf. cmd.info.string.flux_widget.html,
    // fonction tempoColorInfo) : le widget Flux est désormais une commande
    // (cf. createOrUpdateFluxWidget()) dont les valeurs Tempo doivent être
    // recolorées en direct côté client à chaque mise à jour, sans aller-retour
    // serveur — plus utilisé côté PHP.

    /**
     * Lit la dernière valeur connue d'une commande "info" par son logicalId
     * (pas de déclenchement matériel : execCmd() sur une commande info renvoie
     * l'état déjà collecté).
     */
    private function getCmdValue($logicalId)
    {
        $cmd = $this->getCmd(null, $logicalId);
        if (!is_object($cmd)) {
            return 0;
        }
        return $cmd->execCmd();
    }

    /**
     * Lit la valeur d'une source externe (Étage 2 : référence humaine
     * #[Objet][Eq][Cmd]# stockée dans la config via le sélecteur natif
     * jeedom.cmd.getSelectModal, ex. src_intensite / src_grid_papp) — jamais
     * d'ID en dur, cf. addendum §11.
     */
    private function getConfiguredSourceValue($configKey)
    {
        $cmd = $this->resolveSourceCmd($configKey);
        if (!is_object($cmd)) {
            return 0;
        }
        return $cmd->execCmd();
    }

    /**
     * Imax (A) de la jauge d'intensité : priorité à la source "Imax abonnement"
     * (src_imax_abonnement, onglet Sources) si configurée -- c'est ce que son
     * libellé promet ("sert à la jauge d'intensité") mais qu'aucun code ne
     * faisait jusqu'ici (bug trouvé le 2026-07-29 : la jauge n'utilisait que
     * imax_ampere, un champ texte manuel séparé sur l'onglet Comportement,
     * jamais cette source malgré sa description). Repli sur imax_ampere, puis
     * sur 30A codé en dur si rien n'est configuré nulle part.
     */
    private function resolveImaxAmpere()
    {
        $srcCmd = $this->resolveSourceCmd('src_imax_abonnement');
        if (is_object($srcCmd)) {
            $value = (float) $srcCmd->execCmd();
            if ($value > 0) {
                return $value;
            }
        }
        return (float) $this->getConfiguration('imax_ampere', 30);
    }

    /**
     * Comme getConfiguredSourceValue(), mais retombe sur une commande interne
     * du plugin (getCmdValue) si l'utilisateur n'a pas configuré de source —
     * permet de proposer un choix (ex. src_injection : la télémétrie Zendure
     * curée "injected_power" par défaut, ou n'importe quelle autre commande
     * jugée plus fiable, ex. une pince externe) sans rien casser tant que
     * personne n'a rien configuré.
     */
    private function getSourceOrDefault($configKey, $defaultLogicalId)
    {
        if (!empty($this->getConfiguration($configKey))) {
            return $this->getConfiguredSourceValue($configKey);
        }
        return $this->getCmdValue($defaultLogicalId);
    }

    /**
     * Résout une référence humaine (#[Objet][Eq][Cmd]#) stockée en config
     * vers l'objet cmd réel. Retourne null si vide ou introuvable.
     */
    private function resolveSourceCmd($configKey)
    {
        $human = $this->getConfiguration($configKey);
        if (empty($human)) {
            return null;
        }
        try {
            return cmd::byString($human);
        } catch (Exception $e) {
            log::add('zendure', 'debug', 'resolveSourceCmd(' . $configKey . ') introuvable pour "' . $human . '" : ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Comme resolveSourceCmd(), mais retombe sur une commande interne du
     * plugin (par logicalId) si rien n'est configuré — pendant PHP de
     * getSourceOrDefault(), mais renvoie l'objet cmd (pour en extraire l'id
     * côté widget Flux, cf. createOrUpdateFluxWidget()) plutôt que sa valeur.
     */
    private function resolveSourceCmdOrDefault($configKey, $defaultLogicalId)
    {
        $cmd = $this->resolveSourceCmd($configKey);
        if (is_object($cmd)) {
            return $cmd;
        }
        return $this->getCmd(null, $defaultLogicalId);
    }

    private static function cmdIdOrZero($cmd)
    {
        return is_object($cmd) ? $cmd->getId() : 0;
    }

    public function preInsert()
    {
    }

    public function postInsert()
    {
    }

    public function preSave()
    {
        $this->ensureDefaultConfiguration();
    }

    /**
     * Persiste explicitement en base les valeurs par défaut des réglages IHM/
     * connexion, plutôt que de compter uniquement sur le 2e argument de
     * getConfiguration() ailleurs dans le code. Sans ça, un eqLogic tout juste
     * créé (configuration vide, avant que l'utilisateur n'ouvre/enregistre un
     * onglet) a ces clés absentes en base : le rendu dashboard "Condensé" reste
     * correct grâce au fallback code, mais le <select> IHM apparaît vide/non
     * pré-sélectionné côté formulaire (rien à afficher tant que la clé n'existe
     * pas), et certains rendus dépendant de la présence réelle de la config
     * peuvent rester incomplets jusqu'au premier enregistrement manuel de cet
     * onglet (constaté par l'utilisateur le 2026-08-04). Appelé depuis preSave()
     * (avant l'INSERT/UPDATE, cf. DB::save()) pour que ces valeurs fassent partie
     * du tout premier enregistrement, sans save() supplémentaire ni risque de
     * récursion. N'écrase jamais un choix explicite de l'utilisateur (vérifie
     * qu'la clé est bien absente, pas juste "fausse"/0).
     */
    private function ensureDefaultConfiguration()
    {
        if ($this->getConfiguration('template_dashboard', '') == '') {
            $this->setConfiguration('template_dashboard', 'condense');
        }
        if ($this->getConfiguration('animations_actives', '') == '') {
            $this->setConfiguration('animations_actives', 1);
        }
        if ($this->getConfiguration('mode_connexion', '') == '') {
            // 'cloud' : seul mode encore proposé dans le menu de connexion avec
            // 'simulation' (Chemin B/local retiré de l'IHM, cf. commit 552b440) --
            // l'ancien défaut 'local' d'ailleurs codé dans toDaemonConfig() n'est
            // plus jamais sélectionnable et ferait planter build_transport() côté
            // démon (local_host requis, absent) si jamais atteint.
            $this->setConfiguration('mode_connexion', 'cloud');
        }
        if ($this->getConfiguration('type_contrat', '') == '') {
            // 'base' : seul contrat qui ne suppose rien (pas de tarif HP/HC ni
            // Tempo souscrit) -- l'ancien défaut 'tempo' reflétait le contrat de
            // l'auteur, pas un choix universel (plugin destiné à un public plus
            // large que cette seule installation).
            $this->setConfiguration('type_contrat', 'base');
        }
    }

    public function postSave()
    {
        log::add('zendure', 'debug', 'postSave eq_id=' . $this->getId() . ' (' . $this->getName() . ')');
        $this->ensureFluxTileSize();
        $this->createOrUpdateCommands();
        $this->registerTelemetryListener('src_grid_papp', 'grid_power', 'onGridPowerEvent');
        $this->registerTelemetryListener('src_solaire', 'solar_power', 'onSolarPowerEvent');
        $this->registerTelemetryListener('src_injection', 'injected_power', 'onInjectedPowerEvent');
        self::ensureCronRegistered();
        self::writeDaemonConfig();
        $this->reloadDaemonConfig();
    }

    /**
     * La tuile eqLogic ("largeur"/"hauteur" définies via getDisplay(), poignée de
     * redimensionnement du dashboard) partent par défaut sur `auto` — sous
     * Packery, ça revient à une petite cellule de grille, pas à la taille du
     * contenu (contrairement à l'ancien override eqLogic::toHtml() du gabarit
     * Flux, qui ne passait jamais par ce mécanisme et s'affichait donc sans
     * contrainte). Constaté après bascule vers le widget de commande : le
     * losange/jauge/Tempo/cartes financières se retrouvaient tassés dans une
     * tuile bien plus étroite/basse que prévu (dernier exemple en date : le
     * bloc Pilotage tronqué de quelques px en bas après l'agrandissement du
     * diagramme 400x320, cf. équidistance des noeuds). On force donc une
     * taille de départ raisonnable UNE SEULE FOIS par dimension (si
     * l'utilisateur n'a jamais redimensionné la tuile lui-même) ; ensuite
     * libre à lui d'ajuster via la poignée, sans que ce code ne revienne
     * écraser son choix.
     */
    private function ensureFluxTileSize()
    {
        if ($this->getConfiguration('template_dashboard', 'condense') != 'flux') {
            return;
        }
        $changed = false;
        // Anciens défauts auto successifs (660px, puis 480px), avant les itérations
        // de retour utilisateur sur la largeur/proportions du widget — jamais des
        // valeurs choisies par l'utilisateur, donc toujours sûr de les réaligner sur
        // le défaut courant plutôt que de les figer.
        $width = $this->getDisplay('width');
        if ($width == '' || $width == '660px' || $width == '480px') {
            $this->setDisplay('width', '460px');
            $changed = true;
        }
        // Jamais forcée avant (le besoin n'est apparu qu'avec le diagramme 400x320) :
        // seule une valeur vide est donc un ancien défaut ici, pas de lignée à gérer.
        $height = $this->getDisplay('height');
        if ($height == '') {
            $this->setDisplay('height', '680px');
            $changed = true;
        }
        if ($changed) {
            $this->save(true);
        }
    }

    /**
     * Cron HP (addendum, comparaison ligne à ligne avec le scénario Jeedom
     * historique le 2026-07-11) : la branche périodique que ce scénario
     * exécute en plus de sa branche FAST réactive — même formule que la
     * boucle rapide du démon, filet de sécurité (réveil HP, plafond SOC,
     * rattrapage si le démon est down) plutôt que mécanisme principal
     * d'optimisation à la hausse depuis que la boucle rapide gère aussi ce
     * sens (cf. regulation/anti_injection.py, corrigé 2026-07-28 -- avant
     * cette date, la boucle rapide ignorait volontairement l'import excessif
     * et ce cron était le seul recours, d'où un intervalle de 5 min à
     * l'origine). Passé à 1 min le même jour (incident réel : une coupure
     * d'urgence bloquée à 0W pendant 5 min complètes en attendant ce cron).
     *
     * Volontairement en mode simulation par défaut (config cron_hp_dry_run,
     * coché) : logue ce qu'il ferait sans jamais toucher à l'appareil, le
     * temps de valider le comportement en conditions réelles avant activation.
     */
    private static function ensureCronRegistered()
    {
        self::ensureCron('cronOptimisationHP', '*/1 * * * *', 'Cron cronOptimisationHP enregistré (*/1 * * * *, mode simulation par défaut)');
        self::ensureCron('cronRolloverMinuit', '0 0 * * *', 'Cron cronRolloverMinuit enregistré (0 0 * * *, bascule jour -> veille)');
        self::ensureCron('cronRecupererTarifs', '0 3 1 * *', 'Cron cronRecupererTarifs enregistré (0 3 1 * *, récupération mensuelle des tarifs)');
        self::ensureCron('cronStrategieNuit', '0 0 * * *', 'Cron cronStrategieNuit enregistré (0 0 * * *, mode simulation par défaut)');
    }

    private static function ensureCron($function, $schedule, $logMessage)
    {
        $cron = cron::byClassAndFunction('zendure', $function);
        if (!is_object($cron)) {
            $cron = new cron();
            $cron->setClass('zendure');
            $cron->setFunction($function);
            $cron->setEnable(1);
            $cron->setDeamon(0);
            $cron->setSchedule($schedule);
            $cron->save();
            log::add('zendure', 'info', $logMessage);
        }
    }

    public static function cronOptimisationHP()
    {
        foreach (self::byType('zendure', true) as $eqLogic) {
            /* @var zendure $eqLogic */
            if (!$eqLogic->getIsEnable()) {
                continue;
            }
            $eqLogic->runOptimisationHP();
        }
    }

    private function runOptimisationHP()
    {
        if (!$this->getConfiguration('anti_injection_active', 1)) {
            // Coupure complète (même flag que la boucle rapide côté démon, cf.
            // toDaemonConfig()) : pas même un log de simulation, pour ne pas
            // laisser croire que le plugin surveille encore quoi que ce soit
            // pendant qu'un autre pilote (Home Assistant) est aux commandes.
            return;
        }
        $dryRun = (bool) $this->getConfiguration('cron_hp_dry_run', 1);

        // Réveil automatique : runStrategieNuit() bascule l'appareil en mode charge
        // pour toute la plage de charge nuit -- mais rien ne le repassait en
        // décharge une fois cette plage terminée (signalé le 2026-07-22 : 389W
        // tirés au réseau, batterie à 40% totalement inutilisée toute la matinée,
        // mode resté coincé à 1/charge après la fin des HC, alors que le cron
        // continuait d'envoyer des set_output_limit sans effet puisque l'appareil
        // n'était pas en décharge). Ce cron tourne déjà toutes les 5 min : point
        // naturel pour corriger ça sans cron dédié.
        //
        // Le vrai déclencheur, quand il existe, c'est le changement de PTEC (la
        // source tarifaire HP/HC bascule en HP au même instant que le fournisseur,
        // donc plus fiable et plus précis qu'une heure supposée -- pas de dérive si
        // l'horaire HC du contrat change). isHeuresPleines() retourne null si
        // aucune source tarifaire HP/HC n'est configurée (contrat "base", ou juste
        // pas encore renseignée) : dans ce cas seulement, on retombe sur l'heure de
        // config heure_fin_charge_nuit -- pour que le plugin reste utilisable sans
        // tarification HP/HC.
        $heuresPleines = $this->isHeuresPleines();
        if ($heuresPleines === null) {
            $heureFinChargeNuit = (string) $this->getConfiguration('heure_fin_charge_nuit', '06:00');
            $heuresPleines = date('H:i') >= $heureFinChargeNuit;
            $motifReveil = 'fin de charge nuit (' . $heureFinChargeNuit . ') atteinte, pas de tarif HP/HC configuré';
        } else {
            $motifReveil = 'HP détectées (tarif)';
        }
        if ($heuresPleines && (int) $this->getCmdValue('mode') === 1) {
            // Repasse aussi le plafond SOC à 100% (pas seulement le mode) : sinon
            // le plafond de nuit (souvent < 100%, cf. runStrategieNuit()) reste actif
            // toute la journée -- la batterie "pleine" à ce plafond bas n'a plus de
            // marge pour absorber le solaire du jour, qui repart alors intégralement
            // au réseau. Incident réel et documenté le 2026-07-22 (analyse historique
            // à l'appui, cf. mémoire) : plafond resté à 80% depuis le 13/07 (jamais
            // remonté après un test), injection systématique chaque jour ensoleillé
            // dès que la batterie touchait ce plafond -- pas un comportement nouveau
            // de l'appareil, une conséquence mécanique du plafond bas laissé en place
            // en heures pleines. En HP, la batterie doit pouvoir se remplir jusqu'à
            // 100% : c'est la stratégie nuit qui décide de la baisser, seulement pour
            // la nuit suivante.
            log::add('zendure', 'info', sprintf(
                '[cronOptimisationHP]%s eq_id=%d %s, appareil encore en mode charge -> repasse en décharge + SOC max 100%%',
                $dryRun ? ' [SIMULATION]' : '', $this->getId(), $motifReveil
            ));
            if (!$dryRun) {
                $modeCmd = $this->getCmd(null, 'set_mode');
                if (is_object($modeCmd)) {
                    $modeCmd->execCmd(array('select' => 2));
                }
                $socMaxCmd = $this->getCmd(null, 'set_soc_max');
                if (is_object($socMaxCmd)) {
                    $socMaxCmd->execCmd(array('slider' => 100));
                }
            }
        }

        // Anti-injection réservée aux HC AVÉRÉES ($heuresPleines === false),
        // pas simplement "hors HP" : reproduit fidèlement l'ancien scénario
        // Jeedom de référence (id 182 "Zendure", désactivé depuis le
        // portage), qui gardait sa branche "optimisation import réseau"
        // derrière un `if (!$inHC)` strict -- cette garde avait disparu lors
        // du portage vers cette méthode (constaté 2026-07-26 : la nuit
        // dernière, ce bloc a tourné toutes les 5 min sans interruption et
        // réaffirmé la décharge en continu, écrasant à chaque fois la charge
        // envoyée une fois par runStrategieNuit() à minuit -- programmes
        // charge/décharge mutuellement exclusifs côté appareil -- batterie
        // vidée de 92% à 5% en une nuit).
        //
        // isHeuresPleines() === false (HC avérée, tarif qui distingue HP/HC)
        // uniquement -- PAS === null (aucune distinction tarifaire
        // configurée, contrat "base") : demande explicite de l'utilisateur,
        // le plugin doit rester universel (cf. mémoire
        // feedback_plugin_must_stay_universal). Sur un contrat base, il n'y
        // a pas de fenêtre nuit moins chère à réserver pour charger depuis le
        // réseau -- la batterie n'est de toute façon remplie QUE par le
        // solaire, donc continuer à décharger la nuit (comme en journée)
        // reste pertinent : anti-injection tourne alors 24h/24, comportement
        // inchangé pour ces utilisateurs.
        if ($heuresPleines !== false) {
            $grid = (float) $this->getSourceOrDefault('src_grid_papp', 'grid_power');
            // Bascule sur 0 si la télémétrie "injected" est périmée (incident réel du
            // 2026-07-28 : pendant une coupure WiFi de l'appareil, grid -- source
            // externe, jamais affecté par ce WiFi -- restait frais pendant qu'injected
            // restait figé, faussant la cible calculée sans que rien ne le signale).
            // Même seuil que le côté démon (Device._injected_stale_after_s) et que
            // l'offline threshold déjà utilisé dans accumulateEuro().
            $injectedCmd = $this->resolveSourceCmdOrDefault('src_injection', 'injected_power');
            $injected = 0.0;
            if (is_object($injectedCmd)) {
                $injectedCollectDate = $injectedCmd->getCollectDate();
                $injectedOfflineThresholdS = 2 * (float) $this->getConfiguration('telemetry_min_interval_s', 300);
                $injectedStale = empty($injectedCollectDate) || (time() - strtotime($injectedCollectDate)) > $injectedOfflineThresholdS;
                $injected = $injectedStale ? 0.0 : (float) $injectedCmd->execCmd();
            }
            $marge = (float) $this->getConfiguration('marge_anti_injection', config::byKey('default_marge_anti_injection', 'zendure', 30));
            $limitMin = (float) $this->getConfiguration('limite_min_w', 0);
            $limitMax = (float) $this->getConfiguration('limite_max_w', 1200);
            $target = (int) round(max($limitMin, min($limitMax, $grid + $injected - $marge)));

            // Même zone morte que la boucle rapide côté démon (import_tolerance_pct,
            // cf. regulation/anti_injection.py) -- passé de */5 à */1, ce cron
            // renverrait sinon une commande quasi identique toutes les minutes,
            // redondant avec ce que la boucle rapide vient probablement déjà de
            // faire dans les 15 dernières secondes. Comparé à la télémétrie
            // output_limit actuelle (pas une valeur mémorisée : ce cron PHP n'a
            // pas d'état persistant entre deux exécutions, contrairement au
            // régulateur Python).
            //
            // BUG corrigé le 2026-07-28 (constaté en direct : cron loggant "dans
            // la tolérance -> pas d'action" en boucle pendant une vraie injection
            // soutenue, -150 à -230W) : la zone morte ne doit JAMAIS s'appliquer
            // côté injection (grid < marge) -- uniquement côté import (grid >=
            // marge), exactement comme dans regulation/anti_injection.py. Le
            // premier jet l'appliquait sans condition de signe.
            $withinTolerance = false;
            if ($grid >= $marge) {
                $currentOutput = (float) $this->getCmdValue('output_limit');
                $tolerancePct = (float) $this->getConfiguration('tolerance_import_anti_injection', config::byKey('default_tolerance_import_anti_injection', 'zendure', 10));
                $tolerance = ($tolerancePct / 100) * abs($currentOutput);
                $withinTolerance = abs($target - $currentOutput) <= $tolerance;
            }

            log::add('zendure', 'info', sprintf(
                '[cronOptimisationHP]%s eq_id=%d grid=%.1fW injected=%.1fW marge=%.1fW -> cible sortie=%dW%s',
                $dryRun ? ' [SIMULATION]' : '',
                $this->getId(), $grid, $injected, $marge, $target,
                $withinTolerance ? ' (import, dans la tolérance -> pas d\'action)' : ''
            ));

            if ($dryRun || $withinTolerance) {
                return;
            }
            $cmd = $this->getCmd(null, 'set_output_limit');
            if (is_object($cmd)) {
                $cmd->execCmd(array('slider' => $target));
            }
            // Même correctif que la boucle rapide côté démon (cf.
            // Device.on_grid_power()) : pousser la valeur commandée directement sur
            // la commande info output_limit plutôt que d'attendre son écho
            // télémétrie (non fiable, cf. README "Points ouverts") -- sinon le
            // curseur "Limite sortie AC" du widget reste figé après une action du
            // cron HP.
            $outputInfoCmd = $this->getCmd(null, 'output_limit');
            if (is_object($outputInfoCmd)) {
                $outputInfoCmd->event($target);
            }
        } else {
            log::add('zendure', 'info', sprintf(
                '[cronOptimisationHP] eq_id=%d HC avérée (tarif HP/HC configuré) -> pas d\'anti-injection, nuit laissée à la stratégie de charge',
                $this->getId()
            ));
        }
    }

    public static function cronStrategieNuit()
    {
        foreach (self::byType('zendure', true) as $eqLogic) {
            /* @var zendure $eqLogic */
            if (!$eqLogic->getIsEnable()) {
                continue;
            }
            $eqLogic->runStrategieNuit();
        }
    }

    /**
     * Décide le SOC cible de charge nocturne (00h-06h HC) selon Tempo demain +
     * prévision solaire du jour à venir. Deux logiques disponibles, cf.
     * kwhSocTarget()/legacySocTarget() : le modèle kWh (Phase 1 de
     * docs/brief_strategie_charge.md) si batterie_capacite_kwh est renseignée et
     * l'historique assez profond, sinon repli sur le portage direct de l'ancien
     * scénario Jeedom de référence (seuils fixes 100/60/80%).
     *
     * Différences volontaires par rapport à l'original, valables dans les deux
     * logiques :
     * - utilise set_soc_max (SOC maximum/cible de charge, propriété socSet)
     *   et non set_soc_min : l'ancien scénario pilotait déjà ce qui est
     *   fonctionnellement le PLAFOND de charge, mais avant que la distinction
     *   min/max ne soit comprise sur ce device (cf. retour utilisateur
     *   2026-07-13 comparant avec les curseurs HA) elle passait par la seule
     *   commande "SOC" alors disponible.
     * - résout la prévision via resolveForecastKwh() (cf. juste en dessous),
     *   qui compense le décalage de cache d'une source externe à
     *   rafraîchissement matinal (ex. Solcast, ~6h) plutôt que de lire "J+0"
     *   en aveugle.
     * Volontairement en simulation par défaut (config strategie_nuit_dry_run,
     * coché), comme cronOptimisationHP : logue la décision sans jamais
     * toucher à l'appareil tant que la fonctionnalité n'a pas été validée en
     * conditions réelles.
     */
    private function runStrategieNuit()
    {
        if (!$this->getConfiguration('strategie_nuit_active', 0)) {
            return;
        }

        $tempoJ1Cmd = $this->resolveSourceCmd('src_tempo_j1');
        $tempoTomorrow = is_object($tempoJ1Cmd) ? strtoupper(trim((string) $tempoJ1Cmd->execCmd())) : '';
        $forecastKwh = $this->resolveForecastKwh();
        $capacityKwh = (float) $this->getConfiguration('batterie_capacite_kwh', 0);

        if ($capacityKwh > 0) {
            list($socTarget, $reason) = $this->kwhSocTarget($tempoTomorrow, $forecastKwh, $capacityKwh);
        } else {
            list($socTarget, $reason) = $this->legacySocTarget($tempoTomorrow, $forecastKwh);
        }

        $dryRun = (bool) $this->getConfiguration('strategie_nuit_dry_run', 1);
        log::add('zendure', 'info', sprintf(
            '[cronStrategieNuit]%s eq_id=%d tempoDemain=%s prevision=%s -> SOC cible=%d%% (%s)',
            $dryRun ? ' [SIMULATION]' : '',
            $this->getId(),
            $tempoTomorrow !== '' ? $tempoTomorrow : '?',
            $forecastKwh !== null ? number_format($forecastKwh, 1) . 'kWh' : '?',
            $socTarget,
            $reason
        ));

        if ($dryRun) {
            return;
        }

        $modeCmd = $this->getCmd(null, 'set_mode');
        if (is_object($modeCmd)) {
            $modeCmd->execCmd(array('select' => 1)); // 1 = input/charge
        }
        // BUG réel corrigé le 2026-07-22 (cf. README "Points ouverts" -- ce qui y
        // était noté "non résolu" n'était pas un problème de payload MQTT) : cette
        // fonction envoyait set_output_limit(0), c'est-à-dire l'automation
        // DÉCHARGE (autoModelProgram=2, cf. mqtt_transport.py) réglée sur 0W --
        // jamais l'automation CHARGE (autoModelProgram=1). Charge et décharge sont
        // deux programmes d'automation mutuellement exclusifs côté appareil (un
        // seul actif à la fois, cf. Hyper2000.charge/discharge dans zendure_ha) :
        // sans jamais envoyer le programme charge, l'appareil restait sur son
        // dernier programme réel (décharge à 0W) quoi que dise la propriété
        // acMode -- d'où "acMode=1 coupe tous les flux sans jamais démarrer la
        // charge", observé en test réel. La cible est en W AC (pas la limite
        // solaire, distincte, cf. commande info "input_limit").
        $chargePowerW = (float) $this->getConfiguration('charge_power_nuit_w', 1200);
        $inLimitCmd = $this->getCmd(null, 'set_input_limit');
        if (is_object($inLimitCmd)) {
            $inLimitCmd->execCmd(array('slider' => $chargePowerW));
        }
        $socMaxCmd = $this->getCmd(null, 'set_soc_max');
        if (is_object($socMaxCmd)) {
            $socMaxCmd->execCmd(array('slider' => $socTarget));
        }
    }

    /**
     * Ancienne logique à seuils fixes (v1, cf. docs/brief_strategie_charge.md) --
     * conservée comme repli quand la capacité batterie n'est pas renseignée
     * (batterie_capacite_kwh vide) ou que le modèle kWh n'a pas encore assez
     * d'historique exploitable (cf. kwhSocTarget()).
     */
    private function legacySocTarget($tempoTomorrow, $forecastKwh)
    {
        if (strpos($tempoTomorrow, 'ROUG') !== false || $tempoTomorrow === 'RED') {
            return array(100, 'Tempo demain = Rouge -> charge max');
        }
        if ((strpos($tempoTomorrow, 'BLEU') !== false || $tempoTomorrow === 'BLUE') && $forecastKwh !== null && $forecastKwh >= 4.0) {
            return array(60, 'Tempo demain = Bleu + prévision solaire >= 4kWh -> laisser de la place au solaire');
        }
        return array(80, 'cas standard');
    }

    /**
     * Heure de réveil HP (fin de la charge nuit) : réglage explicite
     * (strategie_nuit_fenetre_matin_h) si présent > heure_fin_charge_nuit
     * (même onglet, réutilisée comme proxy) > repli 6h (observé en conditions
     * réelles le 2026-07-27 : réveil HP à 06h05 sur cette installation, cf.
     * docs/brief_strategie_charge.md).
     */
    private function resolveMorningWindowEndH()
    {
        $configured = (int) $this->getConfiguration('strategie_nuit_fenetre_matin_h', 0);
        if ($configured > 0) {
            return $configured;
        }
        $heureFin = trim((string) $this->getConfiguration('heure_fin_charge_nuit', ''));
        if (preg_match('/^(\d{1,2})/', $heureFin, $m)) {
            return max(1, (int) $m[1]);
        }
        return 6;
    }

    /**
     * Heure de retour en HC le soir (fin de la fenêtre HP) : réglage explicite
     * (heure_debut_hc_soir) si présent, sinon repli 22h (horaire standard de la
     * grande majorité des offres Tempo/HP-HC françaises).
     */
    private function resolveEveningHcStartH()
    {
        $heureDebut = trim((string) $this->getConfiguration('heure_debut_hc_soir', ''));
        if (preg_match('/^(\d{1,2})/', $heureDebut, $m)) {
            return max(1, min(24, (int) $m[1]));
        }
        return 22;
    }

    /**
     * Fenêtre à couvrir par le modèle kWh : la fenêtre HP (heure de réveil ->
     * retour HC du soir) si un tarif HP/HC ou Tempo est configuré -- c'est la
     * seule fenêtre où stocker de l'énergie HC de la nuit a un intérêt
     * économique, puisque la conso pendant la fenêtre HC elle-même coûte déjà
     * le même prix qu'elle vienne du réseau ou de la batterie. Repli sur la
     * journée entière (0h-24h) si aucun tarif HP/HC n'est configuré (contrat
     * Base) -- pas de fenêtre "chère" identifiable, mais l'idée de ne pas
     * charger plus que nécessaire une fois le solaire de demain déduit reste
     * pertinente même sans différence de tarif (cf. kwhSocTarget()). Garde le
     * plugin universel, cf. README.
     */
    private function resolveHpWindow()
    {
        $type = $this->getConfiguration('type_contrat', 'base');
        if ($type !== 'hphc' && $type !== 'tempo') {
            return array(0, 24);
        }
        return array($this->resolveMorningWindowEndH(), $this->resolveEveningHcStartH());
    }

    /**
     * Modèle "Phase 1" du brief stratégie de charge nuit : SOC cible calculé en
     * kWh réels plutôt qu'en seuils fixes arbitraires.
     *
     * - Tempo Rouge demain reste un cas à part (charge max) : le tarif Rouge
     *   HP est si élevé qu'un pari perdu sur la prévision solaire coûterait
     *   cher -- on préfère se couvrir plutôt qu'optimiser finement ce jour-là.
     * - Sinon, l'idée centrale (remontée par l'utilisateur le 2026-07-27) :
     *   un électron solaire ne coûte rien, un électron HC stocké la nuit a un
     *   coût (même faible). La priorité n'est donc pas de remplir la batterie
     *   en HC, mais de laisser le solaire de demain couvrir un maximum de la
     *   conso -- la batterie ne doit combler que ce que le solaire ne
     *   couvrira pas. D'où : cible = (conso HP typique du foyer - prévision
     *   solaire du lendemain), jamais négative, ramenée en % de la capacité.
     *   Remplace l'ancien mécanisme plancher/plafond + compromis 50/50 (gardé
     *   dans l'historique git si besoin de comparer) : celui-ci imposait le
     *   plancher de conso dès qu'il dépassait le plafond solaire, rendant la
     *   réserve solaire inopérante sur les installations où la batterie est
     *   petite face à la conso -- exactement le problème signalé.
     * - La conso HP typique est une médiane glissante sur strategie_nuit_hist_jours
     *   jours d'historique réel (cf. estimateWindowConsumptionKwh()), sur la
     *   fenêtre HP réelle (resolveHpWindow()) plutôt que sur toute la journée,
     *   pour ne pas gonfler le besoin avec de la conso déjà au tarif HC.
     * - Pas de prévision solaire disponible : traitée comme 0kWh (aucun crédit
     *   solaire), la cible retombe alors sur la conso HP seule -- cohérent
     *   avec le principe "on charge ce que le solaire ne couvre pas".
     * - Retombe sur legacySocTarget() si l'historique est encore insuffisant
     *   (installation trop récente, sources pas encore configurées) : jamais de
     *   cible calculée sur une médiane à zéro échantillon.
     */
    private function kwhSocTarget($tempoTomorrow, $forecastKwh, $capacityKwh)
    {
        if (strpos($tempoTomorrow, 'ROUG') !== false || $tempoTomorrow === 'RED') {
            return array(100, 'Tempo demain = Rouge -> charge max');
        }

        $days = (int) $this->getConfiguration('strategie_nuit_hist_jours', 7);
        list($startH, $endH) = $this->resolveHpWindow();
        $hpConsumptionKwh = $this->estimateWindowConsumptionKwh($startH, $endH, $days);
        if ($hpConsumptionKwh === null) {
            list($legacyTarget, $legacyReason) = $this->legacySocTarget($tempoTomorrow, $forecastKwh);
            return array($legacyTarget, 'historique encore insuffisant pour le modèle kWh (' . $days . 'j visés) -> repli ancienne logique : ' . $legacyReason);
        }

        $forecastKwhSafe = $forecastKwh !== null ? $forecastKwh : 0.0;
        $netNeedKwh = max(0, $hpConsumptionKwh - $forecastKwhSafe);
        $socTarget = (int) ceil(($netNeedKwh / $capacityKwh) * 100);
        $socTarget = max(20, min(100, $socTarget));

        $reason = sprintf(
            'modèle kWh : conso HP %02dh-%02dh ~%.1fkWh (médiane %dj) - prévision solaire %s -> besoin net %.1fkWh / capacité %.1fkWh -> cible %d%%',
            $startH,
            $endH,
            $hpConsumptionKwh,
            $days,
            $forecastKwh !== null ? number_format($forecastKwh, 1) . 'kWh' : '?kWh (aucune)',
            $netNeedKwh,
            $capacityKwh,
            $socTarget
        );
        return array($socTarget, $reason);
    }

    /**
     * Estime la consommation typique du foyer (kWh) sur la fenêtre horaire
     * [$startH, $endH[ (même jour, $startH < $endH), médiane glissante sur les
     * $days derniers jours d'historique réel. Même formule que le
     * dashboard (house = grid + injected, cf. toHtmlCondense()),
     * pour rester cohérent avec ce que
     * l'utilisateur voit déjà -- et parce que la puissance réellement tirée du
     * réseau ne suffit pas seule si la batterie a couvert une partie du besoin
     * ce jour-là (elle masquerait alors une partie de la conso réelle).
     *
     * Utilise history::getTemporalAvg() (moyenne pondérée dans le temps, pas une
     * simple moyenne arithmétique des points -- résiste aux échantillons
     * irréguliers). Un jour sans donnée exploitable (getTemporalAvg renvoie -1,
     * ex. jour d'installation, coupure) est simplement ignoré plutôt que compté
     * comme 0, pour ne pas tirer la moyenne vers le bas artificiellement.
     *
     * Médiane des jours plutôt que moyenne arithmétique : constaté en conditions
     * réelles le 2026-07-27 sur cette installation, une seule journée avec une
     * grosse conso ponctuelle (ex. charge VE) suffit à tirer la moyenne de
     * +30-40%, faussant la cible de tous les jours suivants alors que ce n'est
     * pas représentatif. La médiane isole ce genre de jour atypique sans avoir
     * besoin de le détecter explicitement (pas de source "charge VE active"
     * disponible sur cette install, cf. section Phase 2 du brief).
     *
     * Retourne null si aucun jour n'a de donnée exploitable : l'appelant doit
     * alors retomber sur legacySocTarget() plutôt que de calculer une cible sur
     * une conso à 0 kWh (charge minimale garantie alors que rien ne le prouve).
     */
    private function estimateWindowConsumptionKwh($startH, $endH, $days)
    {
        $gridCmd = $this->resolveSourceCmdOrDefault('src_grid_papp', 'grid_power');
        $injectedCmd = $this->resolveSourceCmdOrDefault('src_injection', 'injected_power');
        if (!is_object($gridCmd)) {
            return null;
        }

        $windowHours = max(0.1, $endH - $startH);
        $samplesKwh = array();
        for ($d = 1; $d <= $days; $d++) {
            $dayStart = date('Y-m-d 00:00:00', strtotime('-' . $d . ' day'));
            $start = date('Y-m-d H:i:s', strtotime($dayStart) + $startH * 3600);
            $end = date('Y-m-d H:i:s', strtotime($dayStart) + $endH * 3600);

            $gridAvgW = history::getTemporalAvg($gridCmd->getId(), $start, $end);
            if ($gridAvgW == -1) {
                continue;
            }
            $injectedAvgW = 0;
            if (is_object($injectedCmd)) {
                $injectedAvgWRaw = history::getTemporalAvg($injectedCmd->getId(), $start, $end);
                if ($injectedAvgWRaw != -1) {
                    $injectedAvgW = $injectedAvgWRaw;
                }
            }
            $houseAvgW = max(0, $gridAvgW + abs($injectedAvgW));
            $samplesKwh[] = $houseAvgW * $windowHours / 1000;
        }

        if (count($samplesKwh) === 0) {
            return null;
        }
        sort($samplesKwh);
        $n = count($samplesKwh);
        $mid = (int) floor(($n - 1) / 2);
        if ($n % 2 === 1) {
            return $samplesKwh[$mid];
        }
        return ($samplesKwh[$mid] + $samplesKwh[$mid + 1]) / 2;
    }

    /**
     * Résout la prévision solaire "du jour à venir" en kWh, en compensant le
     * décalage de cache d'une source externe à rafraîchissement matinal (ex.
     * Solcast, ~6h) : si la commande J+0 n'a pas encore été recollectée
     * aujourd'hui (cron à 00h00, donc avant ce rafraîchissement), son
     * étiquetage -- et celui de J+1 -- reste ancré sur le dernier
     * rafraîchissement (hier ~6h) ; ce qui était alors "J+1" correspond donc
     * au jour calendaire actuel, pas "J+0" (signalé par l'utilisateur).
     * Bascule automatique sur J+1 dans ce cas, sans dépendre d'une heure de
     * rafraîchissement figée en dur (peut varier selon la source).
     */
    private function resolveForecastKwh()
    {
        $j0Cmd = $this->resolveSourceCmd('src_prevision_solaire');
        if (!is_object($j0Cmd)) {
            return null;
        }
        $collectDate = $j0Cmd->getCollectDate(1);
        $refreshedToday = $collectDate != '' && substr($collectDate, 0, 10) === date('Y-m-d');
        $cmd = $j0Cmd;
        if (!$refreshedToday) {
            $j1Cmd = $this->resolveSourceCmd('src_prevision_solaire_j1');
            if (is_object($j1Cmd)) {
                $cmd = $j1Cmd;
            }
        }
        // Source attendue en Wh (cf. Solcast "Prévision J+x", label onglet
        // Sources) -- conversion en kWh pour le seuil de décision ci-dessus.
        return ((float) $cmd->execCmd()) / 1000;
    }

    public static function cronRolloverMinuit()
    {
        foreach (self::byType('zendure', true) as $eqLogic) {
            /* @var zendure $eqLogic */
            $eqLogic->runRolloverMinuit();
        }
    }

    /**
     * Bascule jour -> veille à minuit (reprise du scénario "Zendure_PTEC_Minuit") :
     * gain_jour/depense_jour n'ont de sens qu'à l'échelle d'une journée, veille
     * n'est qu'un instantané figé du dernier total jour avant remise à 0.
     */
    private function runRolloverMinuit()
    {
        $rollovers = array(
            'gain_solaire_jour' => 'gain_solaire_veille',
            'gain_batterie_jour' => 'gain_batterie_veille',
        );
        // depense_jour/veille exclus si une source externe est configurée (ex.
        // Teleinfo) : elle gère déjà sa propre bascule jour -> veille à son propre
        // rythme -- inutile, et potentiellement trompeur, de réinitialiser nos
        // commandes internes que plus personne ne lit dans ce cas (cf.
        // accumulateEuro()).
        if (!is_object($this->resolveSourceCmd('src_depense_jour'))) {
            $rollovers['depense_jour'] = 'depense_veille';
        }
        foreach ($rollovers as $jourId => $veilleId) {
            $jourValue = (float) $this->getCmdValue($jourId);
            $veilleCmd = $this->getCmd(null, $veilleId);
            $jourCmd = $this->getCmd(null, $jourId);
            if (is_object($veilleCmd)) {
                $veilleCmd->event($jourValue);
            }
            if (is_object($jourCmd)) {
                $jourCmd->event(0);
            }
        }
        $this->recomputeGainTotal('jour');
        $this->recomputeGainTotal('veille');
        log::add('zendure', 'info', 'cronRolloverMinuit eq_id=' . $this->getId() . ' : bascule jour -> veille effectuée');
    }

    public static function cronRecupererTarifs()
    {
        foreach (self::byType('zendure', true) as $eqLogic) {
            /* @var zendure $eqLogic */
            if (!$eqLogic->getConfiguration('maj_tarifs_auto', 0)) {
                continue;
            }
            $eqLogic->runRecupererTarifs();
        }
    }

    /**
     * Récupération mensuelle des tarifs auprès d'open-dpe.fr, seule source retenue
     * pour les 3 types de contrat (Base/HP-HC/Tempo en un seul JSON) — bémol de
     * fiabilité assumé (pipeline PDF->LLM mensuel côté source). Échec réseau/format
     * inattendu -> log warning, prix existants inchangés (jamais de crash, jamais
     * de prix vidé) : ces champs restent éditables à la main dans tous les cas.
     */
    private function runRecupererTarifs()
    {
        try {
            $this->fetchTarifsOpenDpe();
        } catch (Exception $e) {
            log::add('zendure', 'warning', 'runRecupererTarifs eq_id=' . $this->getId() . ' : ' . $e->getMessage());
        }
    }

    private function fetchTarifsOpenDpe()
    {
        $json = self::httpGetJson('https://open-dpe.fr/api/v1/electricity.php?tarif=EDF_bleu');
        $options = $json['options'] ?? null;
        if (!is_array($options)) {
            throw new Exception('réponse open-dpe.fr inattendue (pas de clé "options") : ' . json_encode($json));
        }

        $type = $this->getConfiguration('type_contrat', 'base');
        $updated = false;

        if ($type == 'base') {
            if ($this->applyTarif('tarif_base', $options['base']['prix_kWh'] ?? null)) {
                $updated = true;
            }
        } elseif ($type == 'hphc') {
            if ($this->applyTarif('tarif_hphc_hc', $options['heures_creuses']['prix_kWh']['HC'] ?? null)) {
                $updated = true;
            }
            if ($this->applyTarif('tarif_hphc_hp', $options['heures_creuses']['prix_kWh']['HP'] ?? null)) {
                $updated = true;
            }
        } else {
            // Nommage tempo non confirmé en conditions réelles à date (seuls
            // options.base/options.heures_creuses l'ont été côté open-dpe.fr) : à
            // valider dès la première exécution réelle du cron, échoue proprement
            // (warning + tarifs inchangés) si la structure diffère.
            $tempo = $options['tempo']['prix_kWh'] ?? null;
            if (!is_array($tempo)) {
                throw new Exception('pas de clé "options.tempo.prix_kWh" pour un contrat Tempo');
            }
            foreach (array('bleu', 'blanc', 'rouge') as $couleur) {
                if ($this->applyTarif('tarif_tempo_' . $couleur . '_hc', $tempo[$couleur]['HC'] ?? null)) {
                    $updated = true;
                }
                if ($this->applyTarif('tarif_tempo_' . $couleur . '_hp', $tempo[$couleur]['HP'] ?? null)) {
                    $updated = true;
                }
            }
        }

        if ($updated) {
            $this->save(true);
            log::add('zendure', 'info', 'runRecupererTarifs eq_id=' . $this->getId() . ' : tarifs mis à jour depuis open-dpe.fr');
        }
    }

    private function applyTarif($configKey, $value)
    {
        if (!is_numeric($value)) {
            log::add('zendure', 'warning', 'runRecupererTarifs eq_id=' . $this->getId() . ' : champ ' . $configKey . ' absent/non numérique dans la réponse open-dpe.fr');
            return false;
        }
        $this->setConfiguration($configKey, (float) $value);
        return true;
    }

    /**
     * Client HTTP minimal pour le cron de récupération de tarifs (curl brut, pas de
     * helper HTTP dédié utilisé ailleurs dans ce plugin — cf. sendToDaemon() pour le
     * seul autre client réseau du plugin). Timeout court : un cron mensuel ne doit
     * jamais bloquer le scheduler Jeedom en cas d'API indisponible.
     */
    private static function httpGetJson($url)
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new Exception('requête ' . $url . ' échouée : ' . $error);
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            throw new Exception('requête ' . $url . ' -> HTTP ' . $httpCode);
        }
        $json = json_decode($body, true);
        if (!is_array($json)) {
            throw new Exception('réponse ' . $url . ' non JSON : ' . substr($body, 0, 200));
        }
        return $json;
    }

    /**
     * Lecture des N dernières lignes d'un fichier de log, pour le panneau debug
     * du widget Flux (core/ajax/zendure.ajax.php, action tailLogs). Ne lit que
     * les derniers 64 Ko du fichier plutôt que le fichier entier -- ces logs
     * (surtout celui du démon, niveau debug) peuvent grossir vite, pas question
     * de tout charger en mémoire à chaque poll du widget (~ toutes les 3s).
     * Repli en clair si un fichier fait moins de 64 Ko ou n'existe pas encore.
     */
    public static function tailLogFile($path, $lines)
    {
        if (!is_file($path) || !is_readable($path)) {
            return array();
        }
        $size = filesize($path);
        $chunk = 65536;
        $fh = fopen($path, 'r');
        if ($fh === false) {
            return array();
        }
        fseek($fh, max(0, $size - $chunk));
        $content = stream_get_contents($fh);
        fclose($fh);

        $all = preg_split('/\r\n|\r|\n/', trim((string) $content));
        return array_slice($all, -$lines);
    }

    public function preRemove()
    {
        $this->removeTelemetryListener('onGridPowerEvent');
        $this->removeTelemetryListener('onSolarPowerEvent');
        $this->removeTelemetryListener('onInjectedPowerEvent');
    }

    public function postRemove()
    {
        self::writeDaemonConfig();
        $this->reloadDaemonConfig();
    }

    /**
     * Crée/complète les commandes info/action/calculées définies en haut de fichier.
     * Idempotent : rejoué à chaque save sans dupliquer (createCommandIfNotExist).
     */
    private function createOrUpdateCommands()
    {
        foreach (self::INFO_COMMANDS as $logicalId => $def) {
            $cmd = $this->getCmd(null, $logicalId);
            if (!is_object($cmd)) {
                $cmd = new zendureCmd();
                $cmd->setLogicalId($logicalId);
                $cmd->setEqLogic_id($this->getId());
                $cmd->setType('info');
            }
            $cmd->setName($def[0]);
            $cmd->setSubType($def[1]);
            $cmd->setUnite($def[2]);
            $cmd->setIsHistorized(1);
            $cmd->save();
        }

        foreach (self::COMPUTED_COMMANDS as $logicalId => $def) {
            $cmd = $this->getCmd(null, $logicalId);
            if (!is_object($cmd)) {
                $cmd = new zendureCmd();
                $cmd->setLogicalId($logicalId);
                $cmd->setEqLogic_id($this->getId());
                $cmd->setType('info');
            }
            $cmd->setName($def[0]);
            $cmd->setSubType($def[1]);
            $cmd->setUnite($def[2]);
            $cmd->setIsHistorized(1);
            $cmd->save();
        }

        foreach (self::ACTION_COMMANDS as $logicalId => $def) {
            $cmd = $this->getCmd(null, $logicalId);
            if (!is_object($cmd)) {
                $cmd = new zendureCmd();
                $cmd->setLogicalId($logicalId);
                $cmd->setEqLogic_id($this->getId());
                $cmd->setType('action');
            }
            $cmd->setName($def[0]);
            $cmd->setSubType($def[1]);
            $cmd->save();
        }

        // Toutes les commandes ci-dessus (info/action/calculées, + celles créées
        // à la volée par callback.php pour la télémétrie brute non listée, cf.
        // son commentaire "capture désormais TOUTE la télémétrie") sont la
        // matière première du widget Flux, pas des lectures destinées à être
        // affichées telles quelles sur le dashboard (le losange les recompose).
        // On masque donc tout sauf flux_widget lui-même, plutôt que d'énumérer
        // une liste figée — sinon toute nouvelle clé Zendure découverte à la
        // volée réapparaîtrait en vrac sur le dashboard (constaté : ~80 commandes
        // brutes visibles après ce changement, avant ce correctif). Les
        // commandes des widgets compacts (cf. createOrUpdateCompactWidgets(),
        // appelé juste après) remettent elles-mêmes isVisible=1 à chaque save
        // -- inutile de les exempter ici, elles se rétablissent aussitôt.
        foreach ($this->getCmd() as $cmd) {
            if ($cmd->getLogicalId() == 'flux_widget') {
                continue;
            }
            if ($cmd->getIsVisible() != 0) {
                $cmd->setIsVisible(0);
                $cmd->save();
            }
        }

        $this->createOrUpdateFluxWidget();
        $this->createOrUpdateCompactWidgets();
    }

    /**
     * Test 2026-07-24 : variantes "découpées" du widget Flux (jauge, pastilles
     * Tempo, cartes argent) en widgets de commande INDÉPENDANTS, plaçables
     * séparément sur une page Design -- contrairement au widget Flux complet
     * (losange + animations), qui rend correctement sur le dashboard normal
     * mais casse en placement `type=cmd` sur une page Design (cf. plan id=463,
     * 3 carrés rouges). Objectif : vérifier si des templates plus simples
     * (moins de script, pas d'animation SVG continue) s'en sortent mieux dans
     * ce contexte, avant de généraliser cette approche.
     *
     * Même principe que createOrUpdateFluxWidget() : widget de commande
     * (cmd::setTemplate + display.parameters), jamais un override eqLogic --
     * cf. son commentaire d'en-tête pour la raison (destruction des animations
     * SVG à chaque eqLogic::update).
     */
    private function createOrUpdateCompactWidgets()
    {
        // Jauge (intensité + marge) : héberge le nouveau template sur notre
        // commande interne jauge_intensite_marge -- jusqu'ici un espace
        // réservé jamais alimenté (aucun code n'écrivait sa valeur), on lui
        // donne enfin un rôle : lecture live de la source d'intensité
        // configurée, même mécanisme que cfg.intensiteId du widget Flux.
        // isVisible reste à 0 (défaut) : test 2026-07-24 concluant sur le rendu
        // (templates OK), mais tant qu'aucun placement Design ne les utilise,
        // les rendre visibles duplique gain/dépense en haut du widget standard
        // (parent::toHtml() affiche CHAQUE commande visible séparément, cf.
        // template_dashboard='flux' -> zendure::toHtml() -> parent::toHtml())
        // -- constaté 2026-07-25 : "Dépense jour/veille" figées (commandes
        // internes jamais réalimentées depuis le 14/07) affichées EN DOUBLE
        // au-dessus du losange, par-dessus les vraies valeurs Téléinfo de la
        // ligne 3. Repasser à 1 seulement en même temps qu'un vrai placement
        // Design est recréé pour ces commandes.
        $gaugeCmd = $this->getCmd(null, 'jauge_intensite_marge');
        if (is_object($gaugeCmd)) {
            $gaugeCmd->setTemplate('dashboard', 'zendure::zf_gauge');
            $gaugeCmd->setDisplay('parameters', array(
                'IntensiteId' => self::cmdIdOrZero($this->resolveSourceCmd('src_intensite')),
                'ImaxA' => $this->resolveImaxAmpere(),
            ));
            $gaugeCmd->save();
        }

        // Tempo aujourd'hui/demain : deux commandes hôtes dédiées (pas de
        // recopie de valeur -- lecture live de la source Tempo configurée,
        // même mécanisme). Créées à la volée comme flux_widget lui-même.
        foreach (array(
            'tempo_today_display' => array('Tempo aujourd\'hui (compact)', 'src_tempo_j'),
            'tempo_tomorrow_display' => array('Tempo demain (compact)', 'src_tempo_j1'),
        ) as $logicalId => $conf) {
            list($name, $configKey) = $conf;
            $cmd = $this->getCmd(null, $logicalId);
            if (!is_object($cmd)) {
                $cmd = new zendureCmd();
                $cmd->setLogicalId($logicalId);
                $cmd->setEqLogic_id($this->getId());
                $cmd->setType('info');
                $cmd->setSubType('string');
            }
            $cmd->setName($name);
            $cmd->setIsHistorized(0);
            $cmd->setTemplate('dashboard', 'zendure::zf_tempo_pill');
            $cmd->setDisplay('parameters', array(
                'SourceId' => self::cmdIdOrZero($this->resolveSourceCmd($configKey)),
            ));
            $cmd->save();
        }

        // Badge HPJB/HCJB : même commande hôte dédiée, mêmes deux sources
        // live que le badge intégré au widget Flux (texte période + couleur
        // Tempo du jour) -- cf. createOrUpdateFluxWidget() pour la même
        // logique de résolution "periode_tarif interne si déjà alimentée,
        // sinon src_tempo_now".
        $periodeBadgeCmd = $this->getCmd(null, 'periode_badge_display');
        if (!is_object($periodeBadgeCmd)) {
            $periodeBadgeCmd = new zendureCmd();
            $periodeBadgeCmd->setLogicalId('periode_badge_display');
            $periodeBadgeCmd->setEqLogic_id($this->getId());
            $periodeBadgeCmd->setType('info');
            $periodeBadgeCmd->setSubType('string');
        }
        $periodeInternalCmd = $this->getCmd(null, 'periode_tarif');
        $periodeCmdForBadge = (is_object($periodeInternalCmd) && (string) $periodeInternalCmd->execCmd() !== '')
            ? $periodeInternalCmd
            : $this->resolveSourceCmd('src_tempo_now');
        $periodeBadgeCmd->setName('Période HPJB (compact)');
        $periodeBadgeCmd->setIsHistorized(0);
        // isVisible=0 explicite (comme gauge/tempo ci-dessus) : un nouvel
        // objet cmd est visible=1 par défaut tant qu'on ne dit pas le
        // contraire -- sans ça, la toute première création dupliquerait ce
        // badge sur le dashboard normal avant que quoi que ce soit d'autre
        // n'ait eu la main pour le masquer (constaté avec gain/dépense/jauge
        // plus tôt dans cette même investigation).
        $periodeBadgeCmd->setIsVisible(0);
        $periodeBadgeCmd->setTemplate('dashboard', 'zendure::zf_periode_badge');
        $periodeBadgeCmd->setDisplay('parameters', array(
            'PeriodeId' => self::cmdIdOrZero($periodeCmdForBadge),
            'TempoTodayId' => self::cmdIdOrZero($this->resolveSourceCmd('src_tempo_j')),
        ));
        $periodeBadgeCmd->save();

        // Les 3 cartes "argent" (widget Flux, ligne 3) : commandes déjà
        // existantes et déjà alimentées (moteur gain/dépense, cf.
        // accumulateEuro()) -- on ne fait qu'y attacher le même habillage
        // visuel que le widget Flux, aucune nouvelle commande.
        foreach (array(
            'gain_jour' => array('Gain Zendure · jour', 'fas fa-coins', true),
            'depense_veille' => array('Dépense veille', 'fas fa-calendar-minus', false),
            'depense_jour' => array('Dépense jour', 'fas fa-calendar-day', false),
        ) as $logicalId => $conf) {
            list($label, $icon, $isGain) = $conf;
            $cmd = $this->getCmd(null, $logicalId);
            if (!is_object($cmd)) {
                continue;
            }
            $cmd->setTemplate('dashboard', 'zendure::zf_money');
            $cmd->setDisplay('parameters', array(
                'Label' => $label,
                'Icon' => $icon,
                'IsGain' => $isGain ? 'true' : 'false',
                'IsGainClass' => $isGain ? 'zf-mini-gain' : '',
            ));
            $cmd->save();
        }
    }

    /**
     * Commande hôte du widget "Flux" (addendum §14.3, refonte animation) : un
     * widget au niveau COMMANDE (customTemplate zendure::flux_widget), pas un
     * override eqLogic::toHtml() — cf. investigation du widget natif
     * "Energy_info"/distribution_energy.html. Raison du changement : Jeedom
     * remplace intégralement le HTML d'un widget eqLogic à chaque
     * eqLogic::update (jeedom.eqLogic.refreshValue -> eqLogic.empty().append),
     * ce qui fige/relance toute animation SVG en cours. Un widget de commande
     * n'est jamais redessiné : seules des jeedom.cmd.addUpdateFunction()
     * poussent les nouvelles valeurs, en place, dans le DOM existant (cf.
     * jeedom.cmd.refreshValue). D'où : une seule commande visible ("flux_widget"),
     * toutes les autres masquées ci-dessus, et les ids des commandes "pilotes"
     * (celles dont la valeur doit rafraîchir le losange en direct) transmis en
     * paramètres de widget (cmd.display.parameters -> tokens #Xxx# natifs,
     * cf. cmd::toHtml() core) plutôt que recalculés côté PHP à chaque rendu.
     */
    private function createOrUpdateFluxWidget()
    {
        $cmd = $this->getCmd(null, 'flux_widget');
        if (!is_object($cmd)) {
            $cmd = new zendureCmd();
            $cmd->setLogicalId('flux_widget');
            $cmd->setEqLogic_id($this->getId());
            $cmd->setType('info');
        }
        $cmd->setName('Flux');
        $cmd->setSubType('string');
        $cmd->setIsHistorized(0);
        $cmd->setIsVisible(1);
        $cmd->setOrder(0);
        $cmd->setTemplate('dashboard', 'zendure::flux_widget');
        $cmd->setTemplate('mobile', 'core::default');

        $solarCmd = $this->resolveSourceCmdOrDefault('src_solaire', 'solar_power');
        $gridCmd = $this->resolveSourceCmdOrDefault('src_grid_papp', 'grid_power');
        $injectedCmd = $this->resolveSourceCmdOrDefault('src_injection', 'injected_power');
        $intensiteCmd = $this->resolveSourceCmd('src_intensite');

        // "Période" reprend la même priorité que toHtmlFlux() historique :
        // la commande interne periode_tarif si elle a déjà une valeur, sinon
        // la source Tempo externe configurée (src_tempo_now).
        $periodeInternalCmd = $this->getCmd(null, 'periode_tarif');
        $periodeCmd = (is_object($periodeInternalCmd) && (string) $periodeInternalCmd->execCmd() !== '')
            ? $periodeInternalCmd
            : $this->resolveSourceCmd('src_tempo_now');

        $setOutputLimitCmd = $this->getCmd(null, 'set_output_limit');
        $setInputLimitCmd = $this->getCmd(null, 'set_input_limit');
        $setSocMinCmd = $this->getCmd(null, 'set_soc_min');
        $setSocMaxCmd = $this->getCmd(null, 'set_soc_max');
        $setModeCmd = $this->getCmd(null, 'set_mode');

        $parameters = array(
            'SolarId' => self::cmdIdOrZero($solarCmd),
            'GridId' => self::cmdIdOrZero($gridCmd),
            'InjectedId' => self::cmdIdOrZero($injectedCmd),
            // Flux batterie réel (cercle "Batterie" du losange uniquement,
            // PAS "Maison" -- cf. commentaire détaillé dans le <script> du
            // template flux_widget.html) : deux clés brutes Zendure séparées,
            // jamais aliasées (cf. telemetry_map.py), lues directement par
            // logicalId -- outputPackPower = puissance VERS le pack (charge),
            // packInputPower = puissance DEPUIS le pack (décharge). Corrige
            // le bug signalé 2026-07-25 (sens des billes toujours "décharge",
            // jamais "charge" -- injected_power/outputHomePower ne peut
            // structurellement pas coder ce sens, il n'est jamais négatif).
            'PackChargeId' => self::cmdIdOrZero($this->getCmd(null, 'outputPackPower')),
            'PackDischargeId' => self::cmdIdOrZero($this->getCmd(null, 'packInputPower')),
            'IntensiteId' => self::cmdIdOrZero($intensiteCmd),
            'SocId' => self::cmdIdOrZero($this->getCmd(null, 'soc')),
            'ModeId' => self::cmdIdOrZero($this->getCmd(null, 'mode')),
            'GainJourId' => self::cmdIdOrZero($this->getCmd(null, 'gain_jour')),
            // src_depense_jour/veille (ex. Teleinfo STAT_TODAY_INDEX00_COUT /
            // STAT_YESTERDAY_INDEX00_COUT) : le widget lit directement la source
            // externe si configurée -- même mécanisme que Solaire/Réseau/Injection
            // ci-dessus, pas de recopie intermédiaire. Retombe sur nos commandes
            // internes (calcul par intégration, cf. accumulateEuro()) sinon.
            'DepenseVeilleId' => self::cmdIdOrZero($this->resolveSourceCmdOrDefault('src_depense_veille', 'depense_veille')),
            'DepenseJourId' => self::cmdIdOrZero($this->resolveSourceCmdOrDefault('src_depense_jour', 'depense_jour')),
            'PeriodeId' => self::cmdIdOrZero($periodeCmd),
            'TempoTodayId' => self::cmdIdOrZero($this->resolveSourceCmd('src_tempo_j')),
            'TempoTomorrowId' => self::cmdIdOrZero($this->resolveSourceCmd('src_tempo_j1')),
            'OutputLimitId' => self::cmdIdOrZero($this->getCmd(null, 'output_limit')),
            'InputLimitId' => self::cmdIdOrZero($this->getCmd(null, 'input_limit')),
            // MinSocRawId/SocMaxRawId : PAS les commandes action set_soc_min/set_soc_max
            // elles-mêmes -- jeedom.cmd.execute() sur une commande de type "action" la
            // DÉCLENCHE (même sans valeur), il ne fait pas qu'en lire la valeur (signalé :
            // curseurs qui redéclenchaient l'action en boucle avec une valeur vide dès le
            // chargement du widget, cf. warning démon "valeur invalide... : None" répété).
            // minSoc/socSet sont les commandes "info" brutes créées à la volée par
            // callback.php à partir de la télémétrie réelle du boîtier (cf.
            // resources/zendure_daemon/telemetry_map.py, pas d'alias curé pour celles-ci) :
            // lecture sûre, ET reflète la vraie valeur actuelle du boîtier (pas seulement
            // la dernière commande envoyée par ce widget). Échelle x10 côté device
            // (confirmé : minSoc=400 bru => 40% affiché côté app/HA) -- division par 10
            // faite en JS à l'affichage.
            'MinSocRawId' => self::cmdIdOrZero($this->getCmd(null, 'minSoc')),
            'SocMaxRawId' => self::cmdIdOrZero($this->getCmd(null, 'socSet')),
            'SetOutputLimitId' => self::cmdIdOrZero($setOutputLimitCmd),
            'SetInputLimitId' => self::cmdIdOrZero($setInputLimitCmd),
            'SetSocMinId' => self::cmdIdOrZero($setSocMinCmd),
            'SetSocMaxId' => self::cmdIdOrZero($setSocMaxCmd),
            'SetModeActionId' => self::cmdIdOrZero($setModeCmd),
            'ImaxA' => $this->resolveImaxAmpere(),
            'OutputLimitMaxW' => (float) $this->getConfiguration('limite_max_w', 1200),
            'InputLimitMaxW' => (float) $this->getConfiguration('limite_entree_max_w', 1200),
            'FlowThresholdW' => 5,
            'AnimationsOnClass' => $this->getConfiguration('animations_actives', 1) ? 'zc-animated' : '',
            // Détection "hors ligne" côté widget (JS, cf. commentaire d'en-tête du
            // template) : le démon republie chaque clé de télémétrie au moins
            // toutes les telemetry_min_interval_s secondes même sans changement
            // (heartbeat, cf. TelemetryThrottle côté Python) -- un widget qui n'a
            // reçu AUCUNE poussée de commande depuis plus que ce délai (marge
            // incluse) signale donc une vraie coupure démon/MQTT, pas juste un
            // flux stable.
            'HeartbeatS' => (float) $this->getConfiguration('telemetry_min_interval_s', 300),
            // Pour l'appel AJAX lastSeen (cf. core/ajax/zendure.ajax.php) qui corrige
            // la base de temps du watchdog au chargement de la page -- resolveSourceCmd
            // /getCmd() n'exposent que des ids de COMMANDE, il faut l'id de l'eqLogic
            // lui-même pour cet appel-là.
            'EqLogicId' => $this->getId(),
            // Panneau debug (cf. desktop/php/zendure.php, onglet IHM) : classe CSS
            // conditionnelle plutôt qu'un param booléen brut, même convention que
            // AnimationsOnClass ci-dessus -- le panneau existe toujours dans le DOM
            // (pas de aller-retour serveur pour l'afficher/masquer), juste montré/
            // caché en CSS selon que l'utilisateur a coché l'option.
            'DebugOnClass' => $this->getConfiguration('debug_widget_actif', 0) ? 'zf-debug-on' : '',
        );
        $cmd->setDisplay('parameters', $parameters);
        $cmd->save();
    }

    /**
     * Enregistre un listener core sur une commande source de télémétrie (grid/solaire/
     * injection, cf. addendum §11 étage 2 pour le cas grid historique). Chaîne réelle
     * (grid) : pince -> listener -> ce callback PHP -> socket démon -> décision -> MQTT
     * (addendum §12) ; (solaire/injection) : télémétrie Zendure (callback.php) ->
     * listener -> accumulation gain (cf. accumulateEuro()).
     *
     * Signature confirmée contre core/class/listener.class.php (VM) et l'usage réel
     * dans plugins/alarm/core/class/alarm.class.php : pas de setClassId/setPluginId/
     * byPluginId (n'existent pas dans le core) — le ciblage de la commande écoutée se
     * fait via addEvent($cmdId), et le filtrage par instance via l'`option` passée à
     * byClassAndFunction().
     *
     * resolveSourceCmdOrDefault() (pas resolveSourceCmd()) : l'onglet Sources invite
     * explicitement à laisser src_solaire/src_injection vides ("valeur par défaut"),
     * le listener doit donc s'accrocher à la commande interne curée dans ce cas plutôt
     * que de ne s'enregistrer sur rien — sinon le moteur gain/dépense resterait
     * silencieusement à 0 tant que l'utilisateur n'a rien configuré.
     */
    private function registerTelemetryListener($configKey, $defaultLogicalId, $eventFunction)
    {
        $cmd = $this->resolveSourceCmdOrDefault($configKey, $defaultLogicalId);
        if (!is_object($cmd)) {
            $this->removeTelemetryListener($eventFunction);
            return;
        }

        $option = array('eq_id' => $this->getId());
        $listener = listener::byClassAndFunction('zendure', $eventFunction, $option);
        if (!is_object($listener)) {
            $listener = new listener();
        }
        $listener->setClass('zendure');
        $listener->setFunction($eventFunction);
        $listener->setOption($option);
        $listener->emptyEvent();
        $listener->addEvent($cmd->getId());
        $listener->save();
        log::add('zendure', 'debug', 'registerTelemetryListener(' . $eventFunction . ') eq_id=' . $this->getId() . ' -> cmd_id=' . $cmd->getId());
    }

    private function removeTelemetryListener($eventFunction)
    {
        $listener = listener::byClassAndFunction('zendure', $eventFunction, array('eq_id' => $this->getId()));
        if (is_object($listener)) {
            $listener->remove();
        }
    }

    /**
     * Callback statique invoqué par le core lors du déclenchement du listener
     * (changement de valeur de la commande pince). Relaie vers le démon via le
     * socket local — jamais d'accès direct MQTT depuis PHP (brief §9bis) — et
     * alimente en plus le moteur dépense (même événement source, deux effets).
     */
    public static function onGridPowerEvent($_options)
    {
        $eqId = $_options['eq_id'] ?? null;
        $value = $_options['value'] ?? null;
        if ($eqId === null || $value === null) {
            return;
        }
        log::add('zendure', 'debug', 'onGridPowerEvent eq_id=' . $eqId . ' value=' . $value . 'W');
        self::sendToDaemon(array(
            'type' => 'grid_power',
            'eq_id' => (int) $eqId,
            'value_w' => (float) $value,
        ));

        $eqLogic = eqLogic::byId((int) $eqId);
        if (is_object($eqLogic)) {
            // Convention grid_power > 0 = import réseau (normal), < 0 = injection (à
            // éviter) — cf. toHtmlCondense(). La dépense ne compte jamais l'export.
            $eqLogic->accumulateEuro('depense_jour', (float) $value);
        }
    }

    /**
     * Télémétrie Zendure solaire (cf. moteur gain/dépense) : accumulation directe,
     * pas de relais démon (contrairement à onGridPowerEvent, qui pilote aussi
     * l'anti-injection).
     */
    public static function onSolarPowerEvent($_options)
    {
        self::onTelemetryAccumulationEvent($_options, 'gain_solaire_jour');
    }

    /**
     * Télémétrie Zendure injection maison (puissance déchargée par la batterie,
     * cf. moteur gain/dépense) : même principe qu'onSolarPowerEvent.
     */
    public static function onInjectedPowerEvent($_options)
    {
        self::onTelemetryAccumulationEvent($_options, 'gain_batterie_jour');
    }

    private static function onTelemetryAccumulationEvent($_options, $cumulLogicalId)
    {
        $eqId = $_options['eq_id'] ?? null;
        $value = $_options['value'] ?? null;
        if ($eqId === null || $value === null) {
            return;
        }
        $eqLogic = eqLogic::byId((int) $eqId);
        if (is_object($eqLogic)) {
            $eqLogic->accumulateEuro($cumulLogicalId, (float) $value);
        }
    }

    /**
     * Coeur du moteur gain/dépense : intègre une puissance instantanée (W) dans le
     * temps depuis le dernier passage, valorisée au tarif courant, et l'ajoute à la
     * commande cumulative $cumulLogicalId (depense_jour / gain_solaire_jour /
     * gain_batterie_jour). dt est dérivé de collectDate() de la commande cumulative
     * elle-même : pas de nouvel état séparé à maintenir.
     */
    private function accumulateEuro($cumulLogicalId, $valueW)
    {
        // Dépense : s'auto-désactive si une source externe fait déjà ce calcul en
        // mieux (ex. Teleinfo STAT_TODAY_INDEX00_COUT, basé sur les vrais index
        // matériels du compteur -- résilient à toute coupure Jeedom, contrairement
        // à cette intégration de puissance). Ne concerne pas gain_solaire_jour/
        // gain_batterie_jour : pas d'équivalent externe possible, l'autoconsommation
        // ne passe jamais par un compteur (cf. échange avec l'utilisateur).
        if ($cumulLogicalId == 'depense_jour' && is_object($this->resolveSourceCmd('src_depense_jour'))) {
            return;
        }
        $cmd = $this->getCmd(null, $cumulLogicalId);
        if (!is_object($cmd)) {
            return;
        }

        // Verrou nommé MySQL le temps du lire-modifier-écrire ci-dessous : ce
        // callback (déclenché par callback.php, un webhook HTTP appelé par le
        // démon) peut recevoir des requêtes qui se chevauchent (rafale de
        // télémétrie MQTT + éventuel aller-retour BLE) -- constaté 2026-07-26,
        // gain_batterie_jour retombant brutalement de 0.125€ à 0.0000133€ en
        // quelques secondes sans qu'aucun code n'écrive explicitement une
        // valeur plus basse : deux processus PHP concurrents lisant la même
        // ancienne valeur via execCmd() avant que l'un des deux n'ait fini
        // d'écrire (perte de mise à jour classique). GET_LOCK/RELEASE_LOCK
        // sérialise ce bloc par commande (clé = son id, unique), sans
        // toucher à cmd::event() lui-même (trop de logique core dedans --
        // historisation, listeners, alertes -- pour la réimplémenter en SQL
        // brut sans risque). Timeout 5s : mieux vaut sauter un incrément
        // (perte négligeable, quelques centimes max) que bloquer le webhook
        // indéfiniment si un verrou reste posé anormalement longtemps.
        $lockName = 'zendure_accum_' . $cmd->getId();
        $locked = (bool) DB::Prepare('SELECT GET_LOCK(:name, 5)', array('name' => $lockName), DB::FETCH_TYPE_ROW, PDO::FETCH_COLUMN);
        if (!$locked) {
            return;
        }
        try {
            $lastCollect = $cmd->getCollectDate();
            if (empty($lastCollect)) {
                // Premier passage (commande jamais collectée, ou tout juste remise à 0 par
                // le rollover minuit) : pas de dt exploitable, on amorce collectDate() pour
                // le prochain événement plutôt que d'intégrer un dt aberrant.
                $cmd->event((float) $cmd->execCmd());
                return;
            }
            $dt = time() - strtotime($lastCollect);
            if ($dt <= 0) {
                return;
            }

            // Seuil hors-ligne : même facteur x2 que le watchdog JS du widget Flux (cf.
            // cmd.info.string.flux_widget.html, commentaire HeartbeatS) — au-delà, c'est
            // une vraie coupure (démon arrêté, Jeedom redémarré...), pas juste un flux
            // stable ; on n'intègre jamais à l'aveugle sur ce trou.
            $offlineThresholdS = 2 * (float) $this->getConfiguration('telemetry_min_interval_s', 300);
            if ($dt > $offlineThresholdS) {
                $cmd->event((float) $cmd->execCmd());
                return;
            }

            // Ne compte que le sens positif (import réseau / production solaire /
            // décharge batterie), jamais l'export — même convention que l'ancien
            // scénario Jeedom de référence.
            $kwh = max(0, $valueW) * $dt / 3600 / 1000;
            $euros = $kwh * $this->currentTariffEurPerKwh();
            $cmd->event((float) $cmd->execCmd() + $euros);

            if ($cumulLogicalId == 'gain_solaire_jour' || $cumulLogicalId == 'gain_batterie_jour') {
                $this->recomputeGainTotal('jour');
            }
        } finally {
            DB::Prepare('SELECT RELEASE_LOCK(:name)', array('name' => $lockName), DB::FETCH_TYPE_ROW, PDO::FETCH_COLUMN);
        }
    }

    /**
     * gain_jour/gain_veille sont des valeurs DÉRIVÉES (jamais accumulées
     * indépendamment) : recalculées à chaque mise à jour d'un des deux compteurs
     * détaillés, pour ne jamais risquer de drift entre le total affiché sur la
     * tuile et le détail solaire/batterie.
     */
    private function recomputeGainTotal($suffix)
    {
        $solaire = (float) $this->getCmdValue('gain_solaire_' . $suffix);
        $batterie = (float) $this->getCmdValue('gain_batterie_' . $suffix);
        $cmd = $this->getCmd(null, 'gain_' . $suffix);
        if (is_object($cmd)) {
            $cmd->event($solaire + $batterie);
        }
    }

    /**
     * Détecte si on est actuellement en Heures Pleines à partir de la vraie source
     * tarifaire configurée par l'utilisateur (même principe que
     * currentTariffEurPerKwh() ci-dessous) -- réutilisé par runOptimisationHP()
     * pour réveiller l'appareil du mode charge nocturne au bon moment, celui où le
     * fournisseur bascule réellement, pas une heure supposée.
     *
     * Retourne null si aucune source HP/HC n'est configurée (contrat "base", type
     * de contrat non HP/HC, ou source pas encore renseignée) : dans ce cas
     * l'appelant doit se rabattre sur un autre signal (cf. heure_fin_charge_nuit).
     */
    private function isHeuresPleines()
    {
        $type = $this->getConfiguration('type_contrat', 'base');
        if ($type == 'hphc') {
            $sourceKey = 'src_periode_tarif';
        } elseif ($type == 'tempo') {
            $sourceKey = 'src_tempo_now';
        } else {
            return null;
        }
        $cmd = $this->resolveSourceCmd($sourceKey);
        if (!is_object($cmd)) {
            return null;
        }
        $periode = strtoupper((string) $cmd->execCmd());
        if ($periode === '') {
            return null;
        }
        return substr($periode, 0, 2) === 'HP';
    }

    /**
     * Résout le prix courant du kWh (€) selon type_contrat (config, onglet
     * Comportement -> fieldset Tarifs) : source unique consultée par le moteur
     * d'accumulation, qui ne sait pas si un prix vient d'une saisie manuelle ou
     * du cron de récupération mensuelle (cronRecupererTarifs).
     */
    private function currentTariffEurPerKwh()
    {
        $type = $this->getConfiguration('type_contrat', 'base');

        if ($type == 'base') {
            return (float) $this->getConfiguration('tarif_base', 0);
        }

        if ($type == 'hphc') {
            $periode = strtolower((string) $this->getConfiguredSourceValue('src_periode_tarif'));
            if (strpos($periode, 'hc') !== false) {
                return (float) $this->getConfiguration('tarif_hphc_hc', 0);
            }
            if (strpos($periode, 'hp') !== false) {
                return (float) $this->getConfiguration('tarif_hphc_hp', 0);
            }
            log::add('zendure', 'warning', 'currentTariffEurPerKwh(hphc) eq_id=' . $this->getId() . ' : période "' . $periode . '" non reconnue, tarif 0');
            return 0.0;
        }

        // tempo : src_tempo_now attendu au format historique "HCJB"/"HPJR"/... (2
        // premiers caractères HP/HC, 2 suivants JB/JW/JR = Bleu/Blanc/Rouge), cf.
        // scénario Jeedom de référence (id 184 "Zendure_PTEC_Minuit").
        $now = strtoupper((string) $this->getConfiguredSourceValue('src_tempo_now'));
        $hpHc = substr($now, 0, 2);
        $couleurCode = substr($now, 2, 2);
        $couleurs = array('JB' => 'bleu', 'JW' => 'blanc', 'JR' => 'rouge');
        $couleur = $couleurs[$couleurCode] ?? null;
        $periodeKey = $hpHc == 'HP' ? 'hp' : ($hpHc == 'HC' ? 'hc' : null);

        if ($couleur === null || $periodeKey === null) {
            log::add('zendure', 'warning', 'currentTariffEurPerKwh(tempo) eq_id=' . $this->getId() . ' : src_tempo_now "' . $now . '" non reconnu, tarif 0');
            return 0.0;
        }
        return (float) $this->getConfiguration('tarif_tempo_' . $couleur . '_' . $periodeKey, 0);
    }

    public function reloadDaemonConfig()
    {
        self::sendToDaemon(array('type' => 'reload_config'));
    }

    /**
     * Client TCP minimal vers le socket du démon (127.0.0.1:socketport), une ligne JSON.
     * Best-effort : si le démon est arrêté, on log sans lever d'exception (le
     * superviseur de démon Jeedom gère le redémarrage).
     */
    public static function sendToDaemon($message)
    {
        $port = self::socketport();
        $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 1);
        if (!$fp) {
            log::add('zendure', 'warning', 'Démon injoignable sur le port ' . $port . ' (' . $errstr . ')');
            // Alerte utilisateur (pas seulement un log) : sans elle, un démon planté
            // ou pas encore démarré passe inaperçu tant que personne ne va lire les
            // logs -- exactement le type de panne silencieuse signalée le 2026-07-22
            // (perte de connexion découverte a posteriori, pas au moment où ça arrive).
            message::add('zendure', 'Démon Zendure injoignable sur le port ' . $port . ' (' . $errstr . ') -- vérifier qu\'il tourne.', '', 'zendure_alert_daemon_unreachable_' . $port);
            return false;
        }
        fwrite($fp, json_encode($message) . "\n");
        fclose($fp);
        return true;
    }
}

class zendureCmd extends cmd
{
    public function dontRemoveCmd()
    {
        return true;
    }

    /**
     * Les commandes "action" ne parlent jamais directement au transport MQTT :
     * elles sont relayées au démon, seul détenteur de la connexion Cloud/Local
     * (brief §4/§9bis). Cela garde la couche transport unique et testable côté Python.
     */
    public function execute($_options = array())
    {
        /* @var zendure $eqLogic */
        $eqLogic = $this->getEqLogic();
        zendure::sendToDaemon(array(
            'type' => 'action',
            'eq_id' => $eqLogic->getId(),
            'logical_id' => $this->getLogicalId(),
            'value' => $_options['slider'] ?? $_options['select'] ?? null,
        ));
    }
}
