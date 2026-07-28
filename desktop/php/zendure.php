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
    'eqType' => $plugin->getId(),
    // Onglet "Commandes" (cf. <script> addCmdToTable() en bas de page) :
    // filtre côté JS sur cette liste -- ~230 commandes existent par eqLogic
    // (curées + télémétrie brute auto-créée par callback.php), mais la table
    // native (une ligne complète par commande, cf. plugin.template.js) n'est
    // pas exploitable avec un tel volume (constaté 2026-07-26). Seules les
    // commandes "curées" (celles que le plugin utilise réellement) restent
    // affichées ici ; le brut (packData*, debug...) reste accessible via le
    // bouton "Capture télémétrie complète" (onglet Comportement), qui couvre
    // déjà ce besoin sans polluer cette table.
    'curatedCmdLogicalIds' => array_keys(array_merge(zendure::INFO_COMMANDS, zendure::ACTION_COMMANDS, zendure::COMPUTED_COMMANDS)),
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
            <li role="presentation"><a href="#tab_sources" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-satellite-dish"></i> {{Sources}}</a></li>
            <li role="presentation"><a href="#tab_comportement" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-balance-scale"></i> {{Comportement}}</a></li>
            <li role="presentation"><a href="#tab_ihm" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-palette"></i> {{IHM}}</a></li>
            <li role="presentation"><a href="#commandtab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-list"></i> {{Commandes}}</a></li>
        </ul>

        <form class="form-horizontal tab-content" id="div_eqLogic">
            <div role="tabpanel" class="tab-pane active" id="tab_general">
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
                                        <input class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="cloud_host" placeholder="mqtteu.zen-iot.com" />
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label class="col-sm-4 control-label">{{Port}}</label>
                                    <div class="col-sm-8">
                                        <input type="number" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="cloud_port" placeholder="1883" />
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label class="col-sm-4 control-label">{{Nom d'utilisateur MQTT}}</label>
                                    <div class="col-sm-8">
                                        <input class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="cloud_username" />
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label class="col-sm-4 control-label">{{Mot de passe / clé MQTT}}</label>
                                    <div class="col-sm-8">
                                        <input type="password" class="eqLogicAttr inputPassword form-control" data-l1key="configuration" data-l2key="cloud_auth_key" />
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label class="col-sm-4 control-label">{{Client ID MQTT (avancé)}}</label>
                                    <div class="col-sm-8">
                                        <input class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="cloud_client_id" placeholder="{{auto-généré si vide}}" />
                                        <span class="help-block" style="margin-bottom:0;">{{Laisser vide sauf besoin de diagnostic : certains brokers limitent la réception de télémétrie à un clientId précis lié au compte.}}</span>
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
                        'src_prevision_solaire' => '{{Prévision solaire J+0 (Wh)}}',
                        'src_prevision_solaire_j1' => '{{Prévision solaire J+1 (Wh)}}',
                        'src_depense_jour' => '{{Dépense jour (€) — ex. Teleinfo STAT_TODAY_INDEX00_COUT — laisser vide pour calculer en interne}}',
                        'src_depense_veille' => '{{Dépense veille (€) — ex. Teleinfo STAT_YESTERDAY_INDEX00_COUT — laisser vide pour calculer en interne}}',
                        'src_injection' => '{{Injection maison (Zendure) — laisser vide pour utiliser la valeur par défaut}}',
                        'src_solaire' => '{{Production solaire (dashboard) — laisser vide pour utiliser la valeur par défaut}}',
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
                <div class="alert alert-info">
                    <strong>{{À quoi sert cet onglet}}</strong>
                    <ul style="margin-bottom:0;">
                        <li>{{Pince ampèremétrique / PAPP réseau : la mesure de puissance au niveau du compteur EDF. C'est l'entrée principale de la boucle anti-injection rapide (onglet Comportement) — sans elle, le pilotage automatique ne peut pas fonctionner.}}</li>
                        <li>{{Imax abonnement : intensité max souscrite (A), sert à la jauge d'intensité du dashboard.}}</li>
                        <li>{{Période tarifaire (PTEC/HP-HC) et les 3 sources Tempo : utilisées pour le calcul du gain (€) et la stratégie de charge nocturne (charger davantage si demain est en jour Tempo Rouge).}}</li>
                        <li>{{Prévision solaire : utilisée par la boucle lente (hors périmètre direct du démon) pour moduler le SOC cible nocturne.}}</li>
                        <li>{{Injection maison (Zendure) / Production solaire : le dashboard utilise par défaut les commandes curées "injected_power"/"solar_power" (télémétrie Zendure). Le démon capture désormais TOUTE la télémétrie brute reçue (une commande "info" par valeur, créée automatiquement au premier signalement, visible dans la liste des commandes de cet équipement) — si vous jugez une autre commande plus fiable (ex. la clé brute "outputHomePower", ou une pince externe type Tableau_Zendure), sélectionnez-la ici. Le bilan Réseau utilise déjà le même principe via "PAPP réseau".}}</li>
                        <li>{{Toutes ces sources doivent déjà exister comme commandes "info" ailleurs dans Jeedom (téléinfo, RTE Tempo, prévision solaire...) — ce plugin ne les crée pas, il les référence. Exception : les commandes de télémétrie brute Zendure, elles, sont créées automatiquement par ce plugin lui-même.}}</li>
                    </ul>
                </div>
            </div>

            <div role="tabpanel" class="tab-pane" id="tab_comportement">
                <fieldset>
                    <legend><i class="fas fa-balance-scale"></i> {{Comportement}}</legend>
                    <div class="form-group">
                        <label class="col-sm-3 control-label">{{Connexion active}}</label>
                        <div class="col-sm-2">
                            <input type="checkbox" class="eqLogicAttr" data-l1key="configuration" data-l2key="connexion_active" checked />
                        </div>
                        <label class="col-sm-3 control-label">{{Anti-injection active}}</label>
                        <div class="col-sm-2">
                            <input type="checkbox" class="eqLogicAttr" data-l1key="configuration" data-l2key="anti_injection_active" checked />
                        </div>
                    </div>
                    <div class="form-group">
                        <div class="col-sm-6">
                            <a id="bt_disableSmartMode" class="btn btn-warning"><i class="fas fa-brain"></i> {{Désactiver le mode intelligent}}</a>
                            <span class="help-block" style="margin-top:4px;">{{Si l'appli mobile Zendure a basculé l'appareil en "Mode intelligent", nos commandes manuelles (charge/décharge/limites) sont ignorées sans erreur visible. Ce bouton le désactive directement.}}</span>
                        </div>
                    </div>
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
                        <label class="col-sm-3 control-label">{{Cooldown import (s)}}</label>
                        <div class="col-sm-2">
                            <input type="number" step="1" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="cooldown_import_anti_injection" placeholder="15" />
                        </div>
                        <label class="col-sm-3 control-label">{{Tolérance import (%)}}</label>
                        <div class="col-sm-2">
                            <input type="number" step="1" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="tolerance_import_anti_injection" placeholder="10" />
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="col-sm-3 control-label">{{Limites sortie min/max (W)}}</label>
                        <div class="col-sm-1">
                            <input type="number" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="limite_min_w" placeholder="0" />
                        </div>
                        <div class="col-sm-1">
                            <input type="number" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="limite_max_w" placeholder="1200" />
                        </div>
                        <label class="col-sm-3 control-label">{{Limite entrée max (W)}}</label>
                        <div class="col-sm-2">
                            <input type="number" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="limite_entree_max_w" placeholder="1200" />
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
                    <div class="form-group">
                        <label class="col-sm-3 control-label">{{Cron HP en simulation}}</label>
                        <div class="col-sm-2">
                            <input type="checkbox" class="eqLogicAttr" data-l1key="configuration" data-l2key="cron_hp_dry_run" checked />
                        </div>
                    </div>
                </fieldset>
                <fieldset>
                    <legend><i class="fas fa-database"></i> {{Fréquence de mise à jour}}</legend>
                    <div class="form-group">
                        <label class="col-sm-3 control-label">{{Intervalle minimum (s)}}</label>
                        <div class="col-sm-2">
                            <input type="number" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="telemetry_min_interval_s" placeholder="300" />
                        </div>
                        <label class="col-sm-3 control-label">{{Tolérance de bruit}}</label>
                        <div class="col-sm-2">
                            <input type="number" step="0.1" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="telemetry_noise_threshold" placeholder="3" />
                        </div>
                    </div>
                    <div class="form-group">
                        <div class="col-sm-6">
                            <a id="bt_debugCapture" class="btn btn-default"><i class="fas fa-satellite-dish"></i> {{Capture télémétrie complète (1h)}}</a>
                        </div>
                    </div>
                </fieldset>
                <fieldset>
                    <legend><i class="fas fa-bluetooth-b"></i> {{Secours Bluetooth (BLE)}}</legend>
                    <div class="form-group">
                        <label class="col-sm-3 control-label">{{Secours BLE actif}}</label>
                        <div class="col-sm-2">
                            <input type="checkbox" class="eqLogicAttr" data-l1key="configuration" data-l2key="ble_failover_active" />
                        </div>
                        <label class="col-sm-3 control-label">{{Adresse MAC Bluetooth}}</label>
                        <div class="col-sm-3">
                            <input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="ble_address" placeholder="94:C9:60:C7:5F:EA" />
                        </div>
                    </div>
                    <div class="alert alert-info">
                        {{Désactivé par défaut. Quand la télémétrie MQTT/cloud devient muette (WiFi du boîtier instable) et que cette option est cochée, le démon tente une lecture ponctuelle en direct par Bluetooth (adresse MAC ci-dessus, cf. app Zendure ou un scan BLE pour la trouver) -- lecture seule, aucune commande n'est jamais envoyée par ce canal. Cadencé sur le cron HP (5 min), PAS une connexion permanente : volontairement occasionnel et borné dans le temps pour ne pas monopoliser un adaptateur Bluetooth déjà utilisé par ailleurs (ex. relevés de température BLE via TheengsGateway) -- ne se déclenche de toute façon que si la télémétrie est déjà confirmée muette, jamais en fonctionnement normal.}}
                    </div>
                </fieldset>
                <fieldset>
                    <legend><i class="fas fa-euro-sign"></i> {{Tarifs}}</legend>
                    <div class="form-group">
                        <label class="col-sm-3 control-label">{{Type de contrat}}</label>
                        <div class="col-sm-3">
                            <select id="sel_type_contrat" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="type_contrat">
                                <option value="base">{{Base}}</option>
                                <option value="hphc">{{Heures Pleines / Heures Creuses}}</option>
                                <option value="tempo" selected>{{Tempo}}</option>
                            </select>
                        </div>
                        <label class="col-sm-3 control-label">{{Mise à jour auto (mensuelle)}}</label>
                        <div class="col-sm-2">
                            <input type="checkbox" class="eqLogicAttr" data-l1key="configuration" data-l2key="maj_tarifs_auto" />
                        </div>
                    </div>
                    <div id="bloc_tarif_base">
                        <div class="form-group">
                            <label class="col-sm-3 control-label">{{Prix du kWh (€)}}</label>
                            <div class="col-sm-2">
                                <input type="number" step="0.0001" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="tarif_base" />
                            </div>
                        </div>
                    </div>
                    <div id="bloc_tarif_hphc">
                        <div class="form-group">
                            <label class="col-sm-3 control-label">{{Prix HC (€/kWh)}}</label>
                            <div class="col-sm-2">
                                <input type="number" step="0.0001" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="tarif_hphc_hc" />
                            </div>
                            <label class="col-sm-3 control-label">{{Prix HP (€/kWh)}}</label>
                            <div class="col-sm-2">
                                <input type="number" step="0.0001" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="tarif_hphc_hp" />
                            </div>
                        </div>
                    </div>
                    <div id="bloc_tarif_tempo">
                        <div class="form-group">
                            <label class="col-sm-3 control-label">{{Bleu — HC / HP (€/kWh)}}</label>
                            <div class="col-sm-2">
                                <input type="number" step="0.0001" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="tarif_tempo_bleu_hc" />
                            </div>
                            <div class="col-sm-2">
                                <input type="number" step="0.0001" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="tarif_tempo_bleu_hp" />
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="col-sm-3 control-label">{{Blanc — HC / HP (€/kWh)}}</label>
                            <div class="col-sm-2">
                                <input type="number" step="0.0001" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="tarif_tempo_blanc_hc" />
                            </div>
                            <div class="col-sm-2">
                                <input type="number" step="0.0001" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="tarif_tempo_blanc_hp" />
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="col-sm-3 control-label">{{Rouge — HC / HP (€/kWh)}}</label>
                            <div class="col-sm-2">
                                <input type="number" step="0.0001" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="tarif_tempo_rouge_hc" />
                            </div>
                            <div class="col-sm-2">
                                <input type="number" step="0.0001" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="tarif_tempo_rouge_hp" />
                            </div>
                        </div>
                    </div>
                    <div class="alert alert-info">
                        {{Ces prix alimentent le calcul du gain/dépense (€). Toujours éditables à la main : la mise à jour auto (si activée) les écrase une fois par mois depuis open-dpe.fr (source qui couvre Base/HP-HC/Tempo en un seul appel), avec un bémol de fiabilité assumé — elle est alimentée par un pipeline PDF→LLM mensuel côté source. En cas d'échec (réseau, format inattendu), les prix existants ne sont jamais effacés ni écrasés par une valeur invalide : la saisie manuelle reste le filet de sécurité.}}
                    </div>
                </fieldset>
                <fieldset>
                    <legend><i class="fas fa-moon"></i> {{Stratégie nuit}}</legend>
                    <div class="form-group">
                        <label class="col-sm-3 control-label">{{Stratégie nuit active}}</label>
                        <div class="col-sm-2">
                            <input type="checkbox" class="eqLogicAttr" data-l1key="configuration" data-l2key="strategie_nuit_active" />
                        </div>
                        <label class="col-sm-3 control-label">{{Stratégie nuit en simulation}}</label>
                        <div class="col-sm-2">
                            <input type="checkbox" class="eqLogicAttr" data-l1key="configuration" data-l2key="strategie_nuit_dry_run" checked />
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="col-sm-3 control-label">{{Fin de la charge nuit (repli sans tarif HP/HC)}}</label>
                        <div class="col-sm-2">
                            <input type="time" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="heure_fin_charge_nuit" placeholder="06:00" />
                        </div>
                        <label class="col-sm-3 control-label">{{Puissance de charge nuit (W)}}</label>
                        <div class="col-sm-2">
                            <input type="number" step="1" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="charge_power_nuit_w" placeholder="1200" />
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="col-sm-3 control-label">{{Capacité batterie (kWh)}}</label>
                        <div class="col-sm-2">
                            <input type="number" step="0.1" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="batterie_capacite_kwh" placeholder="{{vide = ancienne logique à seuils fixes}}" />
                        </div>
                        <label class="col-sm-3 control-label">{{Retour HC le soir (modèle kWh)}}</label>
                        <div class="col-sm-2">
                            <input type="time" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="heure_debut_hc_soir" placeholder="22:00" />
                        </div>
                    </div>
                    <div class="alert alert-info">
                        {{Cron à 00h00 : bascule l'appareil en charge (mode Entrée) et fixe un SOC cible (SOC maximum) selon la couleur Tempo de demain et la prévision solaire du jour à venir (source "Prévision solaire", cf. onglet Sources — nécessite un plugin externe type Solcast). Tempo Rouge demain -> toujours charge à 100% (le tarif Rouge HP est assez élevé pour préférer se couvrir plutôt que parier sur la prévision solaire). Sinon deux logiques possibles : (1) Capacité batterie (kWh) renseignée -> modèle en kWh réels -- principe : un électron solaire ne coûte rien, un électron HC stocké la nuit a un coût, donc on ne charge que ce que le solaire de demain ne couvrira pas. Cible = (consommation typique du foyer sur la fenêtre HP réelle réveil→retour HC soir, médiane glissante 7 jours d'historique) moins la prévision solaire du lendemain, jamais négatif, ramené en % de la capacité (mini 20%, jamais plus de 100%). Sans tarif HP/HC configuré (contrat Base), la fenêtre couvre toute la journée (0h-24h) faute de créneau "cher" identifiable — le plugin reste utilisable sans cet abonnement. Retombe automatiquement sur la logique (2) tant que l'historique est insuffisant (installation récente). (2) Capacité batterie vide, ou historique pas encore assez profond -> ancienne logique à seuils fixes (Tempo Bleu + prévision solaire ≥ 4 kWh -> 60% ; sinon 80%). Piège de fraîcheur du cache géré automatiquement : si la prévision "J+0" de la source n'a pas encore été rafraîchie aujourd'hui (typique avant le rafraîchissement matinal de Solcast, souvent ~6h), la "J+1" du cache d'hier est utilisée à la place — elle correspond alors au bon jour calendaire. Puissance de charge nuit (par défaut 1200W, la limite AC du Hyper 2000) : cible envoyée à l'automation de charge de l'appareil -- sans cette commande explicite, l'appareil ne charge jamais réellement (bug corrigé le 2026-07-22, cf. README ; effet réel sur la batterie toujours pas confirmé en conditions réelles à ce jour, cf. docs/brief_strategie_charge.md). Stratégie nuit en simulation : logue la décision (niveau info, préfixe "[cronStrategieNuit] [SIMULATION]") sans jamais toucher à l'appareil tant que cette case est cochée. Réveil du mode charge (cron HP, toutes les 5 min) : si un tarif HP/HC ou Tempo est configuré (onglet Sources), c'est le passage réel en HP côté fournisseur (PTEC) qui déclenche le retour en décharge — précis, pas d'horaire supposé. Sans tarif HP/HC configuré (contrat Base), le champ "Fin de la charge nuit" ci-dessus (défaut 06:00) sert de repli, ainsi que pour le début de la fenêtre HP du modèle kWh. "Retour HC le soir" (défaut 22:00, horaire standard Tempo/HP-HC) ferme cette même fenêtre HP côté modèle kWh uniquement.}}
                    </div>
                </fieldset>
                <div class="alert alert-info">
                    <strong>{{Logique de la boucle anti-injection}}</strong>
                    <p>{{target = clamp(0, limite_max, grid_power + injected_power - marge), recalculé en absolu à chaque mesure de la pince, jamais depuis une valeur mémorisée. Convention : grid_power > 0 = import réseau (normal), < 0 = injection (à éviter). Deux sens, deux cadences (corrigé le 2026-07-28 : avant cette date, le sens import ne faisait rien du tout et une coupure d'urgence pouvait rester bloquée jusqu'à 5 min, cf. brief) : côté injection (grid < marge), réactivité maximale — cooldown court, pas de zone morte, bypass total en cas d'injection avérée (urgent_injection_w). Côté import (grid >= marge), cadence volontairement plus lente (cooldown import) + zone morte en % autour de la dernière valeur envoyée, pour laisser l'appareil se stabiliser entre deux corrections et ne pas re-déclencher une commande pour une variation négligeable — réagir aussi vite que côté injection dans ce sens a un historique d'oscillation réseau.}}</p>
                    <ul style="margin-bottom:0;">
                        <li>{{Connexion active : décochez pour couper complètement la connexion MQTT du démon vers ce boîtier (déconnexion propre, y compris la sortie du "Mode intelligent" sur l'appli mobile) — utile pour cohabiter avec un autre pilote du même compte cloud (ex. Home Assistant) : deux clients connectés simultanément avec les mêmes identifiants se coupent mutuellement la session. Décocher ici libère la session sans désinstaller le plugin ; les autres réglages de cet équipement restent intacts pour une réactivation ultérieure.}}</li>
                        <li>{{Anti-injection active : décochez pour couper la boucle rapide ET le cron HP (mais pas la connexion elle-même) — le plugin continue de recevoir la télémétrie et d'afficher le dashboard, mais ne commande plus jamais la limite de sortie. Utile si un autre outil (scénario, HA...) pilote déjà cet appareil et que seule la régulation doit être neutralisée.}}</li>
                        <li>{{Marge anti-injection (W) : objectif de puissance importée du réseau à maintenir (jamais tout à 0, pour absorber les variations entre deux mesures de la pince). Ex. 30W.}}</li>
                        <li>{{Cooldown (s) : délai minimum entre deux commandes côté injection (grid < marge). Ignoré en cas d'injection avérée (seuil urgent, non exposé dans cet onglet — cf. urgent_injection_w).}}</li>
                        <li>{{Cooldown import (s) : délai minimum entre deux commandes côté import (grid >= marge, pas de risque d'injection immédiat) — volontairement plus long que le cooldown ci-dessus, le temps que l'appareil se stabilise sur la commande précédente. Défaut 15s.}}</li>
                        <li>{{Tolérance import (%) : ignore une correction côté import si la nouvelle cible reste à +/- X% de la dernière valeur commandée. Défaut 10%. Ne s'applique jamais côté injection/urgence.}}</li>
                        <li>{{Limites sortie min/max (W) : bornes physiques/souhaitées de la limite de sortie envoyée à la batterie (ex. 0 à 1200W pour un Hyper 2000).}}</li>
                        <li>{{Cette boucle rapide ne joue que sur la décharge (plancher 0W) : elle ne bascule jamais en charge, conformément au scénario Jeedom de référence — la charge programmée reste une décision distincte (stratégie nuit HC, hors périmètre de cette boucle).}}</li>
                        <li>{{Cron HP en simulation : reproduit la branche HP du scénario (toutes les 5 min, même formule que la boucle rapide) mais se contente de logger ce qu'il ferait (log plugin, niveau info, préfixe "[cronOptimisationHP] [SIMULATION]") sans jamais toucher à l'appareil tant que cette case est cochée.}}</li>
                        <li>{{Imax (A) / Réseau (mono/tri) / Seuils jauge : alimentent uniquement la jauge d'intensité du dashboard "Condensé", aucun impact sur la régulation elle-même.}}</li>
                        <li>{{Intervalle minimum (s) : le démon ne pousse une valeur de télémétrie vers Jeedom que si elle a changé depuis le dernier envoi, sauf si ce délai est dépassé (heartbeat, pour ne jamais laisser une commande "morte" trop longtemps). N'affecte pas l'anti-injection elle-même (qui reste temps réel).}}</li>
                        <li>{{Tolérance de bruit : un écart numérique en dessous de cette valeur n'est pas considéré comme un changement (ex. 3 = les puissances qui frémissent de quelques W en permanence ne déclenchent plus un envoi à chaque trame). Sans tolérance, seules les valeurs stables (SOC%, état...) bénéficient vraiment du filtre.}}</li>
                        <li>{{Capture télémétrie complète (1h) : désactive temporairement ce filtre — tout est poussé sans filtrage pendant 1h (diagnostic), puis le filtrage normal reprend automatiquement.}}</li>
                    </ul>
                </div>
            </div>

            <div role="tabpanel" class="tab-pane" id="tab_ihm">
                <fieldset>
                    <legend><i class="fas fa-palette"></i> {{IHM}}</legend>
                    <div class="form-group">
                        <label class="col-sm-3 control-label">{{Dashboard}}</label>
                        <div class="col-sm-3">
                            <select class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="template_dashboard">
                                <option value="condense">{{Condensé}}</option>
                                <option value="flux">{{Flux}}</option>
                                <option value="historique">{{Historique}}</option>
                            </select>
                        </div>
                        <label class="col-sm-2 control-label">{{Animations}}</label>
                        <div class="col-sm-2">
                            <input type="checkbox" class="eqLogicAttr" data-l1key="configuration" data-l2key="animations_actives" checked />
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="col-sm-3 control-label">{{Panneau debug (widget Flux)}}</label>
                        <div class="col-sm-2">
                            <input type="checkbox" class="eqLogicAttr" data-l1key="configuration" data-l2key="debug_widget_actif" />
                        </div>
                    </div>
                </fieldset>
                <div class="alert alert-info">
                    <strong>{{À quoi sert cet onglet}}</strong>
                    <ul style="margin-bottom:0;">
                        <li>{{Condensé : tuile compacte (anneau SOC, 4 flux, jauge intensité, gain €).}}</li>
                        <li>{{Flux : losange animé Solaire/Réseau/Maison/Batterie, jauge d'intensité, Tempo J/J+1, indicateurs financiers et curseurs de pilotage (limite de sortie AC, SOC minimum).}}</li>
                        <li>{{Historique : pas encore implémenté — en le sélectionnant, l'équipement retombe sur l'affichage générique Jeedom (liste de commandes), sans plantage mais sans le rendu visuel prévu à terme.}}</li>
                        <li>{{Animations : désactivez si vous préférez un rendu statique (utile sur mobile/tablette).}}</li>
                        <li>{{Panneau debug : ajoute un bandeau repliable en bas du widget Flux avec les dernières lignes des logs démon + plugin, actualisées automatiquement (~3s). Pensez à le désactiver une fois le diagnostic terminé (appels réseau périodiques tant qu'il est ouvert).}}</li>
                    </ul>
                </div>

                <legend>{{Aperçus (statiques, données d'exemple)}}</legend>
                <div class="row">
                    <div class="col-md-4 text-center">
                        <p><strong>{{Condensé}}</strong> <span class="label label-success">{{implémenté}}</span></p>
                        <div class="zc-preview zendure-condense">
                            <div class="zc-header">
                                <span class="zc-name">Panneaux solaires</span>
                                <span class="zc-mode">Décharge</span>
                            </div>
                            <div class="zc-body">
                                <div class="zc-soc-ring">
                                    <svg viewBox="0 0 36 36">
                                        <path class="zc-soc-bg" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" />
                                        <path class="zc-soc-fg" stroke-dasharray="74, 100" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" />
                                    </svg>
                                    <div class="zc-soc-value">74%</div>
                                </div>
                                <ul class="zc-flux">
                                    <li><span class="zc-flux-label">☀ {{Solaire}}</span><span class="zc-flux-value">1200 W</span></li>
                                    <li><span class="zc-flux-label">⇅ {{Réseau}}</span><span class="zc-flux-value">30 W</span></li>
                                    <li><span class="zc-flux-label">⌂ {{Maison}}</span><span class="zc-flux-value">950 W</span></li>
                                    <li><span class="zc-flux-label">⇥ {{Injecté}}</span><span class="zc-flux-value">0 W</span></li>
                                </ul>
                            </div>
                            <div class="zc-intensite">
                                <div class="zc-intensite-bar" style="width:45%;background:#4cd964"></div>
                            </div>
                            <div class="zc-footer">
                                <span>{{Gain jour}} : 1.24 €</span>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-4 text-center">
                        <p><strong>{{Flux}}</strong> <span class="label label-success">{{implémenté}}</span></p>
                        <div class="zendure-flux zf-preview-static">
                            <div class="zf-header">
                                <div class="zf-badges">
                                    <span class="zf-badge zf-badge-tarif"><i class="fas fa-sun"></i> HC</span>
                                    <span class="zf-badge"><i class="fas fa-arrow-down"></i> Décharge</span>
                                </div>
                            </div>
                            <div class="zf-diagram">
                                <div class="zf-node zf-top"><i class="fas fa-sun" style="color:#EF9F27"></i><span class="zf-value">1200 W</span><span class="zf-label">{{Solaire}}</span></div>
                                <div class="zf-node zf-left"><i class="fas fa-plug" style="color:#378ADD"></i><span class="zf-value" style="color:#378ADD">↓ 30 W</span><span class="zf-label">{{Réseau}}</span></div>
                                <div class="zf-hub"><i class="fas fa-bolt"></i></div>
                                <div class="zf-node zf-right zf-node-lg"><i class="fas fa-home" style="color:#D4537E"></i><span class="zf-value">950 W</span><span class="zf-label"><i class="fas fa-leaf"></i> 97%</span></div>
                                <div class="zf-node zf-bottom"><span class="zf-value">74%</span><i class="fas fa-battery-three-quarters" style="color:#ED93B1"></i><span class="zf-value-sm" style="color:#D4537E">↓ 220 W</span></div>
                            </div>
                            <div class="zf-row2">
                                <div class="zf-card zf-gauge">
                                    <svg viewBox="0 0 100 62" class="zf-gauge-svg" aria-hidden="true">
                                        <path d="M14,52 A36,36 0 0 1 71.2,22.9" fill="none" stroke="#639922" stroke-width="7" stroke-linecap="round" />
                                        <path d="M71.2,22.9 A36,36 0 0 1 84.2,40.9" fill="none" stroke="#EF9F27" stroke-width="7" />
                                        <path d="M84.2,40.9 A36,36 0 0 1 86,52" fill="none" stroke="#E24B4A" stroke-width="7" stroke-linecap="round" />
                                        <line x1="50" y1="52" x2="35" y2="27" stroke="var(--zf-text)" stroke-width="2.5" stroke-linecap="round" />
                                        <circle cx="50" cy="52" r="3.5" fill="var(--zf-text)" />
                                    </svg>
                                    <div class="zf-gauge-text"><div class="zf-gauge-value">6.2 <span>A</span></div><div class="zf-gauge-marge"><i class="fas fa-shield-alt"></i> {{marge}} 23.8 A</div><div class="zf-gauge-label">{{Intensité réseau}}</div></div>
                                </div>
                                <div class="zf-card zf-tempo">
                                    <div class="zf-tempo-row"><span>{{Aujourd'hui}}</span><span class="zf-pill" style="color:#1D4ED8;background:#BFDBFE">Bleu</span></div>
                                    <div class="zf-tempo-row"><span>{{Demain}}</span><span class="zf-pill" style="color:#854F0B;background:#FDE68A">Blanc</span></div>
                                </div>
                            </div>
                            <div class="zf-row3">
                                <div class="zf-card zf-money zf-money-gain"><div class="zf-money-label"><i class="fas fa-coins"></i> {{Gain}}</div><div class="zf-money-value">+1.24 €</div></div>
                                <div class="zf-card zf-money"><div class="zf-money-label"><i class="fas fa-calendar-minus"></i> {{Veille}}</div><div class="zf-money-value">0.85 €</div></div>
                                <div class="zf-card zf-money"><div class="zf-money-label"><i class="fas fa-calendar-day"></i> {{Jour}}</div><div class="zf-money-value">0.31 €</div></div>
                            </div>
                            <div class="zf-card zf-pilotage">
                                <div class="zf-pilotage-title"><i class="fas fa-sliders-h"></i> {{Pilotage}}</div>
                                <div class="zf-row2">
                                    <div><div class="zf-slider-row"><span>{{Limite sortie AC}}</span><span>800 W</span></div><input type="range" class="zf-slider" value="800" disabled /></div>
                                    <div><div class="zf-slider-row"><span>{{SOC minimum}}</span><span>20%</span></div><input type="range" class="zf-slider" value="20" disabled /></div>
                                </div>
                            </div>
                        </div>
                        <p class="text-muted" style="font-size:11px;">{{Sur le vrai dashboard : diagramme animé (particules dans le sens réel du courant) et curseurs fonctionnels.}}</p>
                    </div>

                    <div class="col-md-4 text-center">
                        <p><strong>{{Historique}}</strong> <span class="label label-default">{{pas encore implémenté}}</span></p>
                        <div class="zh-preview">
                            <div class="zh-bars">
                                <div class="zh-bar" style="height:20%"></div>
                                <div class="zh-bar" style="height:35%"></div>
                                <div class="zh-bar" style="height:55%"></div>
                                <div class="zh-bar" style="height:80%"></div>
                                <div class="zh-bar" style="height:65%"></div>
                                <div class="zh-bar" style="height:40%"></div>
                                <div class="zh-bar" style="height:25%"></div>
                            </div>
                            <div class="zh-footer">{{Solaire}} / {{Maison}} / {{Injection}} — {{Gain cumulé}} : 4.80 €</div>
                        </div>
                        <p class="text-muted" style="font-size:11px;">{{Courbes du jour + totaux, basé sur l'historisation des commandes.}}</p>
                    </div>
                </div>
            </div>

            <div role="tabpanel" class="tab-pane" id="commandtab">
                <div class="alert alert-info">
                    {{Toutes les commandes de cet équipement : les "curées" (utilisées par le plugin, ex. solar_power) et celles créées automatiquement par le démon à partir de la télémétrie brute Zendure (ex. outputHomePower, packData0_socLevel...). Table standard Jeedom (identique aux autres plugins, ex. Zigbee) : Afficher/Historiser/type/etc. directement éditables par commande.}}
                </div>
                <div class="table-responsive">
                    <table id="table_cmd" class="table table-bordered table-condensed">
                    </table>
                </div>
            </div>
        </form>
    </div>
</div>

<style>
/* Aperçu "Condensé" (onglet IHM) : copie de core/template/dashboard/condense/condense.css,
   les classes .zendure-condense/.zc-* sont volontairement identiques au vrai template. */
.zc-preview.zendure-condense {
    display: inline-block;
    text-align: left;
    width: 100%;
    max-width: 340px;
    background: var(--bg-widget, #1e1e1e);
    border-radius: 10px;
    padding: 10px 14px;
    color: var(--text-widget, #eee);
    font-size: 13px;
}
.zc-preview .zc-header { display: flex; justify-content: space-between; font-weight: 600; margin-bottom: 6px; }
.zc-preview .zc-body { display: flex; align-items: center; gap: 12px; }
.zc-preview .zc-soc-ring { position: relative; width: 64px; height: 64px; flex: 0 0 auto; }
.zc-preview .zc-soc-ring svg { width: 100%; height: 100%; transform: rotate(-90deg); }
.zc-preview .zc-soc-bg { fill: none; stroke: rgba(255,255,255,0.12); stroke-width: 3; }
.zc-preview .zc-soc-fg { fill: none; stroke: #4cd964; stroke-width: 3; stroke-linecap: round; }
.zc-preview .zc-soc-value { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 13px; }
.zc-preview .zc-flux { list-style: none; margin: 0; padding: 0; flex: 1 1 auto; }
.zc-preview .zc-flux li { display: flex; justify-content: space-between; padding: 1px 0; }
.zc-preview .zc-intensite { height: 4px; background: rgba(255,255,255,0.12); border-radius: 2px; margin-top: 8px; overflow: hidden; }
.zc-preview .zc-intensite-bar { height: 100%; }
.zc-preview .zc-footer { margin-top: 6px; text-align: right; opacity: 0.85; }

/* Aperçu "Flux" (onglet IHM) : copie du <style> inline de
   core/template/dashboard/cmd.info.string.flux_widget.html (widget de commande,
   cf. zendure::createOrUpdateFluxWidget()) — les classes .zendure-flux/.zf-*
   sont volontairement identiques au vrai template (cf. .zendure-condense
   ci-dessus pour la même convention), à retenir en sync à chaque évolution du
   vrai widget. Pas d'animation SVG (zf-lines/zf-particles) ni de JS de curseur
   ici : c'est un aperçu statique avec des valeurs d'exemple, seuls les nœuds
   et cartes sont repris. */
.zendure-flux {
    --zf-surface-1: rgba(127, 127, 127, 0.08);
    --zf-surface-2: rgba(127, 127, 127, 0.16);
    --zf-text: var(--txt-color, #333);
    --zf-text-muted: var(--placeholder-color, rgba(127, 127, 127, 0.8));
    --zf-border: rgba(127, 127, 127, 0.25);
    width: 100%;
    max-width: 660px;
    background: transparent;
    color: var(--zf-text);
    border-radius: 12px;
    padding: 14px 16px;
    font-size: 13px;
}
.zendure-flux .zf-header { display: flex; justify-content: flex-end; align-items: center; margin-bottom: 10px; }
.zendure-flux .zf-badges { display: flex; gap: 6px; }
.zendure-flux .zf-badge { font-size: 11px; padding: 3px 10px; border-radius: 20px; background: var(--zf-surface-2); }
.zendure-flux .zf-badge-tarif { color: #854F0B; background: #FAC775; }
.zendure-flux .zf-diagram {
    position: relative; width: 100%; max-width: 640px; height: 320px; margin: 0 auto;
    background: var(--zf-surface-1); border-radius: 14px; border: 0.5px solid var(--zf-border);
    overflow: visible;
}
.zendure-flux .zf-node {
    position: absolute; transform: translate(-50%, -50%); width: 84px; height: 84px;
    border-radius: 50%; background: var(--zf-surface-2); border: 2.5px solid var(--zf-border);
    display: flex; flex-direction: column; align-items: center; justify-content: center;
    text-align: center; line-height: 1.2;
}
.zendure-flux .zf-node-lg { width: 96px; height: 96px; }
.zendure-flux .zf-top { left: 50%; top: 20%; border-color: #EF9F27; }
.zendure-flux .zf-left { left: 15%; top: 58%; border-color: #378ADD; }
.zendure-flux .zf-right { left: 85%; top: 58%; border-color: #D4537E; }
.zendure-flux .zf-bottom { left: 50%; top: 86%; border-color: #ED93B1; }
.zendure-flux .zf-hub {
    position: absolute; left: 50%; top: 58%; transform: translate(-50%, -50%);
    width: 56px; height: 56px; border-radius: 50%; background: var(--zf-surface-2);
    border: 2px solid var(--zf-border); display: flex; align-items: center; justify-content: center;
    color: var(--zf-text-muted); font-size: 20px;
}
.zendure-flux .zf-value { font-size: 14px; font-weight: 600; margin-top: 2px; }
.zendure-flux .zf-value-sm { font-size: 11px; font-weight: 600; }
.zendure-flux .zf-label { font-size: 9.5px; color: var(--zf-text-muted); }
.zendure-flux .zf-row2, .zendure-flux .zf-row3 { display: grid; gap: 10px; margin-top: 12px; }
.zendure-flux .zf-row2 { grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); }
.zendure-flux .zf-row3 { grid-template-columns: repeat(3, minmax(0, 1fr)); }
.zendure-flux .zf-card { background: var(--zf-surface-1); border-radius: 10px; padding: 12px 14px; overflow: hidden; }
.zendure-flux .zf-gauge { display: flex; align-items: center; gap: 8px; }
.zendure-flux .zf-gauge-svg { width: 64px; height: 40px; flex-shrink: 0; }
.zendure-flux .zf-gauge-text { min-width: 0; flex: 1; }
.zendure-flux .zf-gauge-value { font-size: 19px; font-weight: 600; }
.zendure-flux .zf-gauge-value span { font-size: 12px; color: var(--zf-text-muted); }
.zendure-flux .zf-gauge-marge { font-size: 10.5px; color: #3B6D11; margin-top: 2px; }
.zendure-flux .zf-gauge-label { font-size: 10px; color: var(--zf-text-muted); }
.zendure-flux .zf-tempo { display: flex; flex-direction: column; justify-content: center; gap: 8px; }
.zendure-flux .zf-tempo-row { display: flex; justify-content: space-between; align-items: center; font-size: 11px; color: var(--zf-text-muted); }
.zendure-flux .zf-pill { font-size: 11px; font-weight: 600; padding: 2px 10px; border-radius: 20px; }
.zendure-flux .zf-money-label { font-size: 10.5px; color: var(--zf-text-muted); }
.zendure-flux .zf-money-value { font-size: 17px; font-weight: 600; margin-top: 3px; }
.zendure-flux .zf-money-gain { background: rgba(76, 217, 100, 0.12); }
.zendure-flux .zf-money-gain .zf-money-label, .zendure-flux .zf-money-gain .zf-money-value { color: #3B6D11; }
.zendure-flux .zf-pilotage { margin-top: 12px; }
.zendure-flux .zf-pilotage-title { font-size: 11px; color: var(--zf-text-muted); margin-bottom: 8px; }
.zendure-flux .zf-slider-row { display: flex; justify-content: space-between; font-size: 11.5px; margin-bottom: 3px; }
.zendure-flux .zf-slider { width: 100%; }

.zendure-flux.zf-preview-static {
    display: inline-block;
    max-width: 340px;
    font-size: 11px;
    padding: 10px 12px;
}
/* 272 = 340 (max-width de l'aperçu) * 320/400 (ratio réel du diagramme, cf.
   .zf-diagram du vrai widget) : garde les 4 noeuds visuellement équidistants
   du hub même dans cet aperçu statique redimensionné. */
.zendure-flux.zf-preview-static .zf-diagram { height: 272px; }
.zendure-flux.zf-preview-static .zf-node { width: 68px; height: 68px; }
.zendure-flux.zf-preview-static .zf-node-lg { width: 78px; height: 78px; }
.zendure-flux.zf-preview-static .zf-hub { width: 44px; height: 44px; font-size: 16px; }
.zendure-flux.zf-preview-static .zf-gauge-svg { width: 46px; height: 29px; }
.zendure-flux.zf-preview-static .zf-value { font-size: 12px; }
.zendure-flux.zf-preview-static .zf-gauge-svg { width: 70px; height: 44px; }
.zendure-flux.zf-preview-static .zf-slider { width: 100%; }

/* Aperçu conceptuel "Historique" (pas encore implémenté) : mini barres du jour */
.zh-preview { max-width: 260px; margin: 0 auto; }
.zh-preview .zh-bars {
    display: flex;
    align-items: flex-end;
    gap: 4px;
    height: 80px;
    background: var(--bg-widget, #1e1e1e);
    border-radius: 8px;
    padding: 8px;
}
.zh-preview .zh-bar { flex: 1 1 auto; background: #4cd964; border-radius: 2px 2px 0 0; }
.zh-preview .zh-footer { font-size: 11px; margin-top: 6px; opacity: 0.85; }
</style>

<script>
$(function () {
    // Deep-link ?id=X (utilisé par le titre du widget Flux, cf. flux.html) : ouvre
    // directement l'équipement au chargement de la page, comme un clic sur sa carte.
    var deepLinkId = getUrlVars('id');
    if (deepLinkId) {
        $('.eqLogicDisplayCard[data-eqlogic_id="' + deepLinkId + '"]').trigger('click');
    }

    $('#sel_mode_connexion').on('change', function () {
        var mode = $(this).val();
        $('#bloc_cloud').toggle(mode == 'cloud');
        $('#bloc_local').toggle(mode == 'local');
    }).trigger('change');

    $('#sel_type_contrat').on('change', function () {
        var type = $(this).val();
        $('#bloc_tarif_base').toggle(type == 'base');
        $('#bloc_tarif_hphc').toggle(type == 'hphc');
        $('#bloc_tarif_tempo').toggle(type == 'tempo');
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

    // Bouton "Capture télémétrie complète (1h)" : pas de cmd_id connu à l'avance côté
    // PHP (formulaire partagé entre équipements), on le résout via un mini AJAX dédié
    // (core/ajax/zendure.ajax.php) plutôt que jeedom.cmd.execute() qui exige l'id direct.
    $('.eqLogic').off('click', '#bt_debugCapture').on('click', '#bt_debugCapture', function () {
        var eqLogicId = $('.eqLogic').find('[data-l1key="id"]').value();
        if (!eqLogicId) {
            return;
        }
        $.ajax({
            type: 'POST',
            url: 'plugins/zendure/core/ajax/zendure.ajax.php',
            data: { action: 'debugCapture', eqLogic_id: eqLogicId },
            dataType: 'json',
            error: function (request, status, error) {
                handleAjaxError(request, status, error);
            },
            success: function (data) {
                if (data.state != 'ok') {
                    $('#div_alert').showAlert({ message: data.result, level: 'danger' });
                    return;
                }
                $('#div_alert').showAlert({ message: '{{Capture complète activée pour 1h}}', level: 'success' });
            }
        });
    });

    // Bouton "Désactiver le mode intelligent" : même contrainte que bt_debugCapture
    // (formulaire partagé entre équipements, pas de cmd_id connu à l'avance côté PHP).
    $('.eqLogic').off('click', '#bt_disableSmartMode').on('click', '#bt_disableSmartMode', function () {
        var eqLogicId = $('.eqLogic').find('[data-l1key="id"]').value();
        if (!eqLogicId) {
            return;
        }
        $.ajax({
            type: 'POST',
            url: 'plugins/zendure/core/ajax/zendure.ajax.php',
            data: { action: 'disableSmartMode', eqLogic_id: eqLogicId },
            dataType: 'json',
            error: function (request, status, error) {
                handleAjaxError(request, status, error);
            },
            success: function (data) {
                if (data.state != 'ok') {
                    $('#div_alert').showAlert({ message: data.result, level: 'danger' });
                    return;
                }
                $('#div_alert').showAlert({ message: '{{Commande envoyée : mode intelligent désactivé}}', level: 'success' });
            }
        });
    });

});
</script>
<script>
// Onglet "Commandes" : table standard Jeedom (core/js/plugin.template.js),
// même mécanisme que Zigbee/Monitoring/SSH -- remplace l'ancienne table
// "Télémétrie" maison en lecture seule (2026-07-26, demande explicite :
// "il faut partir sur l'option standard Jeedom"). addCmdToTable() est
// appelé par le framework pour chaque commande de l'eqLogic sélectionné
// (cf. jeeFrontEnd.pluginTemplate, plugin.template.js ~L126/710/744) --
// sans cette fonction (même vide), la table #table_cmd ne se peuple jamais.
// Pas de thead fourni dans le HTML : addCmdToTableDefault() construit
// lui-même l'en-tête standard (Id/Nom/Type/Logical ID/Options/Paramètres/
// Etat/Action) au premier appel si absent.
//
// Filtre sur curatedCmdLogicalIds (cf. sendVarToJS() en tête de fichier) :
// ~230 commandes existent par eqLogic (curées + télémétrie brute), la table
// native n'est pas exploitable avec un tel volume (signalé 2026-07-26,
// "ce n'est pas exploitable en l'état"). Seules les commandes réellement
// utilisées par le plugin restent affichées ; le brut reste accessible via
// "Capture télémétrie complète" (onglet Comportement).
function addCmdToTable(_cmd) {
    if (isset(_cmd) && curatedCmdLogicalIds.indexOf(_cmd.logicalId) === -1) {
        return
    }
    jeeFrontEnd.pluginTemplate.addCmdToTableDefault(_cmd)
}
</script>
<?php include_file('core', 'plugin.template', 'js'); ?>
