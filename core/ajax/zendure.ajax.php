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

    if (!isConnect('admin')) {
        throw new Exception(__('401 - Accès non autorisé', __FILE__));
    }

    ajax::init();

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

    throw new Exception(__('Aucune méthode correspondante à', __FILE__) . ' : ' . init('action'));
} catch (Exception $e) {
    ajax::error(displayException($e), $e->getCode());
}
