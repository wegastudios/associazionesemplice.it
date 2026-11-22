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
# Si copiano solo i file versionati in git (contenuto della cartella di lavoro): file non versionati (appunti, prove, copie, zip) non finiscono mai in un pacchetto.
tracked() {
	git ls-files -z -- "$1" | while IFS= read -r -d '' f; do if [ -f "$f" ]; then printf '%s\0' "$f"; fi; done > "$out/tracked.lst"
	[ -s "$out/tracked.lst" ] || { echo "Nessun file versionato in $1 (serve git)" >&2; exit 1; }
	mkdir -p "$2"
	tar --null -T "$out/tracked.lst" -cf - | tar -xf - -C "$2"
	rm -f "$out/tracked.lst"
}
tracked associazionesemplice "$out/free"
tracked associazionesemplice-pro "$out/pro"
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
	# Solo il pacchetto del portale dichiara «Update URI» (così WordPress non propone aggiornamenti con lo stesso nome da WordPress.org); quello di WordPress.org no.
	mf="$out/free/associazionesemplice/associazionesemplice.php"
	awk '{ sub(/\r$/, "") } { print } /^ \* Plugin URI:/ { print " * Update URI:        https://associazionesemplice.it/" }' "$mf" > "$mf.tmp"
	mv "$mf.tmp" "$mf"
	grep -q '^ \* Update URI: *https://associazionesemplice.it/$' "$mf" || { echo "Update URI del pacchetto del portale non inserito" >&2; exit 1; }
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
# Mappa delle classi del pacchetto gratuito (includes/classmap.php): l'autoloader trova il file senza interrogare il disco.
# Si genera DOPO aver tolto i file del Pro e l'aggiornatore, così contiene solo classi presenti; il Pro e il codice sorgente (senza mappa) usano la ricerca di sempre.
inc="$out/free/associazionesemplice/includes"
{
	printf '<?php\n// Generato da wordpress/build.sh: non modificare. Classe (senza il prefisso AssociazioneSemplice\\\\) => file dentro includes/.\ndefined( '"'"'ABSPATH'"'"' ) || exit;\n\nreturn array(\n'
	(cd "$inc" && find . -name '*.php' ! -path './languages/*' ! -name classmap.php | sed 's#^\./##' | LC_ALL=C sort) | while IFS= read -r f; do
		cls="${f%.php}"
		cls="${cls//\//\\\\}"
		printf "\t'%s' => '%s',\n" "$cls" "$f"
	done
	printf ');\n'
} > "$inc/classmap.php"
mapn=$(grep -c "' => '" "$inc/classmap.php")
filn=$(cd "$inc" && find . -name '*.php' ! -path './languages/*' ! -name classmap.php | wc -l)
[ "$mapn" -gt 0 ] && [ "$mapn" -eq "$filn" ] || { echo "Mappa delle classi incompleta ($mapn su $filn file)" >&2; exit 1; }
while IFS= read -r f; do
	f="${f%$'\r'}"
	case "$f" in ""|"#"*) continue ;; esac
	if grep -qF "=> '$f'," "$inc/classmap.php"; then echo "La mappa delle classi cita un file del Pro: $f" >&2; exit 1; fi
done < "$list"
# Pacchetti lingua come file PHP (con OPcache restano compilati e non si decodifica il JSON a ogni richiesta). Se qui non c'è PHP resta il solo JSON, che il plugin sa leggere.
if command -v php > /dev/null 2>&1; then
	for j in "$inc"/languages/*.json; do
		[ -f "$j" ] || continue
		php -r '$j = json_decode( file_get_contents( $argv[1] ), true ); if ( ! is_array( $j ) ) { exit( 1 ); } file_put_contents( $argv[2], "<?php\n// Generato da wordpress/build.sh dal file JSON omonimo: non modificare.\ndefined( \"ABSPATH\" ) || exit;\n\nreturn " . var_export( $j, true ) . ";\n" );' "$j" "${j%.json}.php" || { echo "Pacchetto lingua non convertito: $j" >&2; exit 1; }
		php -r '$a = json_decode( file_get_contents( $argv[1] ), true ); define( "ABSPATH", "/" ); $b = include $argv[2]; exit( $a === $b ? 0 : 1 );' "$j" "${j%.json}.php" || { echo "Il pacchetto lingua PHP non coincide col JSON: $j" >&2; exit 1; }
	done
fi
# Foglio di stile e script del front-end ridotti (sono quelli che il visitatore scarica): nel repository restano leggibili, nel pacchetto si toglie il superfluo.
# Se qui manca perl, o il controllo di coerenza dello strumento non passa (o node, se c'è, trova un errore di sintassi), nel pacchetto resta il sorgente com'è.
for spec in css:frontend.css js:frontend.js; do
	kind="${spec%%:*}"
	asset="$out/free/associazionesemplice/assets/${spec#*:}"
	[ -f "$asset" ] || { echo "Manca $asset" >&2; exit 1; }
	if command -v perl > /dev/null 2>&1 && perl minify-assets.pl "$kind" "$asset" "$asset.min" && { [ "$kind" != "js" ] || ! command -v node > /dev/null 2>&1 || node --check "$asset.min"; }; then
		mv "$asset.min" "$asset"
	else
		rm -f "$asset.min"
		echo "Attenzione: ${spec#*:} resta com'è (non ridotto)" >&2
	fi
done
# L'aggiornatore dal portale sta nel gratuito del portale; il Pro ne porta una copia identica (da qui, un solo sorgente) per aggiornarsi anche quando il gratuito viene da WordPress.org e non lo ha.
mkdir -p "$out/pro/associazionesemplice-pro/updater"
cp associazionesemplice/includes/Updater.php "$out/pro/associazionesemplice-pro/updater/Updater.php"
(cd "$out/free" && zip -qr ../associazionesemplice.zip associazionesemplice)
(cd "$out/pro" && zip -qr ../associazionesemplice-pro.zip associazionesemplice-pro)
echo "Pacchetti pronti in $out/: associazionesemplice.zip (gratuito), associazionesemplice-pro.zip ($n file avanzati)"
