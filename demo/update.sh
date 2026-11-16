#!/usr/bin/env bash
# Porta la demo (associazionesemplice.it/demo) alle ultime versioni dei due plugin pubblicate sul portale.
# Si lancia da solo a ogni pubblicazione (portale/release.sh) e a ogni azzeramento notturno. Prima il gratuito, poi il Pro (stesso livello di compatibilità).
set -euo pipefail
export PATH="/usr/local/bin:/usr/bin:/bin:$PATH" # il cron ha un PATH ridotto: senza questo non trova wp (e php sarebbe quello CGI)
# Non si aggiorna mentre la demo viene azzerata (e viceversa): se un azzeramento è in corso si aspetta, fino a 10 minuti. Lanciato da reset.sh non aspetta (il blocco lo ha già reset.sh).
if [ -z "${ASEM_DEMO_LOCKED:-}" ]; then
	exec 8>"$HOME/demo-provision/.demo.lock"
	flock -w "${ASEM_LOCK_WAIT:-600}" 8 || { echo "Un azzeramento della demo è ancora in corso: non aggiorno ora (riprova fra qualche minuto)." >&2; exit 5; }
fi
cd "$HOME/public_html/demo"
R="$HOME/portale/data/releases"
latest() { ls "$R/$1"/*.zip 2>/dev/null | sed 's|.*/||; s|\.zip$||' | sort -V | tail -1; }
for p in free:associazionesemplice pro:associazionesemplice-pro; do
	prod="${p%%:*}"; slug="${p##*:}"; v="$(latest "$prod")"
	[ -n "$v" ] || { echo "Nessuna versione pubblicata per $prod"; continue; }
	cur="$(wp plugin get "$slug" --field=version 2>/dev/null || true)"
	# Si installa solo se manca o se la pubblicata è PIÙ NUOVA: una build di main non ancora pubblicata (numero più alto) non va mai ritirata.
	if [ -z "$cur" ] || { [ "$cur" != "$v" ] && [ "$(printf '%s\n%s\n' "$cur" "$v" | sort -V | tail -1)" = "$v" ]; }; then
		wp plugin install "$R/$prod/$v.zip" --force --activate >/dev/null
		echo "$slug: ${cur:-nuovo} -> $v"
	fi
done
echo "Demo aggiornata: gratuito $(wp plugin get associazionesemplice --field=version), Pro $(wp plugin get associazionesemplice-pro --field=version)"
