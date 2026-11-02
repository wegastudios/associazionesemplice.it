#!/usr/bin/env bash
# Controllo statico del pacchetto Pro con le regole di Plugin Check (sicurezza, database, funzioni da evitare).
# Il Pro non passa da Plugin Check; qui le stesse regole girano con PHP_CodeSniffer e il risultato si confronta con
# wordpress/phpcs/pro-baseline.txt: fallisce solo se compare qualcosa di NUOVO. Le voci dell'elenco sono state verificate a mano
# (di solito funzioni del plugin che ripuliscono dentro, che l'analisi non riconosce).
# Uso: bash wordpress/check-pro-static.sh [cartella-del-pro] [--update]   (prima: bash wordpress/build.sh wordpress/dist)
set -euo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
dir=""; update=""
for a in "$@"; do case "$a" in --update) update="--update" ;; *) dir="$a" ;; esac; done
[ -n "$dir" ] || dir="$here/dist/pro/associazionesemplice-pro"
given="$dir"
dir="$(cd "$dir" 2>/dev/null && pwd)" || { echo "Cartella non trovata: $given (prima: bash wordpress/build.sh wordpress/dist)" >&2; exit 2; }
cd "$here"
tools="${PHPCS_DIR:-${TMPDIR:-/tmp}/asem-phpcs}"
if [ ! -x "$tools/vendor/bin/phpcs" ]; then
	mkdir -p "$tools" && cp phpcs/composer.json "$tools/"
	( cd "$tools" && composer install --no-interaction --no-progress --quiet )
	"$tools/vendor/bin/phpcs" --config-set installed_paths "$tools/vendor/wp-coding-standards/wpcs,$tools/vendor/phpcsstandards/phpcsextra,$tools/vendor/phpcsstandards/phpcsutils" > /dev/null
fi
report="$(mktemp)"
"$tools/vendor/bin/phpcs" --standard=phpcs/ruleset.xml --report=json --report-file="$report" "$dir" > /dev/null || true # esce con 1 se trova qualcosa: lo giudica il confronto
php phpcs/compare.php "$report" phpcs/pro-baseline.txt "$dir" $update
