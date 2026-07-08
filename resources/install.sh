#!/bin/bash
# Installe l'environnement Python du démon (venv isolé, pas de dépendance système).
# Appelé par zendure::dependancy_install() (core/class/zendure.class.php).
set -e

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

python3 -m venv "$DIR/venv"
"$DIR/venv/bin/pip" install --upgrade pip
"$DIR/venv/bin/pip" install -r "$DIR/zendure_daemon/requirements.txt"

echo "Installation terminée."
