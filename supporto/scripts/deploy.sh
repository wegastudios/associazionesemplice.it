#!/usr/bin/env bash
# Rilascio del sito di supporto (supporto.associazionesemplice.it) sul server di Associazione Semplice.
#
# Uso, dalla cartella supporto/ del repository, dopo che i test sono verdi e la PR è unita:
#   SUPPORTO_DEPLOY_KEY=/percorso/della/chiave ./scripts/deploy.sh [--seed-guide]
#
# Regole di sicurezza (storia: il 19/11/2026 copiare public/ ha sostituito il .htaccess del server,
# che contiene il gestore PHP 8.4 di cPanel, e il sito è andato in errore 500):
#   1. public/ NON viene mai copiata: il suo .htaccess è gestito da cPanel e non va sovrascritto.
#      Si copiano solo le cartelle elencate in CARTELLE qui sotto.
#   2. Prima di copiare si salva il .htaccess del server in storage/app/.
#   3. Dopo la copia si controllano /up e la home. Se una delle due non risponde 200 si rimette il
#      .htaccess salvato e si ricontrolla; lo script esce comunque con errore, così nessuno crede che sia andata bene.
set -euo pipefail

HOST="${SUPPORTO_HOST:-associazionesemp@65.108.75.89}"
PORT="${SUPPORTO_PORT:-2299}"
KEY="${SUPPORTO_DEPLOY_KEY:?Serve SUPPORTO_DEPLOY_KEY con il percorso della chiave SSH}"
REMOTE_DIR="${SUPPORTO_REMOTE_DIR:-wega-support}"
PHP="${SUPPORTO_PHP:-/opt/alt/php84/usr/bin/php}"
URL="${SUPPORTO_URL:-https://supporto.associazionesemplice.it}"
# Mai public/: vedi regola 1.
CARTELLE=(app bootstrap config database resources routes)

SEED=0
[ "${1:-}" = "--seed-guide" ] && SEED=1

cd "$(dirname "$0")/.."
for c in "${CARTELLE[@]}"; do [ -d "$c" ] || { echo "Cartella mancante: $c (sei in supporto/?)" >&2; exit 2; }; done
case " ${CARTELLE[*]} " in *" public "*) echo "public/ non si copia mai." >&2; exit 2;; esac

SSH=(ssh -p "$PORT" -i "$KEY" -o IdentitiesOnly=yes "$HOST")
STAMP="$(date +%Y%m%d-%H%M%S)"
BACKUP="$REMOTE_DIR/storage/app/htaccess.prima-deploy-$STAMP"

http_code() { curl -s -o /dev/null -w '%{http_code}' --max-time 20 "$URL$1" || true; }

controlla() {
  local up home
  up="$(http_code /up)"; home="$(http_code /)"
  echo "  /up = $up, home = $home"
  [ "$up" = "200" ] && [ "$home" = "200" ]
}

echo "1/4 Salvo il .htaccess del server in $BACKUP"
"${SSH[@]}" "cp $REMOTE_DIR/public/.htaccess $BACKUP"

echo "2/4 Copio ${CARTELLE[*]} (senza public/)"
tar cf - "${CARTELLE[@]}" | "${SSH[@]}" "cd $REMOTE_DIR && tar xf -"

# Le guide si riseminano da sole quando i testi (database/guides o il seeder) sono diversi da quelli
# dell'ultimo seme sul server; --seed-guide le riserva comunque. L'impronta sta in storage/app/guide-content.sha.
SUM="$(cat database/guides/*.php database/seeders/GuideContentSeeder.php | sha256sum | cut -c1-64)"
SUMFILE="$REMOTE_DIR/storage/app/guide-content.sha"
if [ "$("${SSH[@]}" "cat $SUMFILE 2>/dev/null || true")" != "$SUM" ]; then SEED=1; fi
[ $SEED = 0 ] || echo "  Le guide sono cambiate: le riseminerò."

echo "3/4 Aggiorno il server"
"${SSH[@]}" "cd $REMOTE_DIR && $PHP artisan migrate --force && { [ $SEED = 0 ] || { $PHP artisan db:seed --class=GuideContentSeeder --force && echo $SUM > storage/app/guide-content.sha; }; } && $PHP artisan view:clear && $PHP artisan config:clear"

echo "4/4 Controllo il sito"
if controlla; then
  echo "Rilascio riuscito."
  exit 0
fi

echo "Il sito non risponde 200: rimetto il .htaccess salvato." >&2
"${SSH[@]}" "cp $BACKUP $REMOTE_DIR/public/.htaccess"
if controlla; then
  echo "Dopo il ripristino del .htaccess il sito risponde: il problema era lì." >&2
else
  echo "Il sito non risponde nemmeno dopo il ripristino: guarda storage/logs/laravel.log sul server." >&2
fi
exit 1
