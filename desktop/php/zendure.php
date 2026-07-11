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
                <div class="alert alert-info" style="margin-top:20px;">
                    <strong>{{À quoi sert cet onglet}}</strong>
                    <ul style="margin-bottom:0;">
                        <li>{{Nom : libre, sert juste à identifier l'équipement dans Jeedom.}}</li>
                        <li>{{Objet parent : la pièce où se trouve physiquement la batterie (ex. Extérieur, Garage). Purement organisationnel.}}</li>
                        <li>{{Activé : démarre réellement le pilotage (connexion MQTT + boucle anti-injection). Désactivé = l'équipement existe mais ne fait rien.}}</li>
                        <li>{{Visible : affiche le dashboard "Condensé" sur la page d'accueil / la vue de l'objet.}}</li>
                        <li>{{Catégorie : cosmétique (icône/filtre Jeedom), Énergie coché par défaut.}}</li>
                    </ul>
                </div>
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
                <div class="alert alert-info">
                    <strong>{{À quoi sert cet onglet}}</strong>
                    <ul style="margin-bottom:0;">
                        <li>{{Identifiant appareil (device_id) et Product key : identifiants internes Zendure de VOTRE Hyper 2000 (pas le numéro de série visible sur l'étiquette). Ils ne sont pas exposés par l'app officielle — la façon la plus fiable de les récupérer est de sniffer les infos d'une intégration existante qui pilote déjà l'appareil (ex. Home Assistant : fichier .storage/zendure_ha.storage, champs productKey/deviceKey).}}</li>
                        <li>{{Chemin A (Cloud) : le démon se connecte au broker MQTT cloud de Zendure — simple, mais latence ~90s côté cloud communautaire. C'est le seul chemin validé en conditions réelles à ce jour sur ce projet.}}</li>
                        <li>{{Chemin B (Local) : cible v1 retenue pour le zéro-injection strict (latence minimale), mais nécessite un relais DNS + une reconfiguration Bluetooth de l'appareil vers un broker Mosquitto local (voir l'avertissement ci-dessus). Pas encore mis en place.}}</li>
                        <li>{{En Cloud : le nom d'utilisateur/mot de passe MQTT ne sont PAS le compte de l'app Zendure — ce sont des identifiants de session MQTT (obtenus par ex. en récupérant ceux utilisés par une intégration Home Assistant existante).}}</li>
                    </ul>
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
                <div class="alert alert-info">
                    <strong>{{Logique de la boucle anti-injection}}</strong>
                    <p>{{À chaque mesure de la pince/PAPP (grid_power), le démon calcule l'écart entre la puissance réseau mesurée et la marge cible, et reporte cet écart ~1:1 sur la nouvelle limite de sortie de la batterie (régulateur proportionnel, pas d'intégrateur). Convention : grid_power > 0 = import réseau (normal), < 0 = injection (à éviter).}}</p>
                    <ul style="margin-bottom:0;">
                        <li>{{Marge anti-injection (W) : objectif de puissance importée du réseau à maintenir (jamais tout à 0, pour absorber les variations entre deux mesures de la pince). Ex. 30W.}}</li>
                        <li>{{Cooldown (s) : délai minimum entre deux commandes envoyées à la batterie, pour ne pas la solliciter en continu. Ignoré en cas d'injection avérée (voir seuil urgent, non exposé dans cet onglet — cf. urgent_injection_w).}}</li>
                        <li>{{Hystérésis (W) : en dessous de ce changement de puissance, on ne renvoie pas de commande (évite de spammer la batterie pour des micro-ajustements).}}</li>
                        <li>{{Limites sortie min/max (W) : bornes physiques/souhaitées de la limite de sortie envoyée à la batterie (ex. 0 à 1200W pour un Hyper 2000).}}</li>
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
                </fieldset>
                <div class="alert alert-info">
                    <strong>{{À quoi sert cet onglet}}</strong>
                    <ul style="margin-bottom:0;">
                        <li>{{Condensé : tuile compacte (anneau SOC, 4 flux, jauge intensité, gain €).}}</li>
                        <li>{{Flux : losange animé Solaire/Réseau/Maison/Batterie, jauge d'intensité, Tempo J/J+1, indicateurs financiers et curseurs de pilotage (limite de sortie AC, SOC minimum).}}</li>
                        <li>{{Historique : pas encore implémenté — en le sélectionnant, l'équipement retombe sur l'affichage générique Jeedom (liste de commandes), sans plantage mais sans le rendu visuel prévu à terme.}}</li>
                        <li>{{Animations : désactivez si vous préférez un rendu statique (utile sur mobile/tablette).}}</li>
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
                                <div class="zf-title"><i class="fas fa-bolt"></i><span>Panneaux solaires</span></div>
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
                                    <div><div class="zf-gauge-value">6.2 <span>A</span></div><div class="zf-gauge-marge"><i class="fas fa-shield-alt"></i> {{marge}} 23.8 A</div></div>
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

/* Aperçu "Flux" (onglet IHM) : copie du <style> inline de core/template/dashboard/flux/flux.html,
   les classes .zendure-flux/.zf-* sont volontairement identiques au vrai template
   (cf. .zendure-condense ci-dessus pour la même convention). Pas d'animation ni de
   JS de curseur ici : c'est un aperçu statique avec des valeurs d'exemple. */
.zendure-flux {
    --zf-surface-0: var(--bg-widget, #1e1e1e);
    --zf-surface-1: rgba(127, 127, 127, 0.08);
    --zf-surface-2: rgba(127, 127, 127, 0.16);
    --zf-text: var(--text-widget, #eee);
    --zf-text-muted: rgba(170, 170, 170, 0.8);
    --zf-border: rgba(127, 127, 127, 0.25);
    width: 100%;
    max-width: 660px;
    background: var(--zf-surface-0);
    color: var(--zf-text);
    border-radius: 12px;
    padding: 14px 16px;
    font-size: 13px;
}
.zendure-flux .zf-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; }
.zendure-flux .zf-title { display: flex; align-items: center; gap: 8px; font-weight: 600; }
.zendure-flux .zf-title i { color: #EF9F27; }
.zendure-flux .zf-badges { display: flex; gap: 6px; }
.zendure-flux .zf-badge { font-size: 11px; padding: 3px 10px; border-radius: 20px; background: var(--zf-surface-2); }
.zendure-flux .zf-badge-tarif { color: #854F0B; background: #FAC775; }
.zendure-flux .zf-diagram {
    position: relative; width: 100%; max-width: 640px; height: 300px; margin: 0 auto;
    background: var(--zf-surface-1); border-radius: 14px; border: 0.5px solid var(--zf-border);
}
.zendure-flux .zf-node {
    position: absolute; transform: translate(-50%, -50%); width: 84px; height: 84px;
    border-radius: 50%; background: var(--zf-surface-2); border: 2.5px solid var(--zf-border);
    display: flex; flex-direction: column; align-items: center; justify-content: center;
    text-align: center; line-height: 1.2;
}
.zendure-flux .zf-node-lg { width: 96px; height: 96px; }
.zendure-flux .zf-top { left: 50%; top: 22%; border-color: #EF9F27; }
.zendure-flux .zf-left { left: 16%; top: 58%; border-color: #378ADD; }
.zendure-flux .zf-right { left: 84%; top: 58%; border-color: #D4537E; }
.zendure-flux .zf-bottom { left: 50%; top: 92%; border-color: #ED93B1; }
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
.zendure-flux .zf-row2 { grid-template-columns: 1fr 1fr; }
.zendure-flux .zf-row3 { grid-template-columns: repeat(3, 1fr); }
.zendure-flux .zf-card { background: var(--zf-surface-1); border-radius: 10px; padding: 12px 14px; }
.zendure-flux .zf-gauge { display: flex; align-items: center; gap: 12px; }
.zendure-flux .zf-gauge-svg { width: 90px; height: 56px; flex-shrink: 0; }
.zendure-flux .zf-gauge-value { font-size: 19px; font-weight: 600; }
.zendure-flux .zf-gauge-value span { font-size: 12px; color: var(--zf-text-muted); }
.zendure-flux .zf-gauge-marge { font-size: 10.5px; color: #3B6D11; margin-top: 2px; }
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
.zendure-flux.zf-preview-static .zf-diagram { height: 220px; }
.zendure-flux.zf-preview-static .zf-node { width: 68px; height: 68px; }
.zendure-flux.zf-preview-static .zf-node-lg { width: 78px; height: 78px; }
.zendure-flux.zf-preview-static .zf-hub { width: 44px; height: 44px; font-size: 16px; }
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
            url: 'core/ajax/zendure.ajax.php',
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
});
</script>
<?php include_file('core', 'plugin.template', 'js'); ?>
