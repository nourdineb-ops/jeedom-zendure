#!/bin/bash
# Déploie le plugin vers la VM Jeedom (brief §8 : dev en local, Jeedom reste en prod).
# Configurer JEEDOM_HOST / JEEDOM_USER / JEEDOM_PATH via l'environnement ou en dur ci-dessous.
set -euo pipefail

JEEDOM_HOST="${JEEDOM_HOST:?Renseigner JEEDOM_HOST (IP ou nom de la VM Jeedom)}"
JEEDOM_USER="${JEEDOM_USER:-root}"
JEEDOM_PATH="${JEEDOM_PATH:-/var/www/html/plugins/zendure}"

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

rsync -avz --delete \
    --exclude '.git' \
    --exclude 'resources/venv' \
    --exclude '*.pyc' \
    --exclude '__pycache__' \
    "$DIR/" "${JEEDOM_USER}@${JEEDOM_HOST}:${JEEDOM_PATH}/"

echo "Déployé vers ${JEEDOM_USER}@${JEEDOM_HOST}:${JEEDOM_PATH}"
echo "Penser à relancer le démon depuis l'UI Jeedom si nécessaire."
