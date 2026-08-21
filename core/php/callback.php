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

    if (isset($payload['alert_id'])) {
        // Alerte utilisateur (centre de notifications Jeedom + actionOnMessage,
        // ex. mail -- pas seulement un log) -- ex. reconnexions MQTT en rafale
        // (cf. transport/mqtt_transport.py).
        //
        // Horodatage dans le logicalId (bug 2026-08-21, signalé par l'utilisateur
        // "j'aurais dû recevoir un mail") : message::add() ne déclenche
        // actionOnMessage (mail/push/scenario) qu'à la toute PREMIÈRE création
        // d'un logicalId donné -- toute répétition ultérieure du même logicalId
        // se contente d'incrémenter son compteur d'occurrences EN SILENCE, sans
        // jamais renvoyer de notification. Or les alertes envoyées ici
        // (telemetry_stale/resolu, mqtt_flapping/resolu, cf. device.py) sont
        // déjà "edge-triggered" côté démon -- appelées une seule fois par vraie
        // transition d'état, jamais en boucle -- donc pas besoin d'un
        // dédoublonnage "pour toujours" ici : au contraire, il rendait muette
        // toute réapparition du problème après la toute première fois (constaté
        // le 2026-08-21 : alerte de mi-juillet jamais retombée en mail malgré
        // l'épisode de la nuit du 20 au 21 août). L'horodatage à la seconde
        // suffit à garantir un logicalId neuf par transition réelle, sans
        // risquer de doublon (callback_client.py::send_alert() ne retry pas).
        //
        // Ne PAS appliquer ce même horodatage à l'alerte "démon injoignable"
        // (cf. zendure.class.php::sendToDaemon(), autre point d'appel, pas
        // celui-ci) : elle est vérifiée en continu tant que le démon est down
        // et DOIT rester dédoublonnée pour ne pas spammer.
        $message = $payload['message'] ?? '';
        $eqId = $payload['eq_id'] ?? null;
        log::add('zendure', 'error', $message);
        message::add('zendure', $message, '', 'zendure_alert_' . $eqId . '_' . $payload['alert_id'] . '_' . date('YmdHis'));
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
