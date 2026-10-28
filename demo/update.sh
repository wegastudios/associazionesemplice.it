#!/usr/bin/env bash
# Porta la demo (associazionesemplice.it/demo) alle ultime versioni dei due plugin pubblicate sul portale.
# Si lancia da solo a ogni pubblicazione (portale/release.sh) e a ogni azzeramento notturno. Prima il gratuito, poi il Pro (stesso livello di compatibilità).
set -euo pipefail
cd "$HOME/public_html/demo"
R="$HOME/portale/data/releases"
latest() { ls "$R/$1"/*.zip 2>/dev/null | sed 's|.*/||; s|\.zip$||' | sort -V | tail -1; }
for p in free:associazionesemplice pro:associazionesemplice-pro; do
	prod="${p%%:*}"; slug="${p##*:}"; v="$(latest "$prod")"
	[ -n "$v" ] || { echo "Nessuna versione pubblicata per $prod"; continue; }
	cur="$(wp plugin get "$slug" --field=version 2>/dev/null || true)"
	if [ "$cur" != "$v" ]; then
		wp plugin install "$R/$prod/$v.zip" --force --activate >/dev/null
		echo "$slug: ${cur:-nuovo} -> $v"
	fi
done
echo "Demo aggiornata: gratuito $(wp plugin get associazionesemplice --field=version), Pro $(wp plugin get associazionesemplice-pro --field=version)"
