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
        if (is_object($cmd)) {
            $cmd->event($value);
        } else {
            log::add('zendure', 'debug', 'Valeur reçue pour commande inconnue : ' . $logicalId);
        }
    }

    echo json_encode(array('state' => 'ok'));
} catch (Exception $e) {
    http_response_code($e->getCode() >= 400 ? $e->getCode() : 400);
    echo json_encode(array('state' => 'error', 'message' => $e->getMessage()));
}
