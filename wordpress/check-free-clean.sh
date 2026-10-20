#!/usr/bin/env bash
# Cerca nel pacchetto gratuito le «tracce» delle funzioni Pro: nomi delle loro classi (quelli in associazionesemplice/tests/pro-files.txt)
# e delle loro tabelle del database. Uso: bash wordpress/check-free-clean.sh [cartella-del-pacchetto] [--strict]
#   senza --strict stampa il rapporto e esce con 0; con --strict esce con 1 se trova qualcosa (si accende in CI a pulizia finita).
set -uo pipefail
cd "$(dirname "$0")"
dir="${1:-dist/free/associazionesemplice}"
strict="${2:-}"
[ "$dir" = "--strict" ] && { strict="--strict"; dir="dist/free/associazionesemplice"; }
[ -d "$dir" ] || { echo "Cartella non trovata: $dir (prima: bash wordpress/build.sh)" >&2; exit 2; }
total=0
words=$(tr -d '' < associazionesemplice/tests/pro-files.txt | grep -v '^#' | grep -v '^$' | grep '\.php$' | sed 's#.*/##; s#\.php$##' | sort -u)
tables="insurance assoc_policies fivepm woo_links push_subs attendance member_levels fiscal_years fund_entries receipts payments broadcasts broadcast_rcpt resources reservations reservation_guests"
echo "== Classi Pro citate nel pacchetto gratuito"
for w in $words; do
	n=$(grep -rwn --include=*.php "$w" "$dir" | wc -l)
	[ "$n" -gt 0 ] && { echo "$n  $w"; total=$((total + n)); }
done | sort -rn
echo "== Tabelle Pro create o citate nel pacchetto gratuito"
for t in $tables; do
	n=$( (grep -rnF --include=*.php "CREATE TABLE {\$p}$t (" "$dir"; grep -rnF --include=*.php "Db::t( '$t' )" "$dir") | wc -l)
	[ "$n" -gt 0 ] && echo "$n  $t"
done
echo "(totale righe con classi Pro: $(for w in $words; do grep -rwn --include=*.php "$w" "$dir"; done | wc -l))"
if [ "$strict" = "--strict" ]; then
	hits=$(for w in $words; do grep -rwn --include=*.php "$w" "$dir"; done | wc -l)
	[ "$hits" -gt 0 ] && exit 1
fi
exit 0
