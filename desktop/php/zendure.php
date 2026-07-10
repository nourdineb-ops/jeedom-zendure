<?php
/* This file is part of Jeedom.
 *
 * Page de gestion des eqLogic Zendure. Structure standard Jeedom (liste puis
 * détail en pleine largeur, jamais côte à côte — cf. plugins/frigate,
 * plugins/alarm, plugins/iCalendar), détail organisé en onglets reprenant les
 * étages du brief (transport §1, sources §2, comportement §3).
 *
 * Sélection de commande source (onglet Sources) : widget natif du core
 * (jeedom.cmd.getSelectModal), pas d'implémentation maison — point ouvert
 * de l'addendum §15 tranché en faveur du widget natif, confirmé disponible.
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
    <div class="col-xs-12 eqLogicThumbnailDisplay">
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

        <legend><i class="fas fa-table"></i> {{Mes équipements Zendure}}</legend>
        <?php
        if (count($eqLogics) == 0) {
            echo '<br><div class="text-center" style="font-size:1.2em;font-weight:bold;">{{Aucun équipement Zendure trouvé, cliquez sur "Ajouter" pour commencer}}</div>';
        } else {
            echo '<div class="input-group" style="margin:5px;">';
            echo '<input class="form-control roundedLeft" placeholder="{{Rechercher}}" id="in_searchEqlogic">';
            echo '<div class="input-group-btn">';
            echo '<a id="bt_resetSearch" class="btn" style="width:30px"><i class="fas fa-times"></i></a>';
            echo '<a class="btn roundedRight hidden" id="bt_pluginDisplayAsTable" data-coreSupport="1" data-state="0"><i class="fas fa-grip-lines"></i></a>';
            echo '</div>';
            echo '</div>';
            echo '<div class="eqLogicThumbnailContainer">';
            foreach ($eqLogics as $eqLogic) {
                $opacity = ($eqLogic->getIsEnable()) ? '' : 'disableCard';
                echo '<div class="eqLogicDisplayCard cursor ' . $opacity . '" data-eqLogic_id="' . $eqLogic->getId() . '">';
                echo '<img src="' . $eqLogic->getImage() . '"/>';
                echo '<br/>';
                echo '<span class="name">' . $eqLogic->getHumanName(true, true) . '</span>';
                echo '</div>';
            }
            echo '</div>';
        }
        ?>
    </div>

    <div class="col-xs-12 eqLogic" style="display: none;">
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
            <li role="presentation" class="active"><a href="#tab_general" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-tachometer-alt"></i> {{Equipement}}</a></li>
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
                    <legend><i class="fas fa-tachometer-alt"></i> {{Equipement}}</legend>
                    <div class="form-group">
                        <label class="col-sm-2 control-label">{{Nom de l'équipement}}</label>
                        <div class="col-sm-6">
                            <input type="text" class="eqLogicAttr form-control" data-l1key="id" style="display:none;">
                            <input class="eqLogicAttr form-control" data-l1key="name" />
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="col-sm-2 control-label">{{Objet parent}}</label>
                        <div class="col-sm-6">
                            <select class="eqLogicAttr form-control" data-l1key="object_id">
                                <option value="">{{Aucun}}</option>
                                <?php
                                foreach (jeeObject::buildTree(null, false) as $object) {
                                    echo '<option value="' . $object->getId() . '">' . str_repeat('&nbsp;&nbsp;', $object->getConfiguration('parentNumber')) . $object->getName() . '</option>';
                                }
                                ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="col-sm-2 control-label">{{Statut}}</label>
                        <div class="col-sm-6">
                            <label class="checkbox-inline"><input type="checkbox" class="eqLogicAttr" data-l1key="isEnable" checked /> {{Activé}}</label>
                            <label class="checkbox-inline"><input type="checkbox" class="eqLogicAttr" data-l1key="isVisible" checked /> {{Visible}}</label>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="col-sm-2 control-label">{{Catégorie}}</label>
                        <div class="col-sm-8">
                            <?php
                            foreach (jeedom::getConfiguration('eqLogic:category') as $key => $value) {
                                echo '<label class="checkbox-inline">';
                                echo '<input type="checkbox" class="eqLogicAttr" data-l1key="category" data-l2key="' . $key . '"' . ($key == 'energy' ? ' checked' : '') . ' /> ' . $value['name'];
                                echo '</label>';
                            }
                            ?>
                        </div>
                    </div>
                </fieldset>
            </div>

            <div role="tabpanel" class="tab-pane" id="tab_transport">
                <div class="row">
                    <div class="col-md-6">
                        <legend><i class="fas fa-plug"></i> {{Connexion}}</legend>
                        <fieldset>
                            <div class="form-group">
                                <label class="col-sm-4 control-label">{{Mode de connexion}}</label>
                                <div class="col-sm-8">
                                    <select id="sel_mode_connexion" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="mode_connexion">
                                        <option value="local">{{Local (Chemin B — recommandé v1, zéro-injection)}}</option>
                                        <option value="cloud">{{Cloud (Chemin A — simple mais latent ~90s)}}</option>
                                    </select>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="col-sm-4 control-label">{{Identifiant appareil (device_id)}}</label>
                                <div class="col-sm-8">
                                    <input class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="device_id" />
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="col-sm-4 control-label">{{Product key}}</label>
                                <div class="col-sm-8">
                                    <input class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="product_key" />
                                </div>
                            </div>
                        </fieldset>
                    </div>

                    <div class="col-md-6">
                        <div id="bloc_cloud">
                            <legend><i class="fas fa-cloud"></i> {{Identifiants Cloud (Chemin A)}}</legend>
                            <fieldset>
                                <div class="form-group">
                                    <label class="col-sm-4 control-label">{{Broker cloud}}</label>
                                    <div class="col-sm-8">
                                        <input class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="cloud_host" placeholder="mqtt-eu.zen-iot.com" />
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label class="col-sm-4 control-label">{{Port}}</label>
                                    <div class="col-sm-8">
                                        <input type="number" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="cloud_port" placeholder="1883" />
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label class="col-sm-4 control-label">{{Clé Cloud d'Autorisation}}</label>
                                    <div class="col-sm-8">
                                        <input type="password" class="eqLogicAttr inputPassword form-control" data-l1key="configuration" data-l2key="cloud_auth_key" />
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label class="col-sm-4 control-label">{{Numéro de série}}</label>
                                    <div class="col-sm-8">
                                        <input class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="cloud_device_serial" />
                                    </div>
                                </div>
                            </fieldset>
                        </div>

                        <div id="bloc_local">
                            <legend><i class="fas fa-network-wired"></i> {{Broker local (Chemin B)}}</legend>
                            <fieldset>
                                <div class="form-group">
                                    <label class="col-sm-4 control-label">{{IP Mosquitto local}}</label>
                                    <div class="col-sm-8">
                                        <input class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="local_host" placeholder="192.168.1.50" />
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label class="col-sm-4 control-label">{{Port}}</label>
                                    <div class="col-sm-8">
                                        <input type="number" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="local_port" placeholder="1883" />
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label class="col-sm-4 control-label">{{Identifiant (optionnel)}}</label>
                                    <div class="col-sm-8">
                                        <input class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="local_username" />
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label class="col-sm-4 control-label">{{Mot de passe}}</label>
                                    <div class="col-sm-8">
                                        <input type="password" class="eqLogicAttr inputPassword form-control" data-l1key="configuration" data-l2key="local_password" />
                                    </div>
                                </div>
                                <div class="alert alert-warning">
                                    {{Prérequis Chemin B : relais DNS mq.zen-iot.com -> Mosquitto local + reconfiguration Bluetooth de l'appareil (Solarflow Bluetooth Manager / Zendure Cloud Disconnector). Voir README.}}
                                </div>
                            </fieldset>
                        </div>
                    </div>
                </div>
            </div>

            <div role="tabpanel" class="tab-pane" id="tab_sources">
                <fieldset>
                    <legend><i class="fas fa-satellite-dish"></i> {{Sources de lecture (jamais d'ID en dur)}}</legend>
                    <div class="alert alert-info">
                        {{Chaque source pointe vers une commande "info" existante (pince, téléinfo, Tempo, prévision solaire...) via le sélecteur natif Jeedom.}}
                    </div>
                    <?php
                    $sources = [
                        'src_intensite' => '{{Pince ampèremétrique (intensité)}}',
                        'src_grid_papp' => '{{PAPP réseau}}',
                        'src_imax_abonnement' => '{{Imax abonnement}}',
                        'src_periode_tarif' => '{{Période tarifaire (PTEC / HP-HC)}}',
                        'src_tempo_now' => '{{Tempo — période courante (HP/HC + couleur, ex. HCJB)}}',
                        'src_tempo_j' => '{{Tempo — couleur du jour}}',
                        'src_tempo_j1' => '{{Tempo — couleur de demain (J+1)}}',
                        'src_prevision_solaire' => '{{Prévision solaire (kWh)}}',
                    ];
                    foreach ($sources as $key => $label) {
                        echo '<div class="form-group">';
                        echo '<label class="col-sm-3 control-label">' . $label . '</label>';
                        echo '<div class="col-sm-6">';
                        echo '<div class="input-group">';
                        echo '<input type="text" class="eqLogicAttr form-control roundedLeft" data-l1key="configuration" data-l2key="' . $key . '" placeholder="{{Aucune commande sélectionnée}}" />';
                        echo '<span class="input-group-btn">';
                        echo '<a class="btn btn-default listCmdInfo roundedRight" title="{{Choisir une commande}}"><i class="fas fa-list-alt"></i></a>';
                        echo '</span>';
                        echo '</div>';
                        echo '</div>';
                        echo '</div>';
                    }
                    ?>
                </fieldset>
            </div>

            <div role="tabpanel" class="tab-pane" id="tab_comportement">
                <fieldset>
                    <legend><i class="fas fa-balance-scale"></i> {{Comportement}}</legend>
                    <div class="form-group">
                        <label class="col-sm-3 control-label">{{Marge anti-injection (W)}}</label>
                        <div class="col-sm-2">
                            <input type="number" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="marge_anti_injection" placeholder="30" />
                        </div>
                        <label class="col-sm-3 control-label">{{Cooldown (s)}}</label>
                        <div class="col-sm-2">
                            <input type="number" step="0.1" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="cooldown_anti_injection" placeholder="2" />
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="col-sm-3 control-label">{{Hystérésis (W)}}</label>
                        <div class="col-sm-2">
                            <input type="number" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="hysteresis_anti_injection" placeholder="15" />
                        </div>
                        <label class="col-sm-3 control-label">{{Limites sortie min/max (W)}}</label>
                        <div class="col-sm-1">
                            <input type="number" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="limite_min_w" placeholder="0" />
                        </div>
                        <div class="col-sm-1">
                            <input type="number" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="limite_max_w" placeholder="1200" />
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="col-sm-3 control-label">{{Imax (A)}}</label>
                        <div class="col-sm-2">
                            <input type="number" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="imax_ampere" />
                        </div>
                        <label class="col-sm-3 control-label">{{Réseau}}</label>
                        <div class="col-sm-2">
                            <select class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="phases">
                                <option value="mono">{{Monophasé}}</option>
                                <option value="tri">{{Triphasé}}</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="col-sm-3 control-label">{{Seuil jauge ambre / rouge (%)}}</label>
                        <div class="col-sm-2">
                            <input type="number" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="seuil_intensite_ambre" placeholder="70" />
                        </div>
                        <div class="col-sm-2">
                            <input type="number" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="seuil_intensite_rouge" placeholder="90" />
                        </div>
                    </div>
                </fieldset>
            </div>

            <div role="tabpanel" class="tab-pane" id="tab_ihm">
                <fieldset>
                    <legend><i class="fas fa-palette"></i> {{IHM}}</legend>
                    <div class="form-group">
                        <label class="col-sm-3 control-label">{{Dashboard}}</label>
                        <div class="col-sm-3">
                            <select class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="template_dashboard">
                                <option value="flux">{{Flux}}</option>
                                <option value="condense">{{Condensé}}</option>
                                <option value="historique">{{Historique}}</option>
                            </select>
                        </div>
                        <label class="col-sm-2 control-label">{{Animations}}</label>
                        <div class="col-sm-2">
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

    // Sélecteur natif de commande (onglet Sources) — pattern confirmé fonctionnel
    // dans plugins/portail_gen : le champ visible stocke directement la référence
    // humaine #[Objet][Eq][Cmd]#, résolue côté PHP via cmd::byString().
    $('.eqLogic').off('click', '.listCmdInfo').on('click', '.listCmdInfo', function () {
        var el = $(this).closest('.form-group').find('.eqLogicAttr');
        jeedom.cmd.getSelectModal({ cmd: { type: 'info' } }, function (result) {
            el.value(result.human);
        });
    });
});
</script>
<?php include_file('core', 'plugin.template', 'js'); ?>
