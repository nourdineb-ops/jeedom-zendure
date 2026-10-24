<?php
/* This file is part of Jeedom.
 * Page de configuration globale du plugin (défauts repris par chaque équipement).
 * Les identifiants et seuils propres à un appareil se règlent sur l'équipement
 * lui-même (desktop/php/zendure.php).
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
            <label class="col-sm-3 control-label">{{Port du socket local (PHP -> démon)}}</label>
            <div class="col-sm-3">
                <input type="number" class="configKey form-control" data-l1key="socketport" placeholder="55071" />
            </div>
        </div>
    </fieldset>

    <fieldset>
        <legend>{{Défauts connexion Cloud}}</legend>
        <div class="form-group">
            <label class="col-sm-3 control-label">{{Broker cloud (hôte)}}</label>
            <div class="col-sm-3">
                <input class="configKey form-control" data-l1key="default_cloud_host" placeholder="mqtteu.zen-iot.com" />
            </div>
        </div>
        <div class="form-group">
            <label class="col-sm-3 control-label">{{Port}}</label>
            <div class="col-sm-3">
                <input type="number" class="configKey form-control" data-l1key="default_cloud_port" placeholder="1883" />
            </div>
        </div>
    </fieldset>

    <fieldset>
        <legend>{{Tarifs par défaut (€/kWh) — repris par chaque équipement Zendure}}</legend>
        <div class="form-group">
            <label class="col-sm-3 control-label">{{Prix HP / HC}}</label>
            <div class="col-sm-2">
                <input type="number" step="0.001" class="configKey form-control" data-l1key="prix_hp" />
            </div>
            <div class="col-sm-2">
                <input type="number" step="0.001" class="configKey form-control" data-l1key="prix_hc" />
            </div>
        </div>
        <div class="form-group">
            <label class="col-sm-3 control-label">{{Prix Tempo Bleu/Blanc/Rouge}}</label>
            <div class="col-sm-2">
                <input type="number" step="0.001" class="configKey form-control" data-l1key="prix_tempo_bleu" />
            </div>
            <div class="col-sm-2">
                <input type="number" step="0.001" class="configKey form-control" data-l1key="prix_tempo_blanc" />
            </div>
            <div class="col-sm-2">
                <input type="number" step="0.001" class="configKey form-control" data-l1key="prix_tempo_rouge" />
            </div>
        </div>
    </fieldset>

    <fieldset>
        <legend>{{Défauts anti-injection (repris par chaque équipement)}}</legend>
        <div class="form-group">
            <label class="col-sm-3 control-label">{{Marge anti-injection (W)}}</label>
            <div class="col-sm-3">
                <input type="number" class="configKey form-control" data-l1key="default_marge_anti_injection" placeholder="30" />
            </div>
        </div>
        <div class="form-group">
            <label class="col-sm-3 control-label">{{Cooldown (s)}}</label>
            <div class="col-sm-3">
                <input type="number" step="0.1" class="configKey form-control" data-l1key="default_cooldown_anti_injection" placeholder="2" />
            </div>
        </div>
    </fieldset>

    <fieldset>
        <legend>{{Défauts fréquence de mise à jour (repris par chaque équipement)}}</legend>
        <div class="alert alert-info">
            {{Le démon ne pousse une valeur de télémétrie vers Jeedom que si elle a changé, sauf au minimum toutes les X secondes (pour ne jamais laisser une commande "morte" trop longtemps). Un bouton "Capture télémétrie complète (1h)" sur chaque équipement permet de tout laisser passer temporairement pour du diagnostic.}}
        </div>
        <div class="form-group">
            <label class="col-sm-3 control-label">{{Intervalle minimum (s)}}</label>
            <div class="col-sm-3">
                <input type="number" class="configKey form-control" data-l1key="default_telemetry_min_interval_s" placeholder="300" />
            </div>
        </div>
        <div class="form-group">
            <label class="col-sm-3 control-label">{{Tolérance de bruit}}</label>
            <div class="col-sm-3">
                <input type="number" step="0.1" class="configKey form-control" data-l1key="default_telemetry_noise_threshold" placeholder="3" />
            </div>
        </div>
    </fieldset>
</form>
