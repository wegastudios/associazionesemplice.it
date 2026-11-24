#!/usr/bin/env bash
# Il Pro costruito da questo ramo su un gratuito più vecchio: non deve dare errori fatali, deve sospendersi con un avviso e riprendere quando il gratuito
# viene aggiornato. Mostra anche cosa succedeva con il Pro 1.3.23 (informativo: non fa fallire la prova).
# Uso, dalla radice del repository:  bash wordpress/associazionesemplice/tests/ci/prova-pro-su-free-vecchio.sh VERSIONE-GRATUITO COMMIT-GRATUITO COMMIT-PRO-VECCHIO
# (serve un clone completo: i due commit vengono estratti in cartelle a parte e costruiti con il loro build.sh)
set -uo pipefail
ver="$1"; free_commit="$2"; oldpro_commit="$3"
root="$PWD"
work="${RUNNER_TEMP:-/tmp}/pro-su-vecchio-$ver"
mkdir -p "$work"
fail=0
ko() { echo "::error::$*"; fail=1; }

# 1. pacchetti: quello da provare (questo ramo), il gratuito vecchio, il Pro vecchio
bash wordpress/build.sh "$work/nuovo" >/dev/null
for pair in "free:$free_commit" "pro:$oldpro_commit"; do
  kind="${pair%%:*}"; c="${pair#*:}"
  git worktree add --detach "$work/src-$kind" "$c" >/dev/null 2>&1 || { ko "commit $c non trovato"; exit 1; }
  (cd "$work/src-$kind" && bash wordpress/build.sh "$work/vecchio-$kind" >/dev/null) || { ko "build di $c non riuscita"; exit 1; }
done
cd "$work"
mkdir -p prove
cp vecchio-free/associazionesemplice.zip prove/vecchio-free.zip
cp vecchio-pro/associazionesemplice-pro.zip prove/vecchio-pro.zip
cp nuovo/associazionesemplice.zip prove/nuovo-free.zip
cp nuovo/associazionesemplice-pro.zip prove/nuovo-pro.zip
cat > .wp-env.json <<'JSON'
{ "phpVersion": "8.1", "plugins": [], "mappings": { "wp-content/prove": "./prove" }, "config": { "WP_DEBUG": true, "WP_DEBUG_DISPLAY": false, "WP_DEBUG_LOG": true } }
JSON
wp() { npx --yes @wordpress/env run cli wp "$@"; }
code() { curl -s -o /dev/null -w '%{http_code}' "$1"; }
npx --yes @wordpress/env start >/dev/null 2>&1 || { ko "wp-env non parte"; exit 1; }

echo "### Gratuito $ver + Pro costruito da questo ramo"
wp plugin install wp-content/prove/vecchio-free.zip --activate >/dev/null 2>&1 || ko "gratuito $ver non installato"
wp plugin install wp-content/prove/nuovo-pro.zip --activate 2>&1 | grep -i "fatal" && ko "il Pro nuovo dà un errore fatale sul gratuito $ver"
wp plugin list --fields=name,status,version
p="$(wp eval 'echo asem_pro_problem();' 2>&1)"
echo "Avviso del Pro: $p"
case "$p" in *"ha bisogno di"*) echo "ok: il Pro si sospende con un avviso";; *) ko "nessun avviso con il gratuito $ver";; esac
[ "$(code http://localhost:8888/)" = "200" ] || ko "il sito pubblico non risponde 200 con il gratuito $ver e il Pro nuovo"
[ "$(code http://localhost:8888/wp-login.php)" = "200" ] || ko "wp-login non risponde 200"

echo "### Il gratuito si aggiorna alla build corrente: il Pro riprende e gli indici ci sono"
wp plugin install wp-content/prove/nuovo-free.zip --force >/dev/null 2>&1 || ko "aggiornamento del gratuito non riuscito"
p="$(wp eval 'echo asem_pro_problem();' 2>&1)"
[ -z "$p" ] || ko "dopo l'aggiornamento del gratuito il Pro è ancora sospeso: $p"
wp eval 'echo "ok";' >/dev/null 2>&1 # il primo avvio con il gratuito nuovo esegue l'aggiornamento dello schema (da riga di comando è permesso)
idx="$(wp eval 'echo count( AssociazioneSemplice\Install::ensure_indexes() );' 2>&1 | tail -1)"
echo "Indici ancora da creare dopo l'aggiornamento: $idx"
[ "$idx" = "0" ] || ko "indici mancanti dopo l'aggiornamento del gratuito ($idx)"
[ "$(code http://localhost:8888/)" = "200" ] || ko "il sito pubblico non risponde 200 dopo l'aggiornamento"

echo "### Informativo: il Pro 1.3.23 (già pubblicato) sul gratuito $ver, già attivo"
wp plugin install wp-content/prove/nuovo-free.zip --force >/dev/null 2>&1
wp plugin install wp-content/prove/vecchio-free.zip --force >/dev/null 2>&1
wp plugin install wp-content/prove/vecchio-pro.zip --force --activate 2>&1 | grep -i "fatal" | head -2
echo "Sito pubblico: $(code http://localhost:8888/)  wp-login: $(code http://localhost:8888/wp-login.php)  wp-admin: $(code http://localhost:8888/wp-admin/)"
wp plugin list --fields=name,status,version
wp option get recovery_mode_email_last_sent >/dev/null 2>&1 && echo "recovery mode: email inviata"
echo "### Come se ne esce: il Pro nuovo sopra il vecchio"
wp plugin install wp-content/prove/nuovo-pro.zip --force --activate 2>&1 | grep -i "fatal" | head -2
echo "Sito pubblico: $(code http://localhost:8888/)"
wp plugin list --fields=name,status,version
exit $fail
