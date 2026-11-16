#!/usr/bin/env bash
# Prova dell'aggiornamento di gratuito + Pro con dati del Pro (risorse, crediti, pagamento, documento cifrato).
# Uso, dalla radice del repository:  bash wordpress/associazionesemplice/tests/ci/prova-pro.sh VECCHIO-GRATUITO.zip VECCHIO-PRO.zip
# Installa i due zip «vecchi», crea i dati, installa sopra le build correnti (costruite da build.sh) senza disinstallare e controlla.
# Non stampa chiavi né contenuti: solo conteggi e stati.
set -euo pipefail
old_free="$(realpath "$1")"
old_pro="$(realpath "$2")"
here="$(cd "$(dirname "$0")" && pwd)"

bash wordpress/build.sh wordpress/dist-prove
cd wordpress/dist-prove
mkdir prove
cp "$old_free" prove/vecchio-free.zip
cp "$old_pro" prove/vecchio-pro.zip
cp associazionesemplice.zip prove/nuovo-free.zip
cp associazionesemplice-pro.zip prove/nuovo-pro.zip
cp "$here"/prova-dati-comune.php "$here"/prova-pro-*.php prove/
cat > .wp-env.json <<'JSON'
{
  "phpVersion": "8.1",
  "plugins": [],
  "mappings": { "wp-content/prove": "./prove" },
  "config": { "WP_DEBUG": true, "WP_DEBUG_DISPLAY": false, "WP_DEBUG_LOG": true }
}
JSON

wp() { npx --yes @wordpress/env run cli wp "$@"; }

npx --yes @wordpress/env start
wp plugin install wp-content/prove/vecchio-free.zip --activate
wp plugin install wp-content/prove/vecchio-pro.zip --activate
wp plugin list --fields=name,status,version
wp eval-file wp-content/prove/prova-pro-crea.php
wp plugin install wp-content/prove/nuovo-free.zip --force
wp plugin install wp-content/prove/nuovo-pro.zip --force
wp plugin list --fields=name,status,version
wp eval-file wp-content/prove/prova-pro-verifica.php
