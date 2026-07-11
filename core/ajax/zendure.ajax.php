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

    // Onglet "Télémétrie" : liste toutes les commandes de l'équipement (curées ET
    // créées à la volée par callback.php depuis la télémétrie brute Zendure), avec
    // valeur actuelle + date de dernière mise à jour. Même contrainte que
    // debugCapture : formulaire partagé, pas de contexte $eqLogic au rendu PHP.
    if (init('action') == 'listCommands') {
        $eqLogic = eqLogic::byId(init('eqLogic_id'));
        if (!is_object($eqLogic) || $eqLogic->getEqType_name() != 'zendure') {
            throw new Exception(__('Équipement Zendure introuvable', __FILE__));
        }
        $result = array();
        foreach ($eqLogic->getCmd() as $cmd) {
            $result[] = array(
                'id' => $cmd->getId(),
                'logicalId' => $cmd->getLogicalId(),
                'name' => $cmd->getName(),
                'type' => $cmd->getType(),
                'subType' => $cmd->getSubType(),
                'value' => $cmd->getType() == 'info' ? $cmd->execCmd() : '',
                'unit' => $cmd->getUnite(),
                'isHistorized' => (int) $cmd->getIsHistorized(),
                'collectDate' => $cmd->getCollectDate(),
                'curated' => in_array($cmd->getLogicalId(), array_keys(array_merge(zendure::INFO_COMMANDS, zendure::ACTION_COMMANDS, zendure::COMPUTED_COMMANDS))) ? 1 : 0,
            );
        }
        usort($result, function ($a, $b) {
            return strcmp($a['logicalId'], $b['logicalId']);
        });
        ajax::success($result);
    }

    throw new Exception(__('Aucune méthode correspondante à', __FILE__) . ' : ' . init('action'));
} catch (Exception $e) {
    ajax::error(displayException($e), $e->getCode());
}
