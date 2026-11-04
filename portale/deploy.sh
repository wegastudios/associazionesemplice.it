#!/usr/bin/env bash
# Pubblica il portale sul server: codice in ~/portale (fuori dal web), file pubblici in ~/public_html.
# config.php e data/ sul server non vengono mai toccati. Prima si carica in una cartella d'appoggio e si controlla la sintassi di
# tutti i file PHP: se c'è un errore il sito in funzione non viene toccato. Uso: bash portale/deploy.sh
set -euo pipefail
cd "$(dirname "$0")"
HOST="${PORTALE_HOST:-65.108.75.89}"; PORT="${PORTALE_PORT:-2299}"; USER_="${PORTALE_USER:-associazionesemp}"
KEY="${PORTALE_KEY:-../deploy_key}"
S=(ssh -i "$KEY" -p "$PORT" -o BatchMode=yes -o IdentitiesOnly=yes "$USER_@$HOST")

tar --exclude=tests --exclude=config.php --exclude=data --exclude=.gitignore -cf - . | "${S[@]}" 'rm -rf ~/portale-next && mkdir -p ~/portale-next && tar -xf - -C ~/portale-next'
"${S[@]}" 'bash -s' <<'REMOTE'
set -e
cd ~/portale-next
bad=0
for f in $(find . -name '*.php'); do
	out=$(php -l "$f" 2>&1) || { echo "ERRORE DI SINTASSI: $out"; bad=1; }
done
if [ "$bad" = 1 ]; then
	rm -rf ~/portale-next
	echo "Pubblicazione annullata: il sito in funzione non è stato toccato." >&2
	exit 1
fi
mkdir -p ~/portale && cp -r ~/portale-next/. ~/portale/ && rm -rf ~/portale-next
cd ~/portale && mkdir -p data
cd ~/public_html
printf '<?php\n// Punto d'"'"'ingresso del portale: il codice sta fuori dalla cartella pubblica (~/portale).\nrequire __DIR__ . "/../portale/public/index.php";\n' > index.php
mkdir -p assets && cp -r ../portale/public/assets/* assets/
sed -i '/^# Sito non ancora pubblico/,$d;/^# Tutto ci/,$d' .htaccess
{ echo; cat ../portale/public/.htaccess; } >> .htaccess
rm -f robots.txt
( crontab -l 2>/dev/null | grep -q "portale/bin/cron.php" ) || ( crontab -l 2>/dev/null; echo "0 8 * * * /usr/local/bin/php $HOME/portale/bin/cron.php >> $HOME/logs/portale-cron.log 2>&1" ) | crontab -
echo "Pubblicato."
REMOTE
