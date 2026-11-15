#!/usr/bin/env bash
# Azzera la demo del gestionale e la ricrea con dati finti. Si installa da sé in ~/saas-demo/reset.sh a ogni rilascio
# dell'ambiente demo (remote.sh), senza argomenti: lo lancia il portale con «/bin/bash ~/saas-demo/reset.sh».
# Rifiuta di girare fuori da una cartella «saas-demo»: la produzione (~/saas) non si tocca mai da qui.
set -euo pipefail

BASE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PHP="${SAAS_PHP:-/usr/local/bin/php}"

if [ "$(basename "$BASE")" != "saas-demo" ]; then
    echo "Questo script va lanciato dalla cartella saas-demo: reset annullato." >&2
    exit 2
fi
if [ ! -d "$BASE/current" ]; then
    echo "La demo del gestionale non è ancora installata ($BASE/current manca): nessun reset." >&2
    exit 3
fi

cd "$BASE/current"
exec "$PHP" artisan saas:demo:reset
