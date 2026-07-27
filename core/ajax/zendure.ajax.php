<?php

/* This file is part of Jeedom.
 *
 * Action ponctuelle non liée à une commande précise au moment du rendu (le
 * bouton "Capture télémétrie complète" vit sur le formulaire partagé de
 * l'onglet Comportement, pas sur un widget rendu par eqLogic — donc pas de
 * cmd_id connu à l'avance côté PHP, on le résout ici via l'eqLogic_id transmis
 * par le JS au clic).
 */

try {
    require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';
    include_file('core', 'authentification', 'php');

    ajax::init();

    // Watchdog "hors ligne" du widget Flux (cf. cmd.info.string.flux_widget.html,
    // fonction fetchLastSeen()) : appelé au chargement du dashboard, PAS depuis
    // l'écran de config de l'équipement -- contrairement à debugCapture
    // ci-dessous, un simple utilisateur (profil "user", pas admin) peut légitimement
    // consulter le dashboard, donc isConnect() simple plutôt que isConnect('admin').
    // Pourquoi cet appel existe : jeedom.cmd.execute() au chargement du widget
    // renvoie la dernière valeur connue en base SANS son âge -- le widget la
    // recevait comme une preuve de vie "à l'instant", masquant une coupure déjà
    // ancienne pendant tout un cycle de seuil après chaque rechargement de page
    // (signalé : badge "Hors ligne" absent alors que le device était réellement
    // muet depuis 40+ minutes). Un seul appel réseau au chargement suffit : la
    // suite de la détection reste 100% locale (pushes live via addUpdateFunction).
    if (init('action') == 'lastSeen') {
        if (!isConnect()) {
            throw new Exception(__('401 - Accès non autorisé', __FILE__));
        }
        $eqLogic = eqLogic::byId(init('eqLogic_id'));
        if (!is_object($eqLogic) || $eqLogic->getEqType_name() != 'zendure') {
            throw new Exception(__('Équipement Zendure introuvable', __FILE__));
        }
        $latestMs = 0;
        foreach (array('solar_power', 'grid_power', 'injected_power', 'soc', 'mode') as $lid) {
            $cmd = $eqLogic->getCmd(null, $lid);
            if (!is_object($cmd)) {
                continue;
            }
            $date = $cmd->getCollectDate(1);
            if ($date == '') {
                continue;
            }
            $ms = strtotime($date) * 1000;
            if ($ms > $latestMs) {
                $latestMs = $ms;
            }
        }
        ajax::success(array('lastSeenMs' => $latestMs));
    }

    // Panneau debug du widget Flux (cf. cmd.info.string.flux_widget.html, fonction
    // fetchLogTail()) : mêmes raisons d'accès que lastSeen ci-dessus -- visible
    // depuis le dashboard, donc isConnect() simple, pas 'admin'. Lit les N
    // dernières lignes des 2 logs pertinents (démon Python + plugin PHP) plutôt
    // que d'exposer le fichier entier : c'est un aperçu "très synthétique", pas
    // un visualiseur de logs complet (qui existe déjà nativement dans Jeedom).
    if (init('action') == 'tailLogs') {
        if (!isConnect()) {
            throw new Exception(__('401 - Accès non autorisé', __FILE__));
        }
        $lines = max(1, min(100, (int) init('lines', 15)));
        ajax::success(array(
            'daemon' => zendure::tailLogFile(log::getPathToLog('zendure_daemon'), $lines),
            'plugin' => zendure::tailLogFile(log::getPathToLog('zendure'), $lines),
        ));
    }

    if (!isConnect('admin')) {
        throw new Exception(__('401 - Accès non autorisé', __FILE__));
    }

    if (init('action') == 'debugCapture') {
        $eqLogic = eqLogic::byId(init('eqLogic_id'));
        if (!is_object($eqLogic) || $eqLogic->getEqType_name() != 'zendure') {
            throw new Exception(__('Équipement Zendure introuvable', __FILE__));
        }
        $cmd = $eqLogic->getCmd(null, 'debug_capture_1h');
        if (!is_object($cmd)) {
            throw new Exception(__('Commande debug_capture_1h introuvable (sauvegardez l\'équipement une première fois)', __FILE__));
        }
        $cmd->execute();
        ajax::success();
    }

    // Bouton "Désactiver le mode intelligent" (config, onglet Comportement) :
    // incident réel du 2026-07-22 -- l'appli mobile avait basculé l'appareil en
    // "Mode intelligent" (smartMode), qui fait alors ignorer nos commandes
    // deviceAutomation manuelles (charge/décharge) sans aucune erreur ni retour
    // visible -- juste un plafond de sortie qui ne bouge jamais. Jusqu'ici
    // set_smart_mode n'était appelable qu'en interne (Device.stop()) ; ce bouton
    // le rend accessible à l'utilisateur sans devoir chercher dans l'app Zendure.
    if (init('action') == 'disableSmartMode') {
        $eqLogic = eqLogic::byId(init('eqLogic_id'));
        if (!is_object($eqLogic) || $eqLogic->getEqType_name() != 'zendure') {
            throw new Exception(__('Équipement Zendure introuvable', __FILE__));
        }
        $cmd = $eqLogic->getCmd(null, 'set_smart_mode');
        if (!is_object($cmd)) {
            throw new Exception(__('Commande set_smart_mode introuvable (sauvegardez l\'équipement une première fois)', __FILE__));
        }
        $cmd->execCmd(array('slider' => 0));
        ajax::success();
    }

    throw new Exception(__('Aucune méthode correspondante à', __FILE__) . ' : ' . init('action'));
} catch (Exception $e) {
    ajax::error(displayException($e), $e->getCode());
}
