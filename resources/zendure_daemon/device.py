"""Un Device = un eqLogic Zendure : son transport (A ou B) + sa boucle rapide anti-injection.

La boucle lente (stratégie éco, SOC nocturne/Tempo, cf. brief §9bis) reste côté
scénario Jeedom ou config quotidienne ; elle n'a pas besoin d'être temps réel et
n'est donc pas modélisée ici.
"""

import asyncio
import logging
import time
from typing import Optional

from regulation.anti_injection import AntiInjectionConfig, AntiInjectionRegulator
from telemetry_map import translate_properties
from telemetry_throttle import TelemetryThrottle
from lan_ping import ping_once, kill_zombie_connection
from transport.ble_fallback import fetch_ble_snapshot
from transport.base import TelemetryFrame, Transport
from transport.factory import build_transport

DEBUG_CAPTURE_DURATION_S = 3600.0

# Silence total de télémétrie (aucune trame reçue, throttle ou pas -- cf.
# _on_telemetry) au-delà de ce délai malgré une connexion MQTT propre côté démon
# (transport_connected=True, pas de flapping) : signale que l'APPAREIL lui-même ne
# répond plus (WiFi du boîtier instable, constaté en réel le 2026-07-22 -- 23+ min
# de silence pendant qu'un redémarrage du démon + un getAll explicite n'ont rien
# rapporté), pas un problème du démon/transport. Alerte distincte du flapping MQTT
# (cf. mqtt_transport.py) : la connexion peut très bien être stable pendant que
# l'appareil, lui, est injoignable.
#
# Valeur par défaut seulement : l'utilisateur trouvait ça encore trop bavard en
# mail malgré ce délai (échange du 2026-09-03) -- ce seuil est donc désormais
# piloté par la config (alert_delay_s, onglet Comportement), self._alert_delay_s
# ci-dessous. Sert aussi de délai pour l'alerte ping (cf. check_lan_reachability),
# un seul réglage utilisateur pour les deux plutôt que deux seuils à régler
# séparément pour un même besoin ("moins de mails").
DEFAULT_ALERT_DELAY_S = 300.0

# Seuil distinct, plus court, pour la tentative d'auto-heal (ss -K sur une
# connexion zombie, cf. lan_ping.py::kill_zombie_connection, incident
# 2026-08-24) -- volontairement PAS le même seuil que l'alerte humaine
# (self._alert_delay_s) : cette dernière doit rester prudente pour ne pas
# spammer sur un simple accroc, alors qu'un ss -K sur une connexion qui n'était
# pas réellement zombie ne coûte presque rien (une reconnexion propre de plus,
# ~1-2s, testé en réel). Pas rendu configurable comme alert_delay_s : ce n'est
# pas un canal de notification (rien n'est envoyé à l'utilisateur), juste un
# détail de plomberie interne. Le plancher à respecter est plutôt le cycle de
# sollicitation actif du démon (TELEMETRY_POLL_S = 60s côté zendure_daemon.py,
# le `getAll` périodique) : en dessous d'environ 1.5-2x ce cycle, un silence
# peut juste être une réponse un peu lente au poll normal, pas une vraie panne.
AUTO_HEAL_STALE_S = 120.0

# Durée max d'une session de secours BLE (connexion + handshake + lecture),
# cf. transport/ble_fallback.py -- bornée volontairement courte pour limiter la
# contention avec le scan passif d'un autre consommateur du même adaptateur
# Bluetooth (TheengsGateway, relevés de température, cf. échange utilisateur
# 2026-07-22).
BLE_FAILOVER_TIMEOUT_S = 20.0

# Dernier recours : redémarrage forcé de l'appareil (coupure/relance secteur via une
# prise pilotée, ex. Zigbee/Z2M -- cf. onglet Config avancée "Redémarrage forcé",
# zendure.class.php::executePowerCycle()) quand ni l'auto-heal (ss -K) ni le temps
# laissé à l'alerte humaine n'ont suffi à faire revenir la télémétrie -- signature
# d'un vrai blocage (freeze) de l'appareil, pas d'un simple accroc réseau.
#
# Valeur par défaut nettement au-delà de AUTO_HEAL_STALE_S (120s) ET du délai
# d'alerte par défaut (300s) : le pire épisode de WiFi instable confirmé à ce jour a
# duré 23+ minutes sans être un vrai freeze (cf. project_device_wifi_instability) --
# rester largement au-dessus évite de couper l'alimentation de la batterie pour une
# coupure WiFi qui se serait résolue seule. Reste configurable (power_cycle_stale_s),
# mais toujours CLAMPÉ au-dessus de l'auto-heal et de l'alerte dans reload_config()/
# __init__ ci-dessous, quelle que soit la valeur saisie par l'utilisateur : le principe
# "dernier recours, après échec des autres stratégies" ne doit jamais dépendre d'une
# config cohérente pour rester vrai.
DEFAULT_POWER_CYCLE_STALE_S = 1800.0

