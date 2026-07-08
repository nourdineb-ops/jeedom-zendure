#!/bin/bash
# Nettoyage à la désinstallation du plugin : supprime le venv Python du démon.
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
rm -rf "$DIR/venv"
