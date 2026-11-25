#!/usr/bin/env bash
# Dalle versioni di prima ai pacchetti PUBBLICATI (gratuito e Pro scaricati dal portale), in WordPress vero (wp-env).
# Scenario A (gratuito 1.3.36 + Pro 1.3.23): aggiorno prima il Pro, poi il gratuito → sempre sito 200, Pro attivo e senza avviso, nessun indice mancante.
# Scenario B (gratuito 1.3.35 + Pro 1.3.23): aggiorno il Pro da solo → il Pro si sospende con un avviso, sito 200, area amministrativa raggiungibile;
#   poi aggiorno il gratuito → il Pro riprende da solo, nessun indice mancante.
# Scenario C (gratuito 1.3.25 della radice + Pro pubblicato): il Pro si sospende con un avviso, sito 200 (la coppia che non deve dare errore).
# Uso, dalla radice del repository (clone completo):
#   bash wordpress/associazionesemplice/tests/ci/prova-aggiornamento-pubblicati.sh GRATUITO-PUBBLICATO.zip PRO-PUBBLICATO.zip GRATUITO-1.3.25.zip
set -uo pipefail
pubfree="$(readlink -f "$1")"; pubpro="$(readlink -f "$2")"; free125="$(readlink -f "$3")"
root="$PWD"
work="${RUNNER_TEMP:-/tmp}/aggiorna-pubblicati"
rm -rf "$work"; mkdir -p "$work/prove"
fail=0
ko() { echo "::error::$*"; fail=1; }

# i pacchetti di partenza vengono costruiti dai commit delle versioni uscite (1.3.36/1.3.23 insieme; il gratuito 1.3.35 a parte)
build_from() { # commit nome
  git worktree add --detach "$work/src-$2" "$1" >/dev/null 2>&1 || { ko "commit $1 non trovato"; exit 1; }
  (cd "$work/src-$2" && bash wordpress/build.sh "$work/out-$2" >/dev/null) || { ko "build di $1 non riuscita"; exit 1; }
}
build_from c30b5143 v136
build_from 5d0811d4 v135
cp "$work/out-v136/associazionesemplice.zip" "$work/prove/free-1.3.36.zip"
cp "$work/out-v136/associazionesemplice-pro.zip" "$work/prove/pro-1.3.23.zip"
cp "$work/out-v135/associazionesemplice.zip" "$work/prove/free-1.3.35.zip"
cp "$pubfree" "$work/prove/free-pub.zip"; cp "$pubpro" "$work/prove/pro-pub.zip"; cp "$free125" "$work/prove/free-1.3.25.zip"
for f in free-1.3.36 free-1.3.35; do unzip -p "$work/prove/$f.zip" associazionesemplice/associazionesemplice.php | grep -E "^ \* Version:" | sed "s/^/$f: /"; done
unzip -p "$work/prove/pro-1.3.23.zip" associazionesemplice-pro/associazionesemplice-pro.php | grep -E "^ \* Version:" | sed 's/^/pro-1.3.23: /'
cd "$work"
cat > .wp-env.json <<'JSON'
{ "phpVersion": "8.1", "plugins": [], "mappings": { "wp-content/prove": "./prove" }, "config": { "WP_DEBUG": true, "WP_DEBUG_DISPLAY": false, "WP_DEBUG_LOG": true } }
JSON
wp() { npx --yes @wordpress/env run cli wp "$@"; }
val() { wp eval "echo '<<' . ( $1 ) . '>>';" 2>/dev/null | grep -a -o "<<.*>>" | head -1 | sed -e "s/^<<//" -e "s/>>\$//"; }
code() { curl -s -o /dev/null -w '%{http_code}' "$1"; }
site_ok() { # etichetta
  [ "$(code http://localhost:8888/)" = "200" ] || ko "$1: il sito pubblico non risponde 200"
  [ "$(code http://localhost:8888/wp-login.php)" = "200" ] || ko "$1: wp-login non risponde 200"
}
reset_site() { wp plugin deactivate --all >/dev/null 2>&1; wp plugin delete associazionesemplice associazionesemplice-pro >/dev/null 2>&1; wp option delete asem_pro_db_version >/dev/null 2>&1; }
[ -d "$root/wordpress/dist-prove" ] && (cd "$root/wordpress/dist-prove" && npx --yes @wordpress/env stop >/dev/null 2>&1) # l'ambiente della prova precedente occupa la stessa porta
npx --yes @wordpress/env start >/dev/null 2>&1 || { ko "wp-env non parte"; exit 1; }