# Durée de coupure secteur avant relance : généreuse, pour laisser le temps à
# l'alimentation/aux condensateurs internes de se décharger complètement -- un cycle
# WiFi seul (déjà tenté ailleurs, cf. auto-heal) ne suffit pas forcément à sortir
# d'un vrai blocage logiciel, l'objectif ici est une remise à zéro plus dure.
DEFAULT_POWER_CYCLE_OFF_DURATION_S = 20.0

# Anti-répétition entre deux tentatives : si la télémétrie ne revient toujours pas
# après une première coupure/relance (panne matérielle réelle, pas juste un freeze
# logiciel), inutile de s'acharner à recouper/rallumer la prise en boucle à chaque
# nouveau franchissement du seuil -- ça n'aiderait pas et userait la prise/l'appareil
# pour rien. Compteur en mémoire (self._power_cycle_last_attempt_at, monotonic) :
# remis à zéro à un redémarrage du démon, comme le reste de l'état d'escalade
# (_auto_heal_attempted, _telemetry_stale) -- acceptable, un redémarrage du démon
# est lui-même un signal que quelqu'un est déjà en train d'intervenir.
DEFAULT_POWER_CYCLE_COOLDOWN_S = 3600.0

log = logging.getLogger("zendure.device")


class Device:
    def __init__(self, eq_config: dict, callback_client):
        self.eq_id: int = eq_config["eq_id"]
        self._eq_config = eq_config
        self._callback = callback_client
        self._transport: Transport = build_transport(eq_config)
        self._regulator = AntiInjectionRegulator(
            AntiInjectionConfig.from_dict(eq_config.get("anti_injection", {}))
        )
        self._throttle = TelemetryThrottle(
            float(eq_config.get("telemetry_min_interval_s", 300)),
            float(eq_config.get("telemetry_noise_threshold", 3)),
        )
        # Dernière puissance RÉELLEMENT délivrée à la maison (télémétrie Zendure,
        # pas une valeur qu'on a nous-même commandée) — cf. anti_injection.py,
        # le régulateur recalcule sa cible à partir de cette mesure à chaque fois.
        self._last_injected_w: float = 0.0
        # Horodatage de cette dernière valeur -- mis à jour par _push_values(),
        # donc par la télémétrie MQTT normale ET le secours BLE (contrairement à
        # self._last_telemetry_at, spécifique à la fraîcheur MQTT, cf.
        # check_telemetry_staleness()). Sert à ne jamais faire tourner
        # l'anti-injection sur une valeur périmée -- incident réel du 2026-07-28 :
        # pendant une coupure WiFi de l'appareil, `grid` (PAPP externe, jamais
        # affecté par le WiFi Zendure) restait frais pendant qu'`injected_power`
        # restait figé, faussant la cible calculée par la formule sans que rien
        # ne le signale.
        self._last_injected_at: float = 0.0
        # Au-delà de ce délai sans nouvelle valeur d'injected_power, on ne lui
        # fait plus confiance et on bascule sur 0 (on_grid_power) -- même
        # convention que le seuil hors-ligne côté PHP (accumulateEuro(), x2 le
        # cycle de reporting attendu).
        self._injected_stale_after_s: float = 2 * float(eq_config.get("telemetry_min_interval_s", 300))
        self._last_telemetry_at: float = time.monotonic()
        self._telemetry_stale: bool = False
        # Distinct de _telemetry_stale : s'arme à AUTO_HEAL_STALE_S (plus court),
        # une seule tentative de ss -K par épisode de silence -- cf. son usage
        # dans check_telemetry_staleness().
        self._auto_heal_attempted: bool = False
        self._ble_failover_active: bool = bool(eq_config.get("ble_failover_active", False))
        self._ble_address: str = str(eq_config.get("ble_address") or "")
        self._lan_ping_active: bool = bool(eq_config.get("lan_ping_active", False))
        self._device_ip: str = str(eq_config.get("device_ip") or "")
        self._auto_heal_active: bool = bool(eq_config.get("auto_heal_active", False))
        # Interrupteur + délai des alertes humaines (mail/push), demande utilisateur
        # du 2026-09-03 ("trop d'alerte mail") -- cf. _maybe_send_alert(). Coupe
        # tout (y compris les *_resolu) quand désactivé ; les événements restent
        # visibles dans les logs Jeedom (log::add(), cf. callback.php) quoi qu'il
        # arrive, seule la notification humaine est concernée.
        self._alert_mail_active: bool = bool(eq_config.get("alert_mail_active", True))
        self._alert_delay_s: float = float(eq_config.get("alert_delay_s", DEFAULT_ALERT_DELAY_S))
        # Dernier recours (redémarrage forcé, cf. AUTO_HEAL_STALE_S/DEFAULT_POWER_CYCLE_STALE_S
        # ci-dessus) : self._power_cycle_stale_s est calculé (pas juste lu) via
        # _clamp_power_cycle_stale_s() -- garantit qu'il reste toujours au-dessus de
        # l'auto-heal ET de l'alerte, même si l'utilisateur configure une valeur plus
        # courte par erreur (cf. commentaire de DEFAULT_POWER_CYCLE_STALE_S).
        self._power_cycle_active: bool = bool(eq_config.get("power_cycle_active", False))
        self._power_cycle_stale_s: float = self._clamp_power_cycle_stale_s(eq_config)
        self._power_cycle_off_duration_s: float = float(
            eq_config.get("power_cycle_off_duration_s", DEFAULT_POWER_CYCLE_OFF_DURATION_S)
        )
        self._power_cycle_cooldown_s: float = float(
            eq_config.get("power_cycle_cooldown_s", DEFAULT_POWER_CYCLE_COOLDOWN_S)
        )
        # Distinct de _telemetry_stale/_auto_heal_attempted : s'arme au seuil le plus
        # long, une seule tentative par épisode de silence (edge-triggered, même
        # convention que les deux autres tiers de check_telemetry_staleness()).
        self._power_cycle_attempted: bool = False
        # Monotonic, None tant qu'aucune tentative n'a jamais eu lieu -- cf.
        # DEFAULT_POWER_CYCLE_COOLDOWN_S pour pourquoi cet état n'a pas besoin de
        # survivre à un redémarrage du démon.
        self._power_cycle_last_attempt_at: Optional[float] = None
        # None au démarrage : première mesure jamais "en transition" (pas d'alerte
        # de retour en ligne avant même d'avoir vu l'appareil hors ligne une fois).
        self._lan_reachable: Optional[bool] = None
        # Horodatage du DÉBUT de la coupure ping en cours (None si joignable) et
        # si on a déjà alerté DESSUS -- cf. self._alert_delay_s.
        self._lan_unreachable_since: Optional[float] = None
        self._lan_ping_alerted: bool = False
        self._transport.on_telemetry(self._on_telemetry)
        self._transport.on_connection_change(self._on_connection_change)
        self._transport.on_connection_issue(self._on_connection_issue)
        self._wire_simulated_grid_power()

    # SimulatedTransport seul expose set_grid_power_sink (pas dans l'ABC Transport,
    # cf. transport/simulated_transport.py) : en mode simulation il n'y a pas de
    # pince réelle ni de listener Jeedom, le scénario synthétique du transport
    # appelle donc on_grid_power() directement. getattr(..., None) plutôt qu'un
    # isinstance pour ne rien connaître de SimulatedTransport ici.
    def _wire_simulated_grid_power(self) -> None:
        set_sink = getattr(self._transport, "set_grid_power_sink", None)
        if set_sink is not None:
            set_sink(self.on_grid_power)

    def start(self) -> None:
        self._transport.connect()

    def stop(self) -> None:
        # Repasse smartMode à 0 avant de couper : sinon l'app mobile reste
        # indéfiniment sur "Mode intelligent" alors que plus personne ne pilote
        # l'appareil (cf. échange avec l'utilisateur sur ce comportement).
        try:
            self._transport.set_smart_mode(False)
        except Exception:
            log.warning("eq_id=%s échec set_smart_mode(False) à l'arrêt", self.eq_id, exc_info=True)
        self._transport.disconnect()

    def request_telemetry(self) -> None:
        self._transport.request_telemetry()

    def _maybe_send_alert(self, alert_id: str, message: str) -> None:
        """Point de passage unique pour toute alerte humaine (mail/push, cf.
        callback_client.py::send_alert) -- respecte l'interrupteur
        alert_mail_active (config, onglet Comportement). Couvre aussi les
        résolutions (*_resolu) : si les alertes sont coupées, inutile de
        notifier le retour à la normale de quelque chose qui n'a jamais été
        signalé. L'événement reste tout de même loggé côté démon (log.warning/
        log.info juste avant chaque appel) et côté Jeedom (log::add(), cf.
        callback.php) -- seule la notification humaine est supprimée."""
        if not self._alert_mail_active:
            return
        self._callback.send_alert(self.eq_id, alert_id, message)

    def check_telemetry_staleness(self) -> None:
        """Appelé depuis la boucle périodique du démon (cf. zendure_daemon.py,
        même cadence que le ping request_telemetry()) : détecte un appareil qui ne
        répond plus (WiFi du boîtier instable, cf. échange avec l'utilisateur
        2026-07-22) alors que NOTRE connexion MQTT au broker est propre -- un cas
        que le flapping détecté côté transport (mqtt_transport.py) ne couvre pas,
        puisque la connexion peut très bien rester stable pendant que l'appareil
        lui-même ne pousse plus rien.

        Appelée APRÈS check_lan_reachability() dans la boucle (cf. zendure_daemon.py)
        pour que self._lan_reachable soit frais du même cycle : sert à affiner le
        diagnostic du message -- incident réel du 2026-08-24 où ce message
        générique a fait conclure à tort à un WiFi instable côté boîtier, alors que
        l'appareil répondait très bien au ping (cause réelle : listener TLS local
        disparu après un redémarrage du VM, cf. project_local_mqtt_chemin_b). Avec
        le ping actif, on peut désormais distinguer les deux cas au lieu de
        toujours supposer le même coupable.

        Trois seuils distincts, volontairement pas le même délai (cf.
        AUTO_HEAL_STALE_S, DEFAULT_POWER_CYCLE_STALE_S) : l'auto-heal (ss -K, sans
        risque réel) s'arme bien avant l'alerte humaine (mail/push, qui doit rester
        prudente -- délai réglable par l'utilisateur, self._alert_delay_s), elle-même
        bien avant le redémarrage forcé (coupure physique via une prise pilotée,
        dernier recours -- self._power_cycle_stale_s, toujours clampé au-dessus des
        deux autres)."""
        elapsed = time.monotonic() - self._last_telemetry_at
        minutes = elapsed // 60

        # -- Auto-heal (seuil court) ------------------------------------------
        if elapsed >= AUTO_HEAL_STALE_S and not self._auto_heal_attempted:
            self._auto_heal_attempted = True
            # Ping OK + télémétrie muette = signature du zombie TCP découvert le
            # 2026-08-24 (connexion ESTABLISHED côté noyau, jamais nettoyée avant
            # ~15 min faute d'erreur détectée par personne -- ni le boîtier, ni
            # Mosquitto, ni notre transport). Une seule tentative par épisode de
            # silence (edge-triggered, comme _telemetry_stale plus bas). Si
            # l'appareil n'a en fait aucun souci réel, tuer une connexion déjà
            # morte/absente ne fait rien de nuisible.
            if (
                self._auto_heal_active
                and self._lan_ping_active
                and self._device_ip
                and self._lan_reachable is True
            ):
                log.warning(
                    "eq_id=%s aucune télémétrie depuis %ds, ping OK -- tentative auto-heal",
                    self.eq_id, int(elapsed),
                )
                kill_zombie_connection(self._device_ip)
        elif elapsed < AUTO_HEAL_STALE_S and self._auto_heal_attempted:
            self._auto_heal_attempted = False

        # -- Alerte humaine (seuil long, réglable) ------------------------------
        if elapsed >= self._alert_delay_s and not self._telemetry_stale:
            self._telemetry_stale = True
            log.warning("eq_id=%s aucune télémétrie depuis %ds", self.eq_id, int(elapsed))
            if self._lan_ping_active and self._device_ip and self._lan_reachable is True:
                message = (
                    "Aucune télémétrie Zendure reçue depuis %d minutes, mais le boîtier "
                    "répond bien au ping (%s) -- la coupure ne vient donc probablement PAS "
                    "de son WiFi. Vérifier la chaîne MQTT locale (listener TLS 8883 sur le "
                    "broker local, override DNS, transport_connected)." % (minutes, self._device_ip)
                )
                if self._auto_heal_active:
                    message += (
                        " Une tentative de reconnexion forcée automatique (auto-heal) a déjà "
                        "eu lieu à %ds sans suffire -- investigation manuelle nécessaire."
                        % int(AUTO_HEAL_STALE_S)
                    )
            elif self._lan_ping_active and self._device_ip and self._lan_reachable is False:
                message = (
                    "Aucune télémétrie Zendure reçue depuis %d minutes -- le boîtier ne répond "
                    "plus non plus au ping (%s), cohérent avec une coupure WiFi/alimentation "
                    "côté appareil." % (minutes, self._device_ip)
                )
            else:
                message = (
                    "Aucune télémétrie Zendure reçue depuis %d minutes (connexion MQTT pourtant "
                    "stable) -- probable WiFi instable côté boîtier (ping réseau local non "
                    "configuré, diagnostic non confirmable -- cf. onglet Config avancée)." % minutes
                )
            self._maybe_send_alert("telemetry_stale", message)
        elif elapsed < self._alert_delay_s and self._telemetry_stale:
            self._telemetry_stale = False
            log.info("eq_id=%s télémétrie de retour après %ds de silence", self.eq_id, int(elapsed))
            self._maybe_send_alert("telemetry_stale_resolu", "Télémétrie Zendure de retour.")

        # -- Redémarrage forcé (dernier recours, seuil le plus long) ------------
        # Toujours > AUTO_HEAL_STALE_S et > self._alert_delay_s par construction
        # (cf. _clamp_power_cycle_stale_s()) : à ce stade, l'auto-heal a déjà eu sa
        # chance ET l'utilisateur a déjà été alerté -- ne reste que la coupure
        # physique comme prochaine étape possible.
        if elapsed >= self._power_cycle_stale_s and not self._power_cycle_attempted:
            self._power_cycle_attempted = True
            cooldown_ok = (
                self._power_cycle_last_attempt_at is None
                or (time.monotonic() - self._power_cycle_last_attempt_at) >= self._power_cycle_cooldown_s
            )
            if not self._power_cycle_active:
                pass  # option désactivée : rien à faire, pas la peine de logger à chaque cycle
            elif not cooldown_ok:
                log.warning(
                    "eq_id=%s seuil de redémarrage forcé atteint (%ds) mais anti-répétition actif "
                    "(dernière tentative il y a moins de %ds) -- ignoré",
                    self.eq_id, int(elapsed), int(self._power_cycle_cooldown_s),
                )
            else:
                self._power_cycle_last_attempt_at = time.monotonic()
                reason = (
                    "Aucune télémétrie Zendure reçue depuis %d minutes malgré l'auto-heal et "
                    "l'alerte déjà déclenchés" % minutes
                )
                log.warning("eq_id=%s %s -- déclenchement du redémarrage forcé", self.eq_id, reason)
                ok = self._callback.send_power_cycle_request(
                    self.eq_id, reason, self._power_cycle_off_duration_s
                )
                if ok:
                    # Horodatage humain (pas monotonic) : affiché tel quel côté Jeedom
                    # (power_cycle_last_at, cf. zendure::INFO_COMMANDS) pour voir dans le
                    # temps si ça se reproduit, sans avoir à rouvrir les logs à chaque fois.
                    self._callback.send_event(
                        self.eq_id, {"power_cycle_last_at": time.strftime("%Y-%m-%d %H:%M:%S")}
                    )
                else:
                    log.warning(
                        "eq_id=%s échec de la demande de redémarrage forcé (cf. log Jeedom pour la cause)",
                        self.eq_id,
                    )
        elif elapsed < self._power_cycle_stale_s and self._power_cycle_attempted:
            self._power_cycle_attempted = False

    def maybe_ble_failover(self) -> None:
        """Appelé depuis la boucle périodique du démon, à la cadence du cron HP
        (5 min, cf. zendure_daemon.py::BLE_FAILOVER_INTERVAL_S -- demande
        explicite de l'utilisateur : pas de connexion BLE permanente, pour ne
        pas monopoliser l'adaptateur partagé avec TheengsGateway, cf.
        transport/ble_fallback.py). Secours, pas un canal principal : ne fait
        rien tant que la télémétrie MQTT n'est pas confirmée muette
        (self._telemetry_stale, cf. check_telemetry_staleness()) et que
        l'option n'est pas explicitement activée en config (désactivée par
        défaut)."""
        if not self._ble_failover_active or not self._ble_address:
            return
        if not self._telemetry_stale:
            return
        log.info("eq_id=%s télémétrie MQTT muette, tentative de secours BLE (%s)", self.eq_id, self._ble_address)
        try:
            properties, pack_data = asyncio.run(fetch_ble_snapshot(self._ble_address, BLE_FAILOVER_TIMEOUT_S))
        except Exception:
            log.warning("eq_id=%s échec du secours BLE", self.eq_id, exc_info=True)
            return
        if not properties:
            log.warning("eq_id=%s secours BLE : connexion établie mais aucune propriété reçue", self.eq_id)
            return
        log.info("eq_id=%s secours BLE : %d propriété(s) récupérée(s)", self.eq_id, len(properties))
        # Ne met PAS à jour self._last_telemetry_at : l'alerte "télémétrie MQTT
        # muette" reste active tant que le WiFi du boîtier n'est pas vraiment
        # rétabli -- le secours BLE comble le manque d'information, il ne
        # doit pas masquer le vrai problème sous-jacent (cf. échange
        # utilisateur du 2026-07-22).
        self._push_values(translate_properties({"properties": properties, "packData": pack_data}))

    def check_lan_reachability(self) -> None:
        """Appelé depuis la boucle périodique du démon, même cadence que
        request_telemetry() (cf. zendure_daemon.py). Signal indépendant de
        transport_connected (connexion MQTT DU DÉMON au broker) et de
        check_telemetry_staleness() (silence télémétrie côté MQTT) : teste si le
        BOÎTIER lui-même répond encore sur le réseau local (ICMP), que la liaison
        MQTT/cloud fonctionne ou non. Permet de distinguer en un coup d'oeil une
        coupure WiFi totale de l'appareil (ni ping, ni MQTT, ni BLE) d'un appareil
        bien présent sur le LAN mais dont seul le cloud Zendure est injoignable
        (ping OK, silence MQTT persistant) -- jusqu'ici indiscernable l'un de
        l'autre depuis le silence télémétrie seul. Désactivé par défaut, nécessite
        device_ip (onglet Config avancée) : pas d'IP fixe garantie sans réservation
        DHCP côté routeur, mieux vaut que l'utilisateur l'active explicitement une
        fois cette réservation faite plutôt qu'un ping silencieusement dans le vide.

        L'état (self._lan_reachable, cmd lan_ping_online/lan_ping_ms) est mis à
        jour à CHAQUE cycle (60s), sans délai -- consommé immédiatement par
        check_telemetry_staleness()/l'auto-heal. Seule l'ALERTE humaine
        (mail/push) est différée jusqu'à self._alert_delay_s : signalé par
        l'utilisateur le 2026-08-24 après 3 coupures WiFi courtes (~1 min,
        connues/attendues, cf. project_device_wifi_instability) ayant chacune
        généré un mail immédiat -- même délai réglable, et même interrupteur
        (_maybe_send_alert), que check_telemetry_staleness() : une coupure qui
        se résout d'elle-même sous ce délai ne mérite pas de déranger
        l'utilisateur."""
        if not self._lan_ping_active or not self._device_ip:
            return
        reachable, rtt_ms = ping_once(self._device_ip)
        self._callback.send_event(
            self.eq_id,
            {"lan_ping_online": reachable, "lan_ping_ms": rtt_ms if rtt_ms is not None else 0},
        )
        now = time.monotonic()
        was_known = self._lan_reachable is not None

        if reachable:
            if self._lan_ping_alerted:
                # On n'avait alerté que parce que la coupure avait dépassé le
                # seuil -- symétrique, on informe donc du retour.
                log.info("eq_id=%s ping local (%s) : appareil de nouveau joignable", self.eq_id, self._device_ip)
                self._maybe_send_alert(
                    "lan_ping_resolu",
                    "Le boîtier Zendure répond de nouveau sur le réseau local.",
                )
            elif not was_known:
                log.info("eq_id=%s ping local (%s) : état initial joignable", self.eq_id, self._device_ip)
            self._lan_reachable = True
            self._lan_unreachable_since = None
            self._lan_ping_alerted = False
            return

        # Injoignable : démarre (ou poursuit) le chrono de cette coupure.
        if self._lan_reachable is not False:
            self._lan_unreachable_since = now
            if not was_known:
                log.info("eq_id=%s ping local (%s) : état initial injoignable", self.eq_id, self._device_ip)
        self._lan_reachable = False
        elapsed_down = now - (self._lan_unreachable_since or now)
        if elapsed_down >= self._alert_delay_s and not self._lan_ping_alerted:
            self._lan_ping_alerted = True
            log.warning("eq_id=%s ping local (%s) : injoignable depuis %ds", self.eq_id, self._device_ip, int(elapsed_down))
            self._maybe_send_alert(
                "lan_ping_offline",
                "Le boîtier Zendure ne répond plus sur le réseau local (ping) depuis %d minutes -- "
                "probable coupure WiFi ou d'alimentation côté appareil." % (elapsed_down // 60),
            )

    # Clés qui déterminent la connexion transport : si l'une change (mode, host,
    # credentials...), il faut reconnecter, pas juste mettre à jour le régulateur.
    _TRANSPORT_KEYS = (
        "mode_connexion",
        "device_id",
        "product_key",
        "cloud_host",
        "cloud_port",
        "cloud_tls",
        "cloud_username",
        "cloud_auth_key",
        "cloud_client_id",
        "local_host",
        "local_port",
        "local_tls",
        "local_tls_insecure",
        "local_username",
        "local_password",
    )

    def reload_config(self, eq_config: dict) -> None:
        transport_changed = any(
            self._eq_config.get(key) != eq_config.get(key) for key in self._TRANSPORT_KEYS
        )
        self._eq_config = eq_config
        self._regulator.reload_config(AntiInjectionConfig.from_dict(eq_config.get("anti_injection", {})))
        self._throttle.min_interval_s = float(eq_config.get("telemetry_min_interval_s", 300))
        self._throttle.noise_threshold = float(eq_config.get("telemetry_noise_threshold", 3))
        self._ble_failover_active = bool(eq_config.get("ble_failover_active", False))
        self._ble_address = str(eq_config.get("ble_address") or "")
        self._lan_ping_active = bool(eq_config.get("lan_ping_active", False))
        self._device_ip = str(eq_config.get("device_ip") or "")
        self._auto_heal_active = bool(eq_config.get("auto_heal_active", False))
        self._alert_mail_active = bool(eq_config.get("alert_mail_active", True))
        self._alert_delay_s = float(eq_config.get("alert_delay_s", DEFAULT_ALERT_DELAY_S))
        self._power_cycle_active = bool(eq_config.get("power_cycle_active", False))
        self._power_cycle_stale_s = self._clamp_power_cycle_stale_s(eq_config)
        self._power_cycle_off_duration_s = float(
            eq_config.get("power_cycle_off_duration_s", DEFAULT_POWER_CYCLE_OFF_DURATION_S)
        )
        self._power_cycle_cooldown_s = float(
            eq_config.get("power_cycle_cooldown_s", DEFAULT_POWER_CYCLE_COOLDOWN_S)
        )

        if transport_changed:
            log.info("eq_id=%s configuration transport modifiée, reconnexion", self.eq_id)
            self._transport.disconnect()
            self._transport = build_transport(eq_config)
            self._transport.on_telemetry(self._on_telemetry)
            self._transport.on_connection_change(self._on_connection_change)
            self._transport.on_connection_issue(self._on_connection_issue)
            self._wire_simulated_grid_power()
            self._transport.connect()

    def _clamp_power_cycle_stale_s(self, eq_config: dict) -> float:
        """Lit power_cycle_stale_s en le remontant si besoin au-dessus de l'auto-heal
        ET du délai d'alerte (self._alert_delay_s, déjà à jour à l'appel -- lu APRÈS
        dans __init__/reload_config) : le redémarrage forcé est un DERNIER recours,
        censé n'intervenir qu'après que l'auto-heal a eu sa chance et que l'utilisateur
        a déjà été prévenu. Sans ce clamp, une config incohérente (ex. valeur par
        erreur plus courte que le délai d'alerte) romprait cette garantie silencieusement
        -- ici elle reste vraie par construction, quoi que l'utilisateur saisisse.
        Marge de 60s (pas juste `>`) : laisse le temps aux deux autres tiers de
        réellement s'exécuter sur un cycle du démon avant que celui-ci n'entre en jeu,
        plutôt qu'un simple ex-aequo d'arrondi."""
        configured = float(eq_config.get("power_cycle_stale_s", DEFAULT_POWER_CYCLE_STALE_S))
        floor = max(AUTO_HEAL_STALE_S, self._alert_delay_s) + 60.0
        return max(configured, floor)

    # logicalId (cf. zendure::ACTION_COMMANDS côté PHP) -> méthode Transport.
    _ACTION_DISPATCH = {
        "set_output_limit": "set_output_limit",
        "set_input_limit": "set_input_limit",
        "set_soc_min": "set_soc_min",
        "set_soc_max": "set_soc_max",
        "set_mode": "set_mode",
        # Exposé côté Jeedom le 2026-07-22 : jusqu'ici set_smart_mode n'était
        # appelé qu'en interne (stop(), repli à 0 sur arrêt propre du démon) --
        # l'utilisateur a besoin de pouvoir le couper manuellement depuis Jeedom
        # (constaté : "Mode intelligent" actif côté app empêchait nos commandes
        # deviceAutomation manuelles d'avoir le moindre effet réel, y compris la
        # charge -- l'appareil suit sa propre logique tant que ce mode est actif).
        "set_smart_mode": "set_smart_mode",
    }

    def on_action(self, logical_id: str, value) -> None:
        """Appelé depuis le socket serveur pour toute commande "action" déclenchée côté
        Jeedom (curseur du dashboard, exécution manuelle d'une commande...) — cf.
        zendureCmd::execute() qui relaie ici via {"type": "action", ...}. Sans ce
        handler le démon logait juste "Type de message inconnu : action" et les
        curseurs du dashboard ne faisaient strictement rien."""
        if logical_id == "debug_capture_1h":
            self._throttle.enable_debug_capture(DEBUG_CAPTURE_DURATION_S)
            log.info("eq_id=%s capture télémétrie complète activée pour %ds", self.eq_id, int(DEBUG_CAPTURE_DURATION_S))
            return
        method_name = self._ACTION_DISPATCH.get(logical_id)
        if method_name is None:
            log.warning("eq_id=%s action non gérée : %s=%s", self.eq_id, logical_id, value)
            return
        try:
            getattr(self._transport, method_name)(int(float(value)))
        except (TypeError, ValueError):
            log.warning("eq_id=%s valeur invalide pour %s : %r", self.eq_id, logical_id, value)

    def on_grid_power(self, value_w: float) -> None:
        """Appelé depuis le socket serveur quand la pince (via listener PHP) rapporte une nouvelle valeur.

        Décharge uniquement (cf. anti_injection.py) : jamais de bascule en charge
        depuis cette boucle rapide, aligné sur le scénario Jeedom de référence.
        Le calcul se base sur la dernière puissance injectée RÉELLEMENT mesurée
        (télémétrie), pas sur une limite qu'on aurait nous-même commandée --
        sauf si cette mesure est périmée (self._injected_stale_after_s), auquel
        cas on bascule sur 0 plutôt que de faire confiance à une valeur qui peut
        dater d'avant une coupure WiFi de l'appareil (grid, lui, reste frais :
        source externe indépendante du WiFi Zendure)."""
        injected_w = self._last_injected_w
        if self._last_injected_at == 0.0 or (time.monotonic() - self._last_injected_at) > self._injected_stale_after_s:
            if injected_w != 0.0:
                log.debug("eq_id=%s injected_power périmé, basculé sur 0 pour ce calcul", self.eq_id)
            injected_w = 0.0
        action = self._regulator.update(value_w, injected_w)
        if action is None:
            return
        log.debug(
            "eq_id=%s grid=%.1fW injected=%.1fW -> discharge %sW",
            self.eq_id, value_w, injected_w, action.power_w,
        )
        self._transport.set_output_limit(action.power_w)
        # Pousse la valeur commandée à Jeedom immédiatement, sans attendre un écho
        # télémétrie de l'appareil : le champ outputLimit renvoyé par l'appareil
        # n'est pas fiable comme miroir temps réel (constaté : dérive spontanée
        # sans action de notre part, cf. README "Points ouverts") -- si on
        # attendait cet écho, le curseur "Limite sortie AC" du widget restait
        # visuellement figé pendant que la boucle rapide agissait réellement
        # (signalé). On connaît la valeur avec certitude ici, on la publie donc
        # nous-même plutôt que de dépendre du device pour se la confirmer.
        self._callback.send_event(self.eq_id, {"output_limit": action.power_w})

    def _on_telemetry(self, frame: TelemetryFrame) -> None:
        self._last_telemetry_at = time.monotonic()
        # La trame report Zendure peut porter des données hors du wrapper "properties"
        # (packData, cluster, wifiName/mac/ip...) : translate_properties() aplatit
        # désormais la trame ENTIÈRE (moins la plomberie protocole), pas juste
        # "properties", pour ne perdre aucune information remontée par l'appareil.
        self._push_values(translate_properties(dict(frame)))

    def _push_values(self, values: dict) -> None:
        """Commun à la télémétrie MQTT normale (_on_telemetry) et au secours BLE
        (maybe_ble_failover) : mêmes règles de filtrage/alias, quelle que soit la
        source. Ne touche PAS self._last_telemetry_at -- appelant au choix."""
        if "injected_power" in values:
            try:
                self._last_injected_w = float(values["injected_power"])
                self._last_injected_at = time.monotonic()
            except (TypeError, ValueError):
                pass
        values = self._throttle.filter(values)
        if values:
            self._callback.send_event(self.eq_id, values)

    def _on_connection_change(self, connected: bool) -> None:
        self._callback.send_event(self.eq_id, {"transport_connected": connected})

    def _on_connection_issue(self, issue_id: str, message: str) -> None:
        self._maybe_send_alert(issue_id, message)
