#!/usr/bin/env bash
# Cerca nel pacchetto gratuito le «tracce» delle funzioni Pro: nomi delle loro classi (quelli in associazionesemplice/tests/pro-files.txt)
# e delle loro tabelle del database. Uso: bash wordpress/check-free-clean.sh [cartella-del-pacchetto] [--strict]
#   senza --strict stampa il rapporto e esce con 0; con --strict esce con 1 se trova qualcosa (si accende in CI a pulizia finita).
# Non contano: la pagina di presentazione del Pro (Admin/ProPage.php, è promozione), l'intestazione «License: GPL» del plugin e la parola
# «Resources» del formato PDF. Le tabelle member_levels, fiscal_years e fund_entries sono dati del nucleo (usati anche dall'edizione gratuita).
set -uo pipefail
cd "$(dirname "$0")"
dir="${1:-dist/free/associazionesemplice}"
strict="${2:-}"
[ "$dir" = "--strict" ] && { strict="--strict"; dir="dist/free/associazionesemplice"; }
[ -d "$dir" ] || { echo "Cartella non trovata: $dir (prima: bash wordpress/build.sh)" >&2; exit 2; }
words=$(tr -d '\r' < associazionesemplice/tests/pro-files.txt | grep -v '^#' | grep -v '^$' | grep '\.php$' | sed 's#.*/##; s#\.php$##' | sort -u)
tables="insurance assoc_policies fivepm woo_links push_subs attendance receipts payments broadcasts broadcast_rcpt resources reservations reservation_guests credit_entries credit_charges resource_waitlist"
hits() { # righe del pacchetto che citano la parola $1, senza i falsi positivi
	grep -rwn --include=*.php "$1" "$dir" | grep -v '/Admin/ProPage.php:' | grep -v ' \* License:' | grep -v '/Resources <<'
}
total=0
echo "== Classi Pro citate nel pacchetto gratuito"
for w in $words; do
	n=$(hits "$w" | wc -l)
	[ "$n" -gt 0 ] && { echo "$n  $w"; total=$((total + n)); }
done | sort -rn
echo "== Tabelle Pro create o citate nel pacchetto gratuito"
for t in $tables; do
	n=$( (grep -rnF --include=*.php "CREATE TABLE {\$p}$t (" "$dir"; grep -rnF --include=*.php "Db::t( '$t' )" "$dir") | wc -l)
	[ "$n" -gt 0 ] && echo "$n  $t"
done
sum=0
for w in $words; do n=$(hits "$w" | wc -l); sum=$((sum + n)); done
echo "(totale righe con classi Pro: $sum)"
if [ "$strict" = "--strict" ]; then
	tabs=0
	for t in $tables; do n=$( (grep -rnF --include=*.php "CREATE TABLE {\$p}$t (" "$dir"; grep -rnF --include=*.php "Db::t( '$t' )" "$dir") | wc -l); tabs=$((tabs + n)); done
	[ $((sum + tabs)) -gt 0 ] && exit 1
fi
exit 0
