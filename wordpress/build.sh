#!/usr/bin/env bash
# Costruisce i due pacchetti installabili partendo dal codice unico:
#   dist/associazionesemplice.zip → cartella associazionesemplice/      (gratuito, per tutti: WordPress.org e portale; senza i file elencati in associazionesemplice/tests/pro-files.txt)
#   dist/associazionesemplice-pro.zip   → cartella associazionesemplice-pro/  (quei file, che si aggiungono ad Associazione Semplice quando è attivo)
# Uso: [ASEM_WPORG=1] bash wordpress/build.sh [cartella-di-uscita]   (ASEM_WPORG=1: gratuito per WordPress.org, senza l'aggiornatore dal portale)
set -euo pipefail
root="$PWD"
cd "$(dirname "$0")"
out="${1:-wordpress/dist}"
case "$out" in /*) ;; *) out="$root/$out" ;; esac
list="associazionesemplice/tests/pro-files.txt"
rm -rf "$out"
mkdir -p "$out/free" "$out/pro"
cp -r associazionesemplice "$out/free/associazionesemplice"
cp -r associazionesemplice-pro "$out/pro/associazionesemplice-pro"
mkdir -p "$out/pro/associazionesemplice-pro/includes"
# ASEM_WPORG=1: pacchetto per WordPress.org, senza l'aggiornatore dal portale (lì aggiorna WordPress.org)
[ "${ASEM_WPORG:-}" = "1" ] && rm -f "$out/free/associazionesemplice/includes/Updater.php"
# Pacchetto del portale (senza ASEM_WPORG): è l'unico che contatta il portale, quindi solo il suo readme.txt lo dichiara in «External services»
# (testo in readme-portale.txt, fuori dal plugin). Il readme di WordPress.org resta quello del plugin: dice che non si contatta niente per impostazione predefinita.
if [ "${ASEM_WPORG:-}" != "1" ]; then
	rd="$out/free/associazionesemplice/readme.txt"
	awk -v frag="readme-portale.txt" '
		{ sub(/\r$/, "") }
		/^The plugin works fully on its own and contacts no external service by default\./ {
			print "The plugin works fully on its own. This copy, downloaded from associazionesemplice.it, contacts the Associazione Semplice website to look for updates (first item below). The optional features that involve a third party are described after it."
			next
		}
		/^= PayPal donations \(optional\) =/ { while ((getline l < frag) > 0) { sub(/\r$/, "", l); print l } }
		{ print }
	' "$rd" > "$rd.tmp"
	mv "$rd.tmp" "$rd"
	grep -q '^= Updates from the associazionesemplice.it website' "$rd" || { echo "readme del portale: sezione aggiornamenti non inserita" >&2; exit 1; }
fi
rm -rf "$out/free/associazionesemplice/tests" "$out/free/associazionesemplice/vendor" "$out/free/associazionesemplice/composer.json" "$out/free/associazionesemplice/composer.lock" "$out/free/associazionesemplice/phpunit.xml.dist"
n=0
while IFS= read -r f; do
	f="${f%$'\r'}" # file salvati con fine riga di Windows
	[ -z "$f" ] && continue
	case "$f" in "#"*) continue ;; esac
	src="associazionesemplice/includes/$f"
	[ -f "$src" ] || { echo "File avanzato mancante: $f" >&2; exit 1; }
	mkdir -p "$out/pro/associazionesemplice-pro/includes/$(dirname "$f")"
	cp "$src" "$out/pro/associazionesemplice-pro/includes/$f"
	rm "$out/free/associazionesemplice/includes/$f"
	n=$((n + 1))
done < "$list"
(cd "$out/free" && zip -qr ../associazionesemplice.zip associazionesemplice)
(cd "$out/pro" && zip -qr ../associazionesemplice-pro.zip associazionesemplice-pro)
echo "Pacchetti pronti in $out/: associazionesemplice.zip (gratuito), associazionesemplice-pro.zip ($n file avanzati)"
