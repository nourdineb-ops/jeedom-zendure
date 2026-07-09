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
    public static $INFO_COMMANDS = array(
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
    public static $ACTION_COMMANDS = array(
        'set_mode' => array('Mode Charge/Décharge', 'other'),
        'set_manual_power' => array('Puissance manuelle', 'slider'),
        'set_output_limit' => array('Limite sortie AC (W)', 'slider'),
        'set_input_limit' => array('Limite entrée AC (W)', 'slider'),
        'set_soc_min' => array('SOC minimum', 'slider'),
    );

    /*
     * Commandes calculées côté plugin (brief §14.2), pas des lectures brutes.
     */
    public static $COMPUTED_COMMANDS = array(
        'gain_jour' => array('Gain Zendure (jour)', 'numeric', '€'),
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
        return $path;
    }

    /*     * *********************Methode d'instance************************* */

    public function toDaemonConfig()
    {
        $antiInjection = array(
            'marge_w' => $this->getConfiguration('marge_anti_injection', 30),
            'cooldown_s' => $this->getConfiguration('cooldown_anti_injection', 2),
            'hysteresis_w' => $this->getConfiguration('hysteresis_anti_injection', 15),
            'limit_min_w' => $this->getConfiguration('limite_min_w', 0),
            'limit_max_w' => $this->getConfiguration('limite_max_w', 1200),
            'urgent_injection_w' => $this->getConfiguration('urgent_injection_w', -20),
        );

        $mode = $this->getConfiguration('mode_connexion', 'local');
        $conf = array(
            'eq_id' => $this->getId(),
            'device_id' => $this->getConfiguration('device_id'),
            'product_key' => $this->getConfiguration('product_key'),
            'mode_connexion' => $mode,
            'anti_injection' => $antiInjection,
            'loop_period_s' => $this->getConfiguration('loop_period_s', 1),
        );

        if ($mode == 'cloud') {
            $conf['cloud_host'] = $this->getConfiguration('cloud_host', 'mqtt-eu.zen-iot.com');
            $conf['cloud_port'] = $this->getConfiguration('cloud_port', 1883);
            $conf['cloud_tls'] = (bool) $this->getConfiguration('cloud_tls', false);
            $conf['cloud_device_serial'] = $this->getConfiguration('cloud_device_serial');
            $conf['cloud_auth_key'] = $this->getConfiguration('cloud_auth_key');
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
     * Aiguille selon le sélecteur `template_dashboard` (addendum §14.3). Seul
     * "Condensé" est implémenté en v1 ; Flux/Historique retombent sur le rendu
     * par défaut en attendant (cf. changelog).
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

        $solar = $this->getCmdValue('solar_power');
        $grid = $this->getCmdValue('grid_power');
        $injected = $this->getCmdValue('injected_power');
        $house = $solar + $grid - $injected;

        $imax = (float) $this->getConfiguration('imax_ampere', 30);
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

        $tokens = array(
            '##EQ_ID##' => $this->getId(),
            '##NAME##' => $this->getName(),
            '##MODE##' => $this->getCmdValue('mode'),
            '##SOC##' => round($this->getCmdValue('soc')),
            '##SOLAR_W##' => round($solar),
            '##GRID_W##' => round($grid),
            '##HOUSE_W##' => round($house),
            '##INJECTED_W##' => round($injected),
            '##INTENSITE_PCT##' => $pct,
            '##INTENSITE_COLOR##' => $color,
            '##GAIN_JOUR##' => number_format((float) $this->getCmdValue('gain_jour'), 2),
            '##ANIMATIONS_CLASS##' => $animationsOn ? 'zc-animated' : '',
        );
        return str_replace(array_keys($tokens), array_values($tokens), $html);
    }

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
     * Lit la valeur d'une source externe (Étage 2 : cmdId stocké dans la config,
     * ex. src_intensite / src_grid_papp) — jamais d'ID en dur, cf. addendum §11.
     */
    private function getConfiguredSourceValue($configKey)
    {
        $cmdId = $this->getConfiguration($configKey);
        if (empty($cmdId)) {
            return 0;
        }
        $cmd = cmd::byId($cmdId);
        if (!is_object($cmd)) {
            return 0;
        }
        return $cmd->execCmd();
    }

    public function preInsert()
    {
    }

    public function postInsert()
    {
    }

    public function preSave()
    {
    }

    public function postSave()
    {
        $this->createOrUpdateCommands();
        $this->registerGridPowerListener();
        self::writeDaemonConfig();
        $this->reloadDaemonConfig();
    }

    public function preRemove()
    {
        $this->removeGridPowerListener();
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
        foreach (self::$INFO_COMMANDS as $logicalId => $def) {
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

        foreach (self::$COMPUTED_COMMANDS as $logicalId => $def) {
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

        foreach (self::$ACTION_COMMANDS as $logicalId => $def) {
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
    }

    /**
     * Enregistre un listener core sur la commande source de la pince (src_intensite /
     * src_grid_papp, cf. addendum §11 étage 2). Chaîne réelle : pince -> listener ->
     * ce callback PHP -> socket démon -> décision -> MQTT (addendum §12).
     *
     * Signature confirmée contre core/class/listener.class.php (VM) et l'usage réel
     * dans plugins/alarm/core/class/alarm.class.php : pas de setClassId/setPluginId/
     * byPluginId (n'existent pas dans le core) — le ciblage de la commande écoutée se
     * fait via addEvent($cmdId), et le filtrage par instance via l'`option` passée à
     * byClassAndFunction().
     */
    private function registerGridPowerListener()
    {
        $cmdId = $this->getConfiguration('src_grid_papp');
        if (empty($cmdId)) {
            $this->removeGridPowerListener();
            return;
        }

        $option = array('eq_id' => $this->getId());
        $listener = listener::byClassAndFunction('zendure', 'onGridPowerEvent', $option);
        if (!is_object($listener)) {
            $listener = new listener();
        }
        $listener->setClass('zendure');
        $listener->setFunction('onGridPowerEvent');
        $listener->setOption($option);
        $listener->emptyEvent();
        $listener->addEvent($cmdId);
        $listener->save();
    }

    private function removeGridPowerListener()
    {
        $listener = listener::byClassAndFunction('zendure', 'onGridPowerEvent', array('eq_id' => $this->getId()));
        if (is_object($listener)) {
            $listener->remove();
        }
    }

    /**
     * Callback statique invoqué par le core lors du déclenchement du listener
     * (changement de valeur de la commande pince). Relaie vers le démon via le
     * socket local — jamais d'accès direct MQTT depuis PHP (brief §9bis).
     */
    public static function onGridPowerEvent($_options)
    {
        $eqId = $_options['eq_id'] ?? null;
        $value = $_options['value'] ?? null;
        if ($eqId === null || $value === null) {
            return;
        }
        self::sendToDaemon(array(
            'type' => 'grid_power',
            'eq_id' => (int) $eqId,
            'value_w' => (float) $value,
        ));
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
