#!/bin/sh
# Crea dist/lu3g-page-notes.zip (cartella lu3g-page-notes/ all'interno), il pacchetto che WordPress installa e aggiorna.
# Esclude i file che servono solo allo sviluppo (git, script, automazioni).
set -e
cd "$(dirname "$0")/.."
rm -rf dist && mkdir -p dist/lu3g-page-notes
cp -R lu3g-page-notes.php readme.txt includes assets dist/lu3g-page-notes/
(cd dist && zip -rq lu3g-page-notes.zip lu3g-page-notes -x '*.DS_Store')
rm -rf dist/lu3g-page-notes
echo "dist/lu3g-page-notes.zip"
