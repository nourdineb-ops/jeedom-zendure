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
                <a class="btn btn-sm btn-default pluginAction roundedLeft" data-action="openLocation" data-location="<?= $plugin->getDocumentation() ?>"><i class="fas fa-book"></i><span class="hidden-xs"> {{Documentation}}</span>
                </a><a class="btn btn-sm btn-default eqLogicAction" data-action="configure"><i class="fas fa-cogs"></i><span class="hidden-xs"> {{Configuration avancée}}</span>
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

                <fieldset>
                    <legend><i class="fas fa-magic"></i> {{Récupération assistée (sans Home Assistant)}}</legend>
                    <div class="form-group">
                        <label class="col-sm-2 control-label">{{Token ZendureApp}}</label>
                        <div class="col-sm-6">
                            <input type="text" class="form-control" id="input_zendure_token" placeholder="{{Collez ici le token trouvé dans l'appli mobile Zendure}}" />
                            <span class="help-block" style="margin-bottom:0;">{{Appli Zendure → réglages du compte PRINCIPAL (pas un compte invité, sinon aucun appareil ne remonte) → cherchez une option "token"/"développeur"/"intégration". Ce token n'est utilisé qu'une fois ici, pas sauvegardé.}}</span>
                        </div>
                        <div class="col-sm-4">
                            <a id="bt_fetchViaToken" class="btn btn-primary"><i class="fas fa-download"></i> {{Récupérer via Token}}</a>
                        </div>
                    </div>
                    <div class="form-group" id="bloc_choix_appareil" style="display:none;">
                        <label class="col-sm-2 control-label">{{Appareil détecté}}</label>
                        <div class="col-sm-6">
                            <select class="form-control" id="sel_zendure_device"></select>
                        </div>
                        <div class="col-sm-4">
                            <a id="bt_applyZendureDevice" class="btn btn-success"><i class="fas fa-check"></i> {{Appliquer}}</a>
                        </div>
                    </div>
                </fieldset>

                <div class="row">
                    <div class="col-md-6">
                        <legend><i class="fas fa-plug"></i> {{Connexion}}</legend>
                        <fieldset>
                            <div class="form-group">
                                <label class="col-sm-4 control-label">{{Modèle d'appareil}}</label>
                                <div class="col-sm-8">
                                    <select class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="device_model">
                                        <option value="hyper2000">{{Hyper 2000}}</option>
                                        <option value="hub1200">{{Hub 1200 (non testé)}}</option>
                                        <option value="hub2000">{{Hub 2000 (non testé)}}</option>
                                        <option value="aio2400">{{AIO 2400 (non testé)}}</option>
                                        <option value="superbasev4600">{{SuperBase V4600 (non testé)}}</option>
                                        <option value="superbasev6400">{{SuperBase V6400 (non testé)}}</option>
                                    </select>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="col-sm-4 control-label">{{Mode de connexion}}</label>
                                <div class="col-sm-8">
                                    <select id="sel_mode_connexion" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="mode_connexion">
                                        <option value="cloud">{{Cloud}}</option>
                                        <option value="local">{{Local (expérimental)}}</option>
                                        <option value="simulation">{{Simulation (aucun appareil requis)}}</option>
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
                            <legend><i class="fas fa-cloud"></i> {{Identifiants Cloud}}</legend>
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
                            <legend><i class="fas fa-network-wired"></i> {{Identifiants Local}}</legend>
                            <fieldset>
                                <div class="form-group">
                                    <label class="col-sm-4 control-label">{{Broker local}}</label>
                                    <div class="col-sm-8">
                                        <input class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="local_host" placeholder="192.168.1.12" />
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label class="col-sm-4 control-label">{{Port}}</label>
                                    <div class="col-sm-8">
                                        <input type="number" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="local_port" placeholder="1883" />
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label class="col-sm-4 control-label">{{TLS}}</label>
                                    <div class="col-sm-2">
                                        <input type="checkbox" id="chk_local_tls" class="eqLogicAttr" data-l1key="configuration" data-l2key="local_tls" />
                                    </div>
                                    <label class="col-sm-3 control-label">{{Ignorer le certificat}}</label>
                                    <div class="col-sm-2">
                                        <input type="checkbox" class="eqLogicAttr" data-l1key="configuration" data-l2key="local_tls_insecure" />
                                    </div>
                                    <div class="col-sm-12">
                                        <span class="help-block" style="margin-top:4px;">{{"Ignorer le certificat" : pour un broker local avec certificat auto-signé (ex. Chemin B) -- jamais à cocher pour une connexion cloud.}}</span>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label class="col-sm-4 control-label">{{Nom d'utilisateur MQTT}}</label>
                                    <div class="col-sm-8">
                                        <input class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="local_username" placeholder="{{vide = accès anonyme}}" />
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label class="col-sm-4 control-label">{{Mot de passe}}</label>
                                    <div class="col-sm-8">
                                        <input type="password" class="eqLogicAttr inputPassword form-control" data-l1key="configuration" data-l2key="local_password" />
                                    </div>
                                </div>
                            </fieldset>
                        </div>
                    </div>
                </div>
            </div>

            <div role="tabpanel" class="tab-pane" id="tab_sources">
                <fieldset>
                    <legend><i class="fas fa-satellite-dish"></i> {{Sources de lecture}}</legend>
                    <?php
                    // Closure plutôt qu'une function nommée : ce template peut en théorie
                    // être inclus plus d'une fois dans le même process PHP (double
                    // "Cannot redeclare" sinon).
                    $renderSourceRow = function ($key, $label, $note = '') {
                        echo '<div class="form-group">';
                        echo '<label class="col-sm-3 control-label">' . $label;
                        if ($note != '') {
                            echo '<br><small class="text-muted" style="font-weight:normal;">' . $note . '</small>';
                        }
                        echo '</label>';
                        echo '<div class="col-sm-6">';
                        echo '<div class="input-group">';
                        echo '<input type="text" class="eqLogicAttr form-control roundedLeft" data-l1key="configuration" data-l2key="' . $key . '" placeholder="{{Aucune commande sélectionnée}}" />';
                        echo '<span class="input-group-btn">';
                        echo '<a class="btn btn-default listCmdInfo roundedRight" title="{{Choisir une commande}}"><i class="fas fa-list-alt"></i></a>';
                        echo '</span>';
                        echo '</div>';
                        echo '</div>';
                        echo '</div>';
                    };
                    ?>

                    <legend style="font-size:14px;"><i class="fas fa-bolt"></i> {{Pilotage}}</legend>
                    <?php
                    $renderSourceRow('src_grid_papp', '{{Puissance prélevée sur le réseau (W)}}', '{{Pince/compteur dédié recommandé — plus fiable qu\'un Téléinfo pour l\'injection}}');
                    ?>
                    <p class="text-muted" style="margin:0 0 4px 15px;font-size:11px;">{{Avancé — laisser vide sauf besoin spécifique, le Zendure connaît déjà sa propre injection/production}}</p>
                    <?php
                    $renderSourceRow('src_injection', '{{Injection maison (Zendure)}}', '{{Vide = repli sur la télémétrie Zendure}}');
                    $renderSourceRow('src_solaire', '{{Production solaire (dashboard)}}', '{{Vide = repli sur la télémétrie Zendure}}');
                    ?>

                    <legend style="font-size:14px;"><i class="fas fa-cloud-sun"></i> {{Prévision solaire}}</legend>
                    <?php
                    $renderSourceRow('src_prevision_solaire', '{{Prévision solaire J+0 (Wh)}}');
                    $renderSourceRow('src_prevision_solaire_j1', '{{Prévision solaire J+1 (Wh)}}');
                    ?>

                    <legend style="font-size:14px;"><i class="fas fa-thermometer-half"></i> {{Météo}}</legend>
                    <?php
                    $renderSourceRow('src_temperature_exterieure', '{{Température extérieure (°C)}}', '{{Optionnel -- active le seuil "priorité charge" par temps froid, onglet Comportement}}');
                    ?>

                    <legend style="font-size:14px;"><i class="fas fa-tachometer-alt"></i> {{Compteur}}</legend>
                    <p class="text-muted" style="margin:0 0 4px 15px;font-size:11px;">{{Alimentent l'affichage jauge (intensité) du dashboard}}</p>
                    <?php
                    $renderSourceRow('src_intensite', '{{Intensité instantanée}}');
                    $renderSourceRow('src_imax_abonnement', '{{Imax abonnement}}');
                    ?>

                    <legend style="font-size:14px;"><i class="fas fa-euro-sign"></i> {{Option tarifaire}}</legend>
                    <div class="form-group">
                        <label class="col-sm-3 control-label">{{Type de contrat}}</label>
                        <div class="col-sm-3">
                            <select id="sel_type_contrat" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="type_contrat">
                                <option value="base">{{Base}}</option>
                                <option value="hphc">{{Heures Pleines / Heures Creuses}}</option>
                                <option value="tempo">{{Tempo}}</option>
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
                        <?php $renderSourceRow('src_periode_tarif', '{{Période tarifaire (PTEC / HP-HC)}}'); ?>
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
                        <?php
                        $renderSourceRow('src_tempo_now', '{{Tempo — période courante (HP/HC + couleur, ex. HCJB)}}');
                        $renderSourceRow('src_tempo_j', '{{Tempo — couleur du jour}}');
                        $renderSourceRow('src_tempo_j1', '{{Tempo — couleur de demain (J+1)}}');
                        ?>
                    </div>

                    <legend style="font-size:14px;"><i class="fas fa-coins"></i> {{Coût}}</legend>
                    <?php
                    $renderSourceRow('src_depense_jour', '{{Dépense jour (€)}}');
                    $renderSourceRow('src_depense_veille', '{{Dépense veille (€)}}');
                    ?>
                </fieldset>
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
                        <label class="col-sm-3 control-label">{{Seuil SOC priorité charge (%)}}</label>
                        <div class="col-sm-2">
                            <input type="number" min="0" max="100" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="seuil_soc_priorite_charge" placeholder="20" />
                        </div>
                        <div class="col-sm-7">
                            <span class="help-block" style="margin-top:8px;">{{Sous ce SOC, le réveil HP ne force plus la décharge (le plafond reste relevé à 100%, la bascule de mode est juste retardée) -- évite de forcer une décharge sur une batterie quasi vide alors qu'un surplus solaire pourrait la recharger.}}</span>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="col-sm-3 control-label">{{Seuil priorité charge par temps froid (%)}}</label>
                        <div class="col-sm-2">
                            <input type="number" min="0" max="100" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="seuil_soc_priorite_charge_froid" placeholder="{{vide = désactivé}}" />
                        </div>
                        <label class="col-sm-3 control-label">{{Sous cette température (°C)}}</label>
                        <div class="col-sm-2">
                            <input type="number" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="temperature_seuil_froid" placeholder="5" />
                        </div>
                        <div class="col-sm-12">
                            <span class="help-block" style="margin-top:4px;">{{Optionnel -- nécessite une source "Température extérieure" (onglet Sources). Si renseigné, remplace le seuil ci-dessus par celui-ci quand il fait plus froid que la température indiquée (ex. protéger davantage la batterie l'hiver, comme documenté par certains projets communautaires Zendure).}}</span>
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
                            <input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="ble_address" placeholder="AA:BB:CC:DD:EE:FF" />
                        </div>
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
                    <div class="form-group">
                        <label class="col-sm-3 control-label">{{Heure de montée du solaire (modèle kWh)}}</label>
                        <div class="col-sm-2">
                            <input type="number" min="0" max="23" step="1" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="heure_fin_montee_solaire" placeholder="9" />
                        </div>
                        <div class="col-sm-7">
                            <span class="help-block" style="margin-top:8px;">{{La prévision solaire est un total journalier -- ne garantit rien avant cette heure. La cible de charge est relevée si besoin pour couvrir seule la conso habituelle entre le réveil HP et cette heure, indépendamment de la prévision du jour.}}</span>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="col-sm-3 control-label">{{Équipement Solcast (optionnel)}}</label>
                        <div class="col-sm-4">
                            <select class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="solcast_eqlogic_id">
                                <option value="0">{{Aucun -- suppose 0W avant l'heure ci-dessus}}</option>
                                <?php
                                foreach (eqLogic::byType('solcast', true) as $solcastEq) {
                                    echo '<option value="' . $solcastEq->getId() . '">' . $solcastEq->getName() . '</option>';
                                }
                                ?>
                            </select>
                        </div>
                        <div class="col-sm-5">
                            <span class="help-block" style="margin-top:8px;">{{Si renseigné, la réserve avant montée solaire ci-dessus utilise la vraie prévision horaire Solcast (d0h...) au lieu de supposer 0W -- plus précis, sensible à la météo réelle du jour.}}</span>
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
                                <option value="condense">{{Condensé}}</option>
                                <option value="flux">{{Flux}}</option>
                                <option value="historique">{{Résumé}}</option>
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
                <fieldset>
                    <legend style="font-size:14px;"><i class="fas fa-tachometer-alt"></i> {{Jauge d'intensité}}</legend>
                    <div class="form-group">
                        <label class="col-sm-3 control-label">{{Imax (A)}}<br><small class="text-muted" style="font-weight:normal;">{{Facultatif — utilisé seulement si la source "Imax abonnement" (onglet Sources) n'est pas renseignée}}</small></label>
                        <div class="col-sm-2">
                            <input type="number" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="imax_ampere" />
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
                        <p><strong>{{Résumé}}</strong> <span class="label label-success">{{implémenté}}</span></p>
                        <div class="zd-preview zendure-digest">
                            <div class="zd-header">
                                <span class="zd-name">Panneaux solaires</span>
                            </div>
                            <div class="zd-gains">
                                <div class="zd-gain-card">
                                    <div class="zd-gain-value zd-positive">1.24 €</div>
                                    <div class="zd-gain-label">Gain aujourd'hui</div>
                                </div>
                                <div class="zd-gain-card">
                                    <div class="zd-gain-value zd-positive">0.85 €</div>
                                    <div class="zd-gain-label">Gain veille (J-1)</div>
                                </div>
                            </div>
                            <div class="zd-breakdown">
                                <span>☀ Solaire : 0.90 € (2.10 kWh)</span>
                                <span>🔋 Batterie : 0.34 € (0.85 kWh)</span>
                            </div>
                            <div class="zd-breakdown">
                                <span>Dépense jour : 0.31 € (1.40 kWh)</span>
                                <span>Dépense veille : 0.28 €</span>
                            </div>
                            <div class="zd-footer">
                                <span>SOC</span>
                                <span class="zd-soc-value">74%</span>
                            </div>
                        </div>
                        <p class="text-muted" style="font-size:11px;">{{Digest du jour (gain/dépense, détail brut/coût de charge, SOC) -- pour les courbes, la page Analyse native de Jeedom fait déjà ça très bien, cf. documentation.}}</p>
                    </div>
                </div>
            </div>

            <div role="tabpanel" class="tab-pane" id="commandtab">
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

/* Aperçu "Résumé" (onglet IHM) : copie de core/template/dashboard/digest/digest.html,
   mêmes classes .zendure-digest/.zd-* que le vrai template (cf. convention .zendure-condense
   ci-dessus), à retenir en sync à chaque évolution du vrai template. */
.zd-preview.zendure-digest {
    display: inline-block;
    text-align: left;
    width: 100%;
    max-width: 340px;
    border-radius: 10px;
    padding: 10px 14px;
    background: #fff;
    color: var(--txt-color, #333);
    font-size: 13px;
}
@media (prefers-color-scheme: dark) {
    .zd-preview.zendure-digest { background: #1e1e1e; }
}
.zd-preview .zd-header { display: flex; justify-content: space-between; font-weight: 600; margin-bottom: 8px; }
.zd-preview .zd-gains { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 10px; }
.zd-preview .zd-gain-card { background: rgba(127, 127, 127, 0.08); border-radius: 10px; padding: 8px 10px; text-align: center; }
.zd-preview .zd-gain-value { font-size: 22px; font-weight: 700; }
.zd-preview .zd-gain-value.zd-positive { color: #3B6D11; }
.zd-preview .zd-gain-value.zd-negative { color: #B91C1C; }
.zd-preview .zd-gain-label { font-size: 10.5px; opacity: 0.7; margin-top: 2px; }
.zd-preview .zd-breakdown { display: flex; justify-content: space-between; gap: 10px; margin-bottom: 8px; font-size: 11.5px; }
.zd-preview .zd-breakdown span:first-child { opacity: 0.75; }
.zd-preview .zd-footer { display: flex; justify-content: space-between; align-items: center; margin-top: 6px; padding-top: 6px; border-top: 1px solid rgba(127, 127, 127, 0.2); font-size: 11.5px; }
.zd-preview .zd-soc-value { font-weight: 700; }
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

    // Bouton "Récupérer via Token" : onboarding sans Home Assistant, cf.
    // zendure::fetchCloudCredentialsFromToken(). Pas de eqLogic_id requis --
    // ne touche que les champs du formulaire, encore rien de sauvegardé tant
    // que l'utilisateur ne clique pas sur "Sauvegarder" comme d'habitude.
    var zendureTokenDevices = [];
    $('.eqLogic').off('click', '#bt_fetchViaToken').on('click', '#bt_fetchViaToken', function () {
        var token = $('#input_zendure_token').val();
        if (!token) {
            $('#div_alert').showAlert({ message: '{{Collez d\'abord un token}}', level: 'warning' });
            return;
        }
        var $bt = $(this);
        $bt.prop('disabled', true);
        $.ajax({
            type: 'POST',
            url: 'plugins/zendure/core/ajax/zendure.ajax.php',
            data: { action: 'fetchViaToken', token: token },
            dataType: 'json',
            error: function (request, status, error) {
                $bt.prop('disabled', false);
                handleAjaxError(request, status, error);
            },
            success: function (data) {
                $bt.prop('disabled', false);
                if (data.state != 'ok') {
                    $('#div_alert').showAlert({ message: data.result, level: 'danger' });
                    return;
                }
                zendureTokenDevices = data.result.devices;
                var applyCloudFields = function (device) {
                    $('.eqLogicAttr[data-l2key="device_id"]').value(device.device_id);
                    $('.eqLogicAttr[data-l2key="product_key"]').value(device.product_key);
                    if (device.model_key) {
                        $('.eqLogicAttr[data-l2key="device_model"]').value(device.model_key);
                    }
                    $('.eqLogicAttr[data-l2key="mode_connexion"]').value('cloud').trigger('change');
                    $('.eqLogicAttr[data-l2key="cloud_host"]').value(data.result.cloud_host);
                    if (data.result.cloud_port) {
                        $('.eqLogicAttr[data-l2key="cloud_port"]').value(data.result.cloud_port);
                    }
                    $('.eqLogicAttr[data-l2key="cloud_username"]').value(data.result.cloud_username);
                    $('.eqLogicAttr[data-l2key="cloud_auth_key"]').value(data.result.cloud_auth_key);
                    $('.eqLogicAttr[data-l2key="cloud_client_id"]').value(data.result.cloud_client_id);
                };

                if (zendureTokenDevices.length == 1) {
                    applyCloudFields(zendureTokenDevices[0]);
                    $('#bloc_choix_appareil').hide();
                    $('#div_alert').showAlert({ message: '{{Champs remplis depuis le token. Vérifiez puis sauvegardez.}}', level: 'success' });
                } else {
                    var $sel = $('#sel_zendure_device').empty();
                    zendureTokenDevices.forEach(function (device, idx) {
                        $sel.append($('<option>').val(idx).text(device.name + ' (' + device.model + ')'));
                    });
                    $('#bloc_choix_appareil').show();
                    $('#div_alert').showAlert({ message: '{{Plusieurs appareils trouvés sur ce compte -- choisissez lequel appliquer à cet équipement.}}', level: 'info' });
                }

                $('.eqLogic').off('click', '#bt_applyZendureDevice').on('click', '#bt_applyZendureDevice', function () {
                    var idx = parseInt($('#sel_zendure_device').val(), 10);
                    if (isNaN(idx) || !zendureTokenDevices[idx]) {
                        return;
                    }
                    applyCloudFields(zendureTokenDevices[idx]);
                    $('#div_alert').showAlert({ message: '{{Champs remplis depuis le token. Vérifiez puis sauvegardez.}}', level: 'success' });
                });
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
