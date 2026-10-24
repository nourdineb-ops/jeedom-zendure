#!/bin/bash
# Installe l'environnement Python du démon (venv isolé dans resources/venv).
# Appelé par zendure::dependancy_install() (core/class/zendure.class.php) ;
# $1 = fichier de progression lu par Jeedom (optionnel).
set -e

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROGRESS_FILE="${1:-}"

progress() {
    echo "== $2"
    [ -n "$PROGRESS_FILE" ] && echo "$1" > "$PROGRESS_FILE"
    return 0
}

progress 10 "Vérification de Python 3 et du module venv"
if ! python3 -c "import venv, ensurepip" 2>/dev/null; then
    if command -v apt-get >/dev/null 2>&1; then
        apt-get update
        apt-get install -y python3-venv python3-pip
    else
        echo "Python 3 avec le module venv est requis (paquet python3-venv sous Debian/Ubuntu)." >&2
        exit 1
    fi
fi

progress 30 "Création de l'environnement virtuel"
rm -rf "$DIR/venv"
python3 -m venv "$DIR/venv"

progress 50 "Installation des paquets Python du démon"
"$DIR/venv/bin/pip" install --upgrade pip
"$DIR/venv/bin/pip" install -r "$DIR/zendure_daemon/requirements.txt"

# Le script tourne en root (lancé par Jeedom) : le venv doit appartenir au même
# utilisateur que le plugin (www-data), sinon Jeedom ne peut pas le supprimer
# proprement à la désinstallation (son repli « sudo rm -rf » ne protège pas les
# chemins contenant des espaces ou des parenthèses, comme le gabarit setuptools
# « script (dev).tmpl », inutile ici).
find "$DIR/venv" -name '* *' -type f -delete 2>/dev/null || true
chown -R --reference="$DIR" "$DIR/venv" 2>/dev/null || true

progress 100 "Installation terminée"
rm -f "$PROGRESS_FILE" 2>/dev/null || true
