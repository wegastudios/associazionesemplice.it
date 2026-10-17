#!/bin/bash
# Prima installazione dell'hub sul server cPanel (utente supportwegastudi). Si esegue sul server:
#   bash ~/wega-support/scripts/provision-hub.sh
# Crea database e utente MySQL, scrive .env (password generata sul posto, mai mostrata), installa le dipendenze di produzione,
# esegue le migrazioni e prepara le cache. Idempotente solo fino al primo avvio: non sovrascrive un .env esistente.
set -euo pipefail

PHP=/opt/alt/php84/usr/bin/php
APP="$HOME/wega-support"
DOMAIN="support.wegastudios.com"
DBNAME="supportwegastudi_hub"
DBUSER="supportwegastudi_hub"

cd "$APP"

if [ -f .env ]; then
    echo ".env esiste gia: mi fermo per non sovrascrivere credenziali." >&2
    exit 1
fi

DBPASS="$(openssl rand -hex 20)"

echo "== Database"
uapi Mysql create_database name="$DBNAME" | grep -E "status|error|does not|already" || true
uapi Mysql create_user name="$DBUSER" password="$DBPASS" | grep -E "status|error|does not|already" || true
uapi Mysql set_privileges_on_database user="$DBUSER" database="$DBNAME" privileges=ALL%20PRIVILEGES | grep -E "status|error|does not|already" || true

echo "== .env"
cp .env.example .env
setenv() { # chiave valore
    local key="$1" value="$2"
    if grep -q "^${key}=" .env; then
        sed -i "s|^${key}=.*|${key}=${value}|" .env
    else
        echo "${key}=${value}" >> .env
    fi
}
setenv APP_ENV production
setenv APP_DEBUG false
setenv APP_URL "https://${DOMAIN}"
setenv APP_ROLE hub
setenv DB_DATABASE "$DBNAME"
setenv DB_USERNAME "$DBUSER"
setenv DB_PASSWORD "$DBPASS"
setenv LOG_LEVEL warning
setenv MAIL_MAILER sendmail
setenv MAIL_FROM_ADDRESS "noreply@wegastudios.com"
chmod 600 .env

echo "== Dipendenze di produzione"
$PHP /usr/local/bin/composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist 2>&1 | tail -3

$PHP artisan key:generate --force

echo "== Migrazioni"
$PHP artisan migrate --force

echo "== Cache"
$PHP artisan storage:link >/dev/null 2>&1 || true
$PHP artisan config:cache
$PHP artisan route:cache
$PHP artisan view:cache

echo "Fatto. Prossimi passi: php artisan wega:admin:create, puntare public_html a public, cron e coda."
