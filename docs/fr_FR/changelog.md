# Changelog — plugin Zendure

## 0.1.0-beta

Première version publiée.

- Pilotage d'un Zendure Hyper 2000 via le cloud Zendure (MQTT), avec récupération
  assistée des identifiants à partir du token de l'application ZendureApp.
- Boucle anti-injection temps réel, avec cadences et garde-fous distincts selon le
  sens (injection / import). Source réseau : commande Jeedom ou formule.
- Stratégie de charge nocturne en kWh (consommation typique du foyer, prévision
  solaire du lendemain) avec repli sur des seuils fixes.
- Calcul du gain en € (contrats Base, Heures Pleines/Creuses, Tempo).
- Trois dashboards : Condensé, Flux (avec curseurs de pilotage), Résumé.
- Mode Simulation sans appareil.
- Supervision : détection de télémétrie muette et de reconnexions MQTT en rafale,
  alertes mail/push configurables, ping réseau local, secours de télémétrie par
  Bluetooth (lecture seule), redémarrage forcé optionnel via une prise commandable.
- Journal d'incidents persistant (`data/zendure/incidents.log`).
- Multi-équipement.