echo "### A. gratuito 1.3.36 + Pro 1.3.23 → Pro pubblicato, poi gratuito pubblicato"
wp plugin install wp-content/prove/free-1.3.36.zip --activate >/dev/null 2>&1 || ko "A: gratuito 1.3.36 non installato"
wp plugin install wp-content/prove/pro-1.3.23.zip --activate >/dev/null 2>&1 || ko "A: Pro 1.3.23 non installato"
wp eval 'echo "ok";' >/dev/null 2>&1
[ -z "$(val 'asem_pro_problem()')" ] || ko "A: il Pro 1.3.23 doveva funzionare sul gratuito 1.3.36"
site_ok "A prima"
wp plugin install wp-content/prove/pro-pub.zip --force 2>&1 | grep -i "fatal" && ko "A: errore fatale aggiornando il Pro"
echo "A dopo il Pro: $(wp plugin list --fields=name,version,status 2>/dev/null | tr '\n' ' ')"
[ -z "$(val 'asem_pro_problem()')" ] || ko "A: dopo l'aggiornamento del Pro l'avviso non doveva esserci"
site_ok "A dopo il Pro"
wp plugin install wp-content/prove/free-pub.zip --force 2>&1 | grep -i "fatal" && ko "A: errore fatale aggiornando il gratuito"
wp eval 'echo "ok";' >/dev/null 2>&1
[ -z "$(val 'asem_pro_problem()')" ] || ko "A: dopo il gratuito l'avviso non doveva esserci"
idx="$(val 'count( AssociazioneSemplice\Install::ensure_indexes() )')"
echo "A: indici ancora da creare: $idx"; [ "$idx" = "0" ] || ko "A: indici mancanti ($idx)"
site_ok "A dopo il gratuito"

reset_site
echo "### B. gratuito 1.3.35 + Pro 1.3.23 → Pro pubblicato da solo, poi gratuito pubblicato"
wp plugin install wp-content/prove/free-1.3.35.zip --activate >/dev/null 2>&1 || ko "B: gratuito 1.3.35 non installato"
wp plugin install wp-content/prove/pro-1.3.23.zip --activate >/dev/null 2>&1 || ko "B: Pro 1.3.23 non installato"
wp eval 'echo "ok";' >/dev/null 2>&1
site_ok "B prima"
wp plugin install wp-content/prove/pro-pub.zip --force 2>&1 | grep -i "fatal" && ko "B: errore fatale aggiornando il Pro da solo"
case "$(val 'asem_pro_problem()')" in *"ha bisogno di"*) echo "ok: il Pro si sospende con un avviso";; *) ko "B: nessun avviso con il gratuito 1.3.35";; esac
site_ok "B dopo il Pro"
[ "$(code http://localhost:8888/wp-admin/)" = "302" ] || [ "$(code http://localhost:8888/wp-admin/)" = "200" ] || ko "B: wp-admin non raggiungibile"
wp plugin install wp-content/prove/free-pub.zip --force 2>&1 | grep -i "fatal" && ko "B: errore fatale aggiornando il gratuito"
wp eval 'echo "ok";' >/dev/null 2>&1
[ -z "$(val 'asem_pro_problem()')" ] || ko "B: dopo il gratuito il Pro è ancora sospeso"
idx="$(val 'count( AssociazioneSemplice\Install::ensure_indexes() )')"
echo "B: indici ancora da creare: $idx"; [ "$idx" = "0" ] || ko "B: indici mancanti ($idx)"
site_ok "B dopo il gratuito"

reset_site
echo "### C. gratuito 1.3.25 + Pro pubblicato: sospeso con avviso, sito 200"
wp plugin install wp-content/prove/free-1.3.25.zip --activate >/dev/null 2>&1 || ko "C: gratuito 1.3.25 non installato"
wp plugin install wp-content/prove/pro-pub.zip --activate 2>&1 | grep -i "fatal" && ko "C: errore fatale attivando il Pro"
case "$(val 'asem_pro_problem()')" in *"ha bisogno di"*) echo "ok: il Pro si sospende con un avviso";; *) ko "C: nessun avviso con il gratuito 1.3.25";; esac
site_ok "C"
exit $fail
