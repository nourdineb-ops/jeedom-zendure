<?php
/* This file is part of Jeedom.
 *
 * Page de gestion des eqLogic Zendure. Structure standard Jeedom (liste à gauche,
 * détail à droite en onglets). Les champs de configuration reprennent les 3 étages
 * décrits dans l'addendum §11 : transport (§1), sources (§2), comportement (§3).
 *
 * NOTE (addendum §15, point ouvert) : le sélecteur de commande source
 * (.zendureCmdSelect ci-dessous) est une implémentation MAISON (ajax
 * core/ajax/zendure.ajax.php?action=cmdList) plutôt qu'un widget natif du core
 * dont la signature exacte restait à confirmer sur doc.jeedom.com. À remplacer par
 * le widget natif si son API est confirmée équivalente ou meilleure.
 */

if (!isConnect()) {
    include_file('desktop', '404', 'php');
    die();
}

$plugin = plugin::byId('zendure');
sendVarToJS([
    'eqType' => $plugin->getId()
]);
$eqLogics = eqLogic::byType($plugin->getId());
?>

<div class="row row-overflow">
    <div class="col-lg-3 col-md-3 hidden-xs hidden-sm">
        <legend><i class="fas fa-cog"></i> {{Gestion}}</legend>
        <div class="eqLogicThumbnailContainer">
            <div class="cursor eqLogicAction logoPrimary" data-action="add">
                <i class="fas fa-plus-circle"></i>
                <br />
                <span style="color:var(--txt-color)">{{Ajouter}}</span>
            </div>
            <div class="cursor eqLogicAction logoSecondary" data-action="gotoPluginConf">
                <i class="fas fa-wrench"></i>
                <br>
                <span style="color:var(--txt-color)">{{Configuration}}</span>
            </div>
            <div class="cursor pluginAction logoSecondary" data-action="openLocation" data-location="<?= $plugin->getDocumentation() ?>">
                <i class="fas fa-book icon_blue"></i>
                <br>
                <span style="color:var(--txt-color)">{{Documentation}}</span>
            </div>
            <div class="cursor pluginAction logoSecondary" data-action="openLocation" data-location="https://community.jeedom.com/tag/plugin-<?= $plugin->getId() ?>">
                <i class="fas fa-thumbs-up icon_green"></i>
                <br>
                <span style="color:var(--txt-color)">{{Community}}</span>
            </div>
        </div>

        <legend>{{Mes Zendure}}</legend>
        <div class="input-group m-b-5">
            <input class="form-control roundedLeft roundedRight" placeholder="{{Rechercher}}" id="in_search_eqLogic" />
        </div>
        <div class="eqLogicThumbnailDisplay">
            <div class="eqLogicThumbnailContainer">
                <?php
                foreach ($eqLogics as $eqLogic) {
                    $opacity = ($eqLogic->getIsEnable()) ? '' : 'disableCard';
                    echo '<div class="eqLogicDisplayCard cursor ' . $opacity . '" data-eqLogic_id="' . $eqLogic->getId() . '">';
                    echo '<img src="' . $eqLogic->getImage() . '"/>';
                    echo '<br/>';
                    echo '<span class="name">' . $eqLogic->getHumanName(true, true) . '</span>';
                    echo '</div>';
                }
                ?>
            </div>
        </div>
    </div>

    <div class="col-lg-9 col-md-9 col-sm-12 col-xs-12 eqLogic" style="display: none;">
        <div class="input-group pull-right" style="display:inline-flex">
            <span class="input-group-btn">
                <!-- Les balises <a></a> sont volontairement fermées à la ligne suivante pour éviter les espaces entre les boutons. Ne pas modifier -->
                <a class="btn btn-sm btn-default eqLogicAction roundedLeft" data-action="configure"><i class="fas fa-cogs"></i><span class="hidden-xs"> {{Configuration avancée}}</span>
                </a><a class="btn btn-sm btn-default eqLogicAction" data-action="copy"><i class="fas fa-copy"></i><span class="hidden-xs"> {{Dupliquer}}</span>
                </a><a class="btn btn-sm btn-success eqLogicAction" data-action="save"><i class="fas fa-check-circle"></i> {{Sauvegarder}}
                </a><a class="btn btn-sm btn-danger eqLogicAction roundedRight" data-action="remove"><i class="fas fa-minus-circle"></i> {{Supprimer}}
                </a>
            </span>
        </div>

        <ul class="nav nav-tabs" role="tablist">
            <li role="presentation"><a href="#" class="eqLogicAction" aria-controls="home" role="tab" data-toggle="tab" data-action="returnToThumbnailDisplay"><i class="fas fa-arrow-circle-left"></i></a></li>
            <li role="presentation" class="active"><a href="#tab_general" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-cog"></i> {{Zendure}}</a></li>
            <li role="presentation"><a href="#tab_transport" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-plug"></i> {{Transport}}</a></li>
            <li role="presentation"><a href="#tab_sources" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-satellite-dish"></i> {{Sources}}</a></li>
            <li role="presentation"><a href="#tab_comportement" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-balance-scale"></i> {{Comportement}}</a></li>
            <li role="presentation"><a href="#tab_ihm" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-palette"></i> {{IHM}}</a></li>
        </ul>

        <form class="form-horizontal tab-content" id="div_eqLogic">
            <div role="tabpanel" class="tab-pane active" id="tab_general">
                <div class="alert alert-info">
                    {{Un eqLogic Zendure = un Hyper 2000. Multi-équipement : créer un 2e eqLogic sans recoder (addendum §13).}}
                </div>
                <fieldset>
                    <legend><i class="fas fa-cog"></i> {{Général}}</legend>
                    <div class="form-group">
                        <label class="col-cm-2 control-label">{{Nom}}</label>
                        <div class="col-cm-4">
                            <input class="eqLogicAttr form-control" data-l1key="name" />
                        </div>
                        <label class="col-cm-2 control-label">{{Activer}}</label>
                        <div class="col-cm-2">
                            <input type="checkbox" class="eqLogicAttr" data-l1key="isEnable" checked />
                        </div>
                    </div>
                </fieldset>
            </div>

            <div role="tabpanel" class="tab-pane" id="tab_transport">
            <fieldset>
                <legend><i class="fas fa-plug"></i> {{Transport / connexion}}</legend>
                <div class="form-group">
                    <label class="col-cm-3 control-label">{{Mode de connexion}}</label>
                    <div class="col-cm-4">
                        <select id="sel_mode_connexion" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="mode_connexion">
                            <option value="local">{{Local (Chemin B — recommandé v1, zéro-injection)}}</option>
                            <option value="cloud">{{Cloud (Chemin A — simple mais latent ~90s)}}</option>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label class="col-cm-3 control-label">{{Identifiant appareil (device_id)}}</label>
                    <div class="col-cm-4">
                        <input class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="device_id" />
                    </div>
                    <label class="col-cm-2 control-label">{{Product key}}</label>
                    <div class="col-cm-3">
                        <input class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="product_key" />
                    </div>
                </div>

                <div id="bloc_cloud">
                    <div class="form-group">
                        <label class="col-cm-3 control-label">{{Broker cloud}}</label>
                        <div class="col-cm-4">
                            <input class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="cloud_host" placeholder="mqtt-eu.zen-iot.com" />
                        </div>
                        <label class="col-cm-2 control-label">{{Port}}</label>
                        <div class="col-cm-3">
                            <input type="number" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="cloud_port" placeholder="1883" />
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="col-cm-3 control-label">{{Clé Cloud d'Autorisation}}</label>
                        <div class="col-cm-4">
                            <input type="password" class="eqLogicAttr inputPassword form-control" data-l1key="configuration" data-l2key="cloud_auth_key" />
                        </div>
                        <label class="col-cm-2 control-label">{{Numéro de série}}</label>
                        <div class="col-cm-3">
                            <input class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="cloud_device_serial" />
                        </div>
                    </div>
                </div>

                <div id="bloc_local">
                    <div class="form-group">
                        <label class="col-cm-3 control-label">{{IP Mosquitto local}}</label>
                        <div class="col-cm-4">
                            <input class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="local_host" placeholder="192.168.1.50" />
                        </div>
                        <label class="col-cm-2 control-label">{{Port}}</label>
                        <div class="col-cm-3">
                            <input type="number" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="local_port" placeholder="1883" />
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="col-cm-3 control-label">{{Identifiant (optionnel)}}</label>
                        <div class="col-cm-4">
                            <input class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="local_username" />
                        </div>
                        <label class="col-cm-2 control-label">{{Mot de passe}}</label>
                        <div class="col-cm-3">
                            <input type="password" class="eqLogicAttr inputPassword form-control" data-l1key="configuration" data-l2key="local_password" />
                        </div>
                    </div>
                    <div class="alert alert-warning">
                        {{Prérequis Chemin B : relais DNS mq.zen-iot.com -> Mosquitto local + reconfiguration Bluetooth de l'appareil (Solarflow Bluetooth Manager / Zendure Cloud Disconnector). Voir README.}}
                    </div>
                </div>
            </fieldset>
            </div>

            <div role="tabpanel" class="tab-pane" id="tab_sources">
            <fieldset>
                <legend><i class="fas fa-satellite-dish"></i> {{Sources de lecture (jamais d'ID en dur)}}</legend>
                <div class="form-group">
                    <label class="col-cm-3 control-label">{{Pince ampèremétrique (intensité)}}</label>
                    <div class="col-cm-5">
                        <select class="zendureCmdSelect eqLogicAttr form-control" data-l1key="configuration" data-l2key="src_intensite"></select>
                    </div>
                </div>
                <div class="form-group">
                    <label class="col-cm-3 control-label">{{PAPP réseau}}</label>
                    <div class="col-cm-5">
                        <select class="zendureCmdSelect eqLogicAttr form-control" data-l1key="configuration" data-l2key="src_grid_papp"></select>
                    </div>
                </div>
                <div class="form-group">
                    <label class="col-cm-3 control-label">{{Imax abonnement}}</label>
                    <div class="col-cm-5">
                        <select class="zendureCmdSelect eqLogicAttr form-control" data-l1key="configuration" data-l2key="src_imax_abonnement"></select>
                    </div>
                </div>
                <div class="form-group">
                    <label class="col-cm-3 control-label">{{Période tarifaire (PTEC / HP-HC)}}</label>
                    <div class="col-cm-5">
                        <select class="zendureCmdSelect eqLogicAttr form-control" data-l1key="configuration" data-l2key="src_periode_tarif"></select>
                    </div>
                </div>
                <div class="form-group">
                    <label class="col-cm-3 control-label">{{Tempo (now / J / J+1)}}</label>
                    <div class="col-cm-2">
                        <select class="zendureCmdSelect eqLogicAttr form-control" data-l1key="configuration" data-l2key="src_tempo_now"></select>
                    </div>
                    <div class="col-cm-2">
                        <select class="zendureCmdSelect eqLogicAttr form-control" data-l1key="configuration" data-l2key="src_tempo_j"></select>
                    </div>
                    <div class="col-cm-2">
                        <select class="zendureCmdSelect eqLogicAttr form-control" data-l1key="configuration" data-l2key="src_tempo_j1"></select>
                    </div>
                </div>
                <div class="form-group">
                    <label class="col-cm-3 control-label">{{Prévision solaire (kWh)}}</label>
                    <div class="col-cm-5">
                        <select class="zendureCmdSelect eqLogicAttr form-control" data-l1key="configuration" data-l2key="src_prevision_solaire"></select>
                    </div>
                </div>
            </fieldset>
            </div>

            <div role="tabpanel" class="tab-pane" id="tab_comportement">
            <fieldset>
                <legend><i class="fas fa-balance-scale"></i> {{Comportement}}</legend>
                <div class="form-group">
                    <label class="col-cm-3 control-label">{{Marge anti-injection (W)}}</label>
                    <div class="col-cm-2">
                        <input type="number" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="marge_anti_injection" placeholder="30" />
                    </div>
                    <label class="col-cm-3 control-label">{{Cooldown (s)}}</label>
                    <div class="col-cm-2">
                        <input type="number" step="0.1" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="cooldown_anti_injection" placeholder="2" />
                    </div>
                </div>
                <div class="form-group">
                    <label class="col-cm-3 control-label">{{Hystérésis (W)}}</label>
                    <div class="col-cm-2">
                        <input type="number" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="hysteresis_anti_injection" placeholder="15" />
                    </div>
                    <label class="col-cm-3 control-label">{{Limites sortie min/max (W)}}</label>
                    <div class="col-cm-1">
                        <input type="number" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="limite_min_w" placeholder="0" />
                    </div>
                    <div class="col-cm-1">
                        <input type="number" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="limite_max_w" placeholder="1200" />
                    </div>
                </div>
                <div class="form-group">
                    <label class="col-cm-3 control-label">{{Imax (A)}}</label>
                    <div class="col-cm-2">
                        <input type="number" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="imax_ampere" />
                    </div>
                    <label class="col-cm-3 control-label">{{Réseau}}</label>
                    <div class="col-cm-2">
                        <select class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="phases">
                            <option value="mono">{{Monophasé}}</option>
                            <option value="tri">{{Triphasé}}</option>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label class="col-cm-3 control-label">{{Seuil jauge ambre / rouge (%)}}</label>
                    <div class="col-cm-2">
                        <input type="number" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="seuil_intensite_ambre" placeholder="70" />
                    </div>
                    <div class="col-cm-2">
                        <input type="number" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="seuil_intensite_rouge" placeholder="90" />
                    </div>
                </div>
            </fieldset>
            </div>

            <div role="tabpanel" class="tab-pane" id="tab_ihm">
            <fieldset>
                <legend><i class="fas fa-palette"></i> {{IHM}}</legend>
                <div class="form-group">
                    <label class="col-cm-3 control-label">{{Dashboard}}</label>
                    <div class="col-cm-3">
                        <select class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="template_dashboard">
                            <option value="flux">{{Flux}}</option>
                            <option value="condense">{{Condensé}}</option>
                            <option value="historique">{{Historique}}</option>
                        </select>
                    </div>
                    <label class="col-cm-2 control-label">{{Animations}}</label>
                    <div class="col-cm-2">
                        <input type="checkbox" class="eqLogicAttr" data-l1key="configuration" data-l2key="animations_actives" checked />
                    </div>
                </div>
            </fieldset>
            </div>
        </form>
    </div>
</div>

<script>
$(function () {
    $('#sel_mode_connexion').on('change', function () {
        var mode = $(this).val();
        $('#bloc_cloud').toggle(mode == 'cloud');
        $('#bloc_local').toggle(mode == 'local');
    }).trigger('change');

    $('.zendureCmdSelect').each(function () {
        var $sel = $(this);
        var current = $sel.attr('data-cmdid') || '';
        jeedom.plugin.ajax({
            action: 'cmdList',
            plugin: 'zendure',
            error: function (error) {
                $('#div_alert').showAlert({ message: error.message, level: 'danger' });
            },
            success: function (data) {
                $sel.empty().append('<option value="">{{Choisir une commande}}</option>');
                $.each(data, function (i, cmd) {
                    $sel.append('<option value="' + cmd.id + '">' + cmd.eqName + ' :: ' + cmd.name + '</option>');
                });
                $sel.val(current);
            }
        });
    });

});
</script>
<?php include_file('core', 'plugin.template', 'js'); ?>
