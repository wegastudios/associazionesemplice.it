#!/usr/bin/env bash
# Prenotazione di gruppo, versioni: 1.3.38 → build corrente (schema 76 → 77) con dati, ritorno alla 1.3.38 con lo schema 77 già creato, di nuovo avanti;
# coppie: Pro 1.3.24 + gratuito nuovo; Pro nuovo (minimo alzato) + gratuito 1.3.38 = sospeso con avviso, poi riprende.
# Uso dalla radice (clone completo): bash .github/scripts/prova-gruppo-versioni.sh
set -uo pipefail
work="${RUNNER_TEMP:-/tmp}/gruppo-versioni"; rm -rf "$work"; mkdir -p "$work/prove"
fail=0; ko() { echo "- MANCA $*"; echo "::error::$*"; fail=1; }; si() { echo "- ok $*"; }
root="$PWD"
git worktree add --detach "$work/src" 4262573a044f3aa349d83acf649b12101b7d3fa9 >/dev/null 2>&1 || { ko "commit della 1.3.38 non trovato"; exit 1; }
(cd "$work/src" && bash wordpress/build.sh "$work/vecchio" >/dev/null) || { ko "build 1.3.38"; exit 1; }
# la build corrente come sarà alla pubblicazione: numero e minimo del Pro alzati solo in questa copia
git worktree add --detach "$work/cur" HEAD >/dev/null 2>&1
sed -i -E "s/^( \* Version: +)[0-9.]+/\11.3.99/; s/(define\( 'ASEM_VERSION', ')[0-9.]+/\11.3.99/" "$work/cur/wordpress/associazionesemplice/associazionesemplice.php"
sed -i -E "s/^( \* Version: +)[0-9.]+/\11.3.98/; s/(define\( 'ASEM_PRO_VERSION', ')[0-9.]+/\11.3.98/; s/(define\( 'ASEM_PRO_MIN_FREE', ')[0-9.]+/\11.3.99/" "$work/cur/wordpress/associazionesemplice-pro/associazionesemplice-pro.php"
(cd "$work/cur" && bash wordpress/build.sh "$work/nuovo" >/dev/null) || { ko "build corrente"; exit 1; }
cp "$work/vecchio/associazionesemplice.zip" "$work/prove/vf.zip"; cp "$work/vecchio/associazionesemplice-pro.zip" "$work/prove/vp.zip"
cp "$work/nuovo/associazionesemplice.zip" "$work/prove/nf.zip"; cp "$work/nuovo/associazionesemplice-pro.zip" "$work/prove/np.zip"
cp "$root"/wordpress/associazionesemplice/tests/ci/prova-dati-*.php "$root"/.github/scripts/versioni-gruppo-*.php "$work/prove/"
cd "$work"
cat > .wp-env.json <<'JSON'
{ "phpVersion": "8.1", "plugins": [], "mappings": { "wp-content/prove": "./prove" }, "config": { "WP_DEBUG": true, "WP_DEBUG_DISPLAY": false, "WP_DEBUG_LOG": true } }
JSON
wp() { npx --yes @wordpress/env run cli wp "$@"; }
val() { wp eval "echo '<<' . ( $1 ) . '>>';" 2>/dev/null | grep -a -o "<<.*>>" | head -1 | sed -e "s/^<<//" -e "s/>>\$//"; }
code() { curl -s -o /dev/null -w '%{http_code}' "$1"; }
sito() { for u in / /wp-login.php; do [ "$(code "http://localhost:8888$u")" = "200" ] || ko "$1: $u non risponde 200"; done; }
inst() { wp plugin install "wp-content/prove/$1.zip" --force --activate 2>&1 | grep -iE "fatal|parse error" && ko "$2: errore fatale"; wp eval 'echo "ok";' >/dev/null 2>&1; }
prova() { wp eval-file wp-content/prove/$1 2>&1 | grep -aE "^(ok|- |Success|Error|FAIL|Fatal|PHP)" ; [ "${PIPESTATUS[0]}" = 0 ] || ko "$2"; }
npx --yes @wordpress/env start >/dev/null 2>&1 || { ko "wp-env non parte"; exit 1; }

echo "### 1. Gratuito 1.3.38 con dati → build corrente"
inst vf "1.3.38"
wp eval-file wp-content/prove/prova-dati-crea.php > crea.txt 2>&1; grep -aE "^(ok|Error)" crea.txt # l'ultimo controllo (un evento per lavoro) vale solo per versioni più vecchie della 1.3.30
grep -qa "^ok - impronta prima" crea.txt && si "dati creati con la 1.3.38" || ko "dati creati con la 1.3.38"
if grep -a "^Error" crea.txt | grep -qv "lavori periodici della versione di prima"; then ko "errore creando i dati"; fi
[ "$(val 'get_option("asem_db_version")')" = 76 ] && si "schema 76 con la 1.3.38" || ko "schema atteso 76"
inst nf "aggiornamento"; [ "$(val 'get_option("asem_db_version")')" = 77 ] && si "schema 77 dopo l'aggiornamento" || ko "schema atteso 77"
prova prova-dati-verifica.php "dati conservati dopo l'aggiornamento"; prova versioni-gruppo-nuovo.php "prenotazione di gruppo dopo l'aggiornamento"; sito "dopo l'aggiornamento"

echo "### 2. Ritorno alla 1.3.38 con lo schema 77 già creato"
inst vf "ritorno"; echo "schema salvato: $(val 'get_option("asem_db_version")')"
prova versioni-gruppo-vecchio.php "la 1.3.38 lavora con le colonne nuove"; sito "dopo il ritorno"

echo "### 3. Di nuovo avanti"
inst nf "secondo aggiornamento"; [ "$(val 'get_option("asem_db_version")')" = 77 ] && si "schema 77" || ko "schema atteso 77 al secondo giro"
prova versioni-gruppo-dopo.php "i dati della prenotazione di gruppo ci sono ancora"; sito "secondo aggiornamento"

echo "### 4. Pro 1.3.24 + gratuito nuovo"
inst vp "Pro 1.3.24 sul gratuito nuovo"; p="$(val 'asem_pro_problem()')"; [ -z "$p" ] && si "il Pro 1.3.24 lavora col gratuito nuovo" || ko "Pro 1.3.24 sospeso: $p"
prova versioni-gruppo-dopo.php "gruppo con il Pro 1.3.24"; sito "Pro 1.3.24 + nuovo"; prova "versioni-gruppo-pagamento.php vecchio" "pagamento simulato con il Pro 1.3.24"

echo "### 5. Pro nuovo + gratuito 1.3.38 (minimo alzato): sospeso con avviso, poi riprende"
inst np "Pro nuovo"; p="$(val 'asem_pro_problem()')"; [ -z "$p" ] && si "coppia nuova: il Pro lavora" || ko "coppia nuova sospesa: $p"; sito "coppia nuova"; prova "versioni-gruppo-pagamento.php nuovo" "pagamento simulato con il Pro nuovo"
inst vf "gratuito 1.3.38 sotto il Pro nuovo"; p="$(val 'asem_pro_problem()')"; case "$p" in *"ha bisogno di"*) si "Pro nuovo sospeso con avviso sulla 1.3.38";; *) ko "nessun avviso con la 1.3.38: $p";; esac; sito "Pro nuovo + 1.3.38"
inst nf "gratuito nuovo"; p="$(val 'asem_pro_problem()')"; [ -z "$p" ] && si "il Pro riprende da solo" || ko "Pro ancora sospeso: $p"; sito "ripresa"
wp eval 'echo "ok";' >/dev/null; npx --yes @wordpress/env run cli sh -c 'grep -aE "PHP (Fatal|Parse)" wp-content/debug.log 2>/dev/null | tail -5' | grep -a "PHP" && ko "errori fatali nel registro"
exit $fail
