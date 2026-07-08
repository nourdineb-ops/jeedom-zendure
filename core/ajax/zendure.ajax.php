<?php
/* This file is part of Jeedom.
 *
 * Ajax du plugin Zendure. Fournit notamment cmdList, utilisé par le sélecteur de
 * commande source (desktop/php/zendure.php, cf. note addendum §15 sur la
 * signature du widget natif à confirmer). Implémentation volontairement simple :
 * cmd::all() + filtre type=info, pas de dépendance à une API core incertaine.
 */

require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';

try {
    if (!isConnect()) {
        throw new Exception(__('401 - Accès non autorisé', __FILE__));
    }

    if (init('action') == 'cmdList') {
        $search = strtolower(init('search', ''));
        $result = array();
        foreach (cmd::all() as $cmd) {
            if ($cmd->getType() != 'info') {
                continue;
            }
            $eqLogic = $cmd->getEqLogic();
            if (!is_object($eqLogic)) {
                continue;
            }
            $label = $eqLogic->getName() . ' :: ' . $cmd->getName();
            if ($search != '' && strpos(strtolower($label), $search) === false) {
                continue;
            }
            $result[] = array(
                'id' => $cmd->getId(),
                'name' => $cmd->getName(),
                'eqName' => $eqLogic->getName(),
            );
        }
        ajax::success($result);
    }

    throw new Exception(__('Aucune méthode correspondante à : ', __FILE__) . init('action'));
} catch (Exception $e) {
    ajax::error(displayException($e), $e->getCode());
}
