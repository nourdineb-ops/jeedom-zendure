<?php
/* This file is part of Jeedom.
 * Page de config globale du plugin (Étage 1 : défauts transport, socket démon).
 * Les creds/seuils par appareil vivent au niveau eqLogic (desktop/php/zendure.php),
 * cf. addendum §13 : "config transport + creds + seuils au niveau eqLogic".
 */

if (!isConnect('admin')) {
    include_file('desktop', '404', 'php');
    die();
}
?>

<form class="form-horizontal">
    <fieldset>
        <legend>{{Démon}}</legend>
        <div class="form-group">
            <label class="col-cm-3 control-label">{{Port du socket local (PHP -> démon)}}</label>
            <div class="col-cm-3">
                <input type="number" class="configKey form-control" data-l1key="socketport" placeholder="55071" />
            </div>
        </div>
        <div class="form-group">
            <label class="col-cm-3 control-label">{{Niveau de log}}</label>
            <div class="col-cm-3">
                <select class="configKey form-control" data-l1key="loglevel">
                    <option value="info">info</option>
                    <option value="debug">debug</option>
                    <option value="warning">warning</option>
                </select>
            </div>
        </div>
    </fieldset>

    <fieldset>
        <legend>{{Défauts Chemin A - Cloud}}</legend>
        <div class="form-group">
            <label class="col-cm-3 control-label">{{Broker cloud (hôte)}}</label>
            <div class="col-cm-3">
                <input class="configKey form-control" data-l1key="default_cloud_host" placeholder="mqtt-eu.zen-iot.com" />
            </div>
        </div>
        <div class="form-group">
            <label class="col-cm-3 control-label">{{Port}}</label>
            <div class="col-cm-3">
                <input type="number" class="configKey form-control" data-l1key="default_cloud_port" placeholder="1883" />
            </div>
        </div>
    </fieldset>

    <fieldset>
        <legend>{{Défauts anti-injection (Étage 3, repris par chaque eqLogic)}}</legend>
        <div class="form-group">
            <label class="col-cm-3 control-label">{{Marge anti-injection (W)}}</label>
            <div class="col-cm-3">
                <input type="number" class="configKey form-control" data-l1key="default_marge_anti_injection" placeholder="30" />
            </div>
        </div>
        <div class="form-group">
            <label class="col-cm-3 control-label">{{Cooldown (s)}}</label>
            <div class="col-cm-3">
                <input type="number" step="0.1" class="configKey form-control" data-l1key="default_cooldown_anti_injection" placeholder="2" />
            </div>
        </div>
    </fieldset>
</form>
