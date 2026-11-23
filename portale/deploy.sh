#!/usr/bin/env bash
# Pubblica il portale sul server: codice in ~/portale (fuori dal web), file pubblici in ~/public_html.
# config.php e data/ sul server non vengono mai toccati. Prima si carica in una cartella d'appoggio e si controlla la sintassi di
# tutti i file PHP: se c'è un errore il sito in funzione non viene toccato. Uso: bash portale/deploy.sh
set -euo pipefail
cd "$(dirname "$0")"
KEY="${PORTALE_KEY:-../deploy_key}"
# Indirizzo, porta e utente del server stanno in un file non tracciato accanto alla chiave (deploy_key.env, vedi deploy-server.env.example): il repository è pubblico.
ENVF="${PORTALE_ENV:-$(dirname "$KEY")/deploy_key.env}"
[ -f "$ENVF" ] && . "$ENVF"
HOST="${PORTALE_HOST:?manca PORTALE_HOST: copia deploy-server.env.example in deploy_key.env accanto alla chiave}"; PORT="${PORTALE_PORT:?manca PORTALE_PORT}"; USER_="${PORTALE_USER:?manca PORTALE_USER}"
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
# Struttura del database con l'utente proprietario (data/pg-migrate.php, non tracciato). Se non riesce lo dice e la pubblicazione, finita
# comunque (il sito non resta a metà), termina con errore: così non passa inosservata una colonna mancante.
migr=0
/usr/local/bin/php ~/portale/bin/migrate.php || migr=1
cd ~/public_html
printf '<?php\n// Punto d'"'"'ingresso del portale: il codice sta fuori dalla cartella pubblica (~/portale).\nrequire __DIR__ . "/../portale/public/index.php";\n' > index.php
mkdir -p assets && cp -r ../portale/public/assets/* assets/
sed -i '/^# Sito non ancora pubblico/,$d;/^# Tutto ci/,$d' .htaccess
{ echo; tr -d '\r' < ../portale/public/.htaccess; } >> .htaccess
sed -i 's/\r$//' .htaccess # niente fine riga Windows: il server web non applicherebbe le regole
chmod 644 .htaccess # leggibile dal server web: con 600 LiteSpeed non applica le regole e tutte le pagine danno 404
rm -f robots.txt
# Lavori giornalieri alle 10:47, non alle 08:00-08:30 (la posta ha un tetto orario per tutto l'account); una riga vecchia a un'altra ora si sostituisce.
( crontab -l 2>/dev/null | grep -q "^47 10 .*portale/bin/cron.php" ) || ( crontab -l 2>/dev/null | grep -v "portale/bin/cron.php"; echo "47 10 * * * /usr/local/bin/php $HOME/portale/bin/cron.php >> $HOME/logs/portale-cron.log 2>&1" ) | crontab -
( crontab -l 2>/dev/null | grep -q "portale/bin/mail-outbox.php" ) || ( crontab -l 2>/dev/null; echo "*/5 * * * * /usr/local/bin/php $HOME/portale/bin/mail-outbox.php >> $HOME/logs/portale-mail-outbox.log 2>&1" ) | crontab -
( crontab -l 2>/dev/null | grep -q "portale/bin/saas-outbox.php" ) || ( crontab -l 2>/dev/null; echo "*/5 * * * * /usr/local/bin/php $HOME/portale/bin/saas-outbox.php >> $HOME/logs/portale-saas-outbox.log 2>&1" ) | crontab -
echo "Pubblicato."
if [ "$migr" = 1 ]; then
	echo "ATTENZIONE: il codice è online ma la migrazione del database non è completa (elenco sopra): crea le colonne mancanti prima di usare le funzioni nuove." >&2
	exit 1
fi
REMOTE
