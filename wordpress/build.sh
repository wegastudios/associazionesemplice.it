#!/usr/bin/env bash
# Costruisce i pacchetti installabili partendo dal codice unico:
#   dist/associazionesemplice-free.zip   → cartella associazionesemplice/      (gratuito scaricato dal portale: senza i file elencati in associazionesemplice/tests/pro-files.txt, con l'aggiornatore diretto)
#   dist/associazionesemplice-wporg.zip  → cartella associazionesemplice/      (gratuito per WordPress.org: come sopra, senza i file elencati in associazionesemplice/tests/portal-files.txt)
#   dist/associazionesemplice-pro.zip    → cartella associazionesemplice-pro/  (i file Pro, che si aggiungono ad Associazione Semplice quando è attivo)
# Uso: bash wordpress/build.sh [cartella-di-uscita]
set -euo pipefail
root="$PWD"
cd "$(dirname "$0")"
out="${1:-wordpress/dist}"
case "$out" in /*) ;; *) out="$root/$out" ;; esac
list="associazionesemplice/tests/pro-files.txt"
portal_list="associazionesemplice/tests/portal-files.txt"
rm -rf "$out"
mkdir -p "$out/free" "$out/pro"
cp -r associazionesemplice "$out/free/associazionesemplice"
cp -r associazionesemplice-pro "$out/pro/associazionesemplice-pro"
mkdir -p "$out/pro/associazionesemplice-pro/includes"
rm -rf "$out/free/associazionesemplice/tests" "$out/free/associazionesemplice/vendor" "$out/free/associazionesemplice/composer.json" "$out/free/associazionesemplice/composer.lock" "$out/free/associazionesemplice/phpunit.xml.dist"
n=0
while IFS= read -r f; do
	[ -z "$f" ] && continue
	case "$f" in "#"*) continue ;; esac
	src="associazionesemplice/includes/$f"
	[ -f "$src" ] || { echo "File avanzato mancante: $f" >&2; exit 1; }
	mkdir -p "$out/pro/associazionesemplice-pro/includes/$(dirname "$f")"
	cp "$src" "$out/pro/associazionesemplice-pro/includes/$f"
	rm "$out/free/associazionesemplice/includes/$f"
	n=$((n + 1))
done < "$list"
# Pacchetto per WordPress.org: senza i file che esistono solo nel pacchetto del portale (aggiornamento diretto dal portale)
mkdir -p "$out/wporg"
cp -r "$out/free/associazionesemplice" "$out/wporg/associazionesemplice"
while IFS= read -r f; do
	[ -z "$f" ] && continue
	case "$f" in "#"*) continue ;; esac
	[ -f "associazionesemplice/includes/$f" ] || { echo "File del portale mancante: $f" >&2; exit 1; }
	rm -f "$out/wporg/associazionesemplice/includes/$f"
done < "$portal_list"
(cd "$out/free" && zip -qr ../associazionesemplice-free.zip associazionesemplice)
(cd "$out/wporg" && zip -qr ../associazionesemplice-wporg.zip associazionesemplice)
(cd "$out/pro" && zip -qr ../associazionesemplice-pro.zip associazionesemplice-pro)
echo "Pacchetti pronti in $out/: associazionesemplice-free.zip (portale), associazionesemplice-wporg.zip (WordPress.org), associazionesemplice-pro.zip ($n file avanzati)"
