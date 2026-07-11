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
        <legend>{{Tarifs EDF (€/kWh) — communs à tous les équipements Zendure}}</legend>
        <div class="form-group">
            <label class="col-cm-3 control-label">{{Prix HP / HC}}</label>
            <div class="col-cm-2">
                <input type="number" step="0.001" class="configKey form-control" data-l1key="prix_hp" />
            </div>
            <div class="col-cm-2">
                <input type="number" step="0.001" class="configKey form-control" data-l1key="prix_hc" />
            </div>
        </div>
        <div class="form-group">
            <label class="col-cm-3 control-label">{{Prix Tempo Bleu/Blanc/Rouge}}</label>
            <div class="col-cm-2">
                <input type="number" step="0.001" class="configKey form-control" data-l1key="prix_tempo_bleu" />
            </div>
            <div class="col-cm-2">
                <input type="number" step="0.001" class="configKey form-control" data-l1key="prix_tempo_blanc" />
            </div>
            <div class="col-cm-2">
                <input type="number" step="0.001" class="configKey form-control" data-l1key="prix_tempo_rouge" />
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

    <fieldset>
        <legend>{{Défauts fréquence de mise à jour (Étage 3, repris par chaque eqLogic)}}</legend>
        <div class="alert alert-info">
            {{Le démon ne pousse une valeur de télémétrie vers Jeedom que si elle a changé, sauf au minimum toutes les X secondes (pour ne jamais laisser une commande "morte" trop longtemps). Un bouton "Capture télémétrie complète (1h)" sur chaque équipement permet de tout laisser passer temporairement pour du diagnostic.}}
        </div>
        <div class="form-group">
            <label class="col-cm-3 control-label">{{Intervalle minimum (s)}}</label>
            <div class="col-cm-3">
                <input type="number" class="configKey form-control" data-l1key="default_telemetry_min_interval_s" placeholder="300" />
            </div>
        </div>
        <div class="form-group">
            <label class="col-cm-3 control-label">{{Tolérance de bruit}}</label>
            <div class="col-cm-3">
                <input type="number" step="0.1" class="configKey form-control" data-l1key="default_telemetry_noise_threshold" placeholder="3" />
            </div>
        </div>
    </fieldset>
</form>
