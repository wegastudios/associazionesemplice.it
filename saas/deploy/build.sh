#!/usr/bin/env bash
# Prepara il pacchetto di una versione (in GitHub Actions: sul server non c'è Node). Uso: deploy/build.sh <cartella-uscita>
# Il pacchetto contiene codice, dipendenze senza quelle di sviluppo e CSS/JS compilati; niente test, .env o dati.
set -euo pipefail
cd "$(dirname "$0")/.."
OUT="${1:?cartella di uscita}"
mkdir -p "$OUT"

composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader
npm ci --no-audit --no-fund
npm run build

REV="$(git rev-parse --short=10 HEAD)"
echo "$REV" > REVISION

tar --exclude-vcs --exclude='./node_modules' --exclude='./tests' --exclude='./.env' --exclude='./.env.*' --exclude='./storage' \
    --exclude='./keyservice/public' --exclude='./keyservice/config.ini*' --exclude='./docs' --exclude='./deploy/build.sh' \
    --exclude='./phpunit.xml' --exclude='./phpstan.neon' --exclude='./.phpunit.cache' --exclude='./resources/js' --exclude='./resources/css' \
    -czf "$OUT/saas-$REV.tar.gz" .

sha256sum "$OUT/saas-$REV.tar.gz" | cut -d' ' -f1 > "$OUT/saas-$REV.tar.gz.sha256"
echo "$OUT/saas-$REV.tar.gz"
