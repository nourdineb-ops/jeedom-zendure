<?php
/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

require_once dirname(__FILE__) . '/../../../core/php/core.inc.php';

// Appelée par le cœur après l'installation du plugin.
function zendure_install()
{
}

// Appelée par le cœur après une mise à jour : chaque équipement est resauvegardé
// pour que postSave() réenregistre commandes, listeners et crons de la nouvelle version.
function zendure_update()
{
    foreach (eqLogic::byType('zendure') as $eqLogic) {
        $eqLogic->save();
    }
}

// Appelée par le cœur à la désinstallation du plugin.
function zendure_remove()
{
}
