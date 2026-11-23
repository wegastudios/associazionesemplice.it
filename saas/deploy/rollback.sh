#!/usr/bin/env bash
# Torna subito alla versione precedente (sul server). Le migrazioni non si annullano: sono in due tempi, quindi la
# versione precedente funziona con lo schema nuovo. Uso: ~/saas/current/deploy/rollback.sh
set -euo pipefail
BASE="${SAAS_BASE:-$HOME/saas}"
PHP="${SAAS_PHP:-/usr/local/bin/php}"
CURRENT="$(readlink "$BASE/current")"
PREVIOUS="$(ls -1dt "$BASE"/releases/*/ | sed 's#/$##' | grep -vxF "$CURRENT" | head -n 1 || true)"
[ -n "$PREVIOUS" ] || { echo "Nessuna versione precedente." >&2; exit 1; }

ln -sfn "$PREVIOUS" "$BASE/current.new" && mv -Tf "$BASE/current.new" "$BASE/current"
cd "$BASE/current" && "$PHP" artisan optimize && "$PHP" artisan queue:restart
# La versione precedente può non avere il comando (rilasci anteriori a H22): in quel caso si prosegue.
"$PHP" artisan saas:web:refresh --no-warm || true
echo "Ora in uso: $PREVIOUS (prima: $CURRENT)"
