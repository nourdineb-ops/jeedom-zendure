<?php
/* This file is part of Jeedom.
 *
 * Callback HTTP appelé par le démon (resources/zendure_daemon/jeedom/callback_client.py)
 * pour pousser télémétrie / logs vers le cœur. C'est le seul sens démon -> Jeedom ;
 * le sens Jeedom -> démon passe par le socket local (core/class/zendure.class.php::sendToDaemon).
 */

require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';

try {
    $payload = json_decode(file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        throw new Exception('Payload JSON invalide');
    }

    if (($payload['apikey'] ?? null) !== jeedom::getApiKey('zendure')) {
        throw new Exception('apikey invalide', 401);
    }

    if (isset($payload['log_level'])) {
        log::add('zendure', $payload['log_level'], $payload['message'] ?? '');
        echo json_encode(array('state' => 'ok'));
        die();
    }

    $eqId = $payload['eq_id'] ?? null;
    $values = $payload['values'] ?? array();
    $eqLogic = eqLogic::byId($eqId);
    if (!is_object($eqLogic) || $eqLogic->getEqType_name() != 'zendure') {
        throw new Exception('eqLogic zendure introuvable : ' . $eqId);
    }

    foreach ($values as $logicalId => $value) {
        $cmd = $eqLogic->getCmd(null, $logicalId);
        if (!is_object($cmd)) {
            // Changement de stratégie : le plugin capture désormais TOUTE la
            // télémétrie Zendure (pas une liste figée de clés), et crée la
            // commande à la volée au premier signalement — l'utilisateur choisit
            // ensuite lui-même, via l'onglet Sources, laquelle est la plus
            // fiable pour un usage donné (ex. injection).
            $cmd = new zendureCmd();
            $cmd->setLogicalId($logicalId);
            $cmd->setEqLogic_id($eqLogic->getId());
            $cmd->setType('info');
            $cmd->setName($logicalId);
            $cmd->setSubType(is_numeric($value) ? 'numeric' : 'string');
            // Pas d'historisation par défaut : une trame peut porter des dizaines
            // de clés à un rythme élevé (cf. échange sur la fréquence MQTT), on ne
            // veut pas gonfler `history` pour des champs jamais réellement utilisés.
            // L'utilisateur peut l'activer au cas par cas depuis la commande.
            $cmd->setIsHistorized(0);
            // Masquée par défaut : ce sont des lectures brutes destinées à être
            // choisies comme source (onglet Sources) ou recomposées par le widget
            // Flux (cf. zendure::createOrUpdateFluxWidget()), pas à s'afficher en
            // vrac sur le dashboard entre deux sauvegardes de l'équipement (seul
            // moment où le grand nettoyage de visibilité de postSave() repasse).
            $cmd->setIsVisible(0);
            $cmd->save();
            log::add('zendure', 'debug', 'Commande créée à la volée : ' . $logicalId);
        }
        $cmd->event($value);
    }

    echo json_encode(array('state' => 'ok'));
} catch (Exception $e) {
    http_response_code($e->getCode() >= 400 ? $e->getCode() : 400);
    echo json_encode(array('state' => 'error', 'message' => $e->getMessage()));
}
