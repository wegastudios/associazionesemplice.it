#!/usr/bin/env bash
# Ogni azione asem_front_* del plugin deve essere CLASSIFICATA in demo/asem-demo.php: permessa (elenco $asem_demo_allowed) o vietata (elenco $asem_demo_blocked).
# Un'azione nuova non classificata si fermerebbe in demo con «Nella demo questa azione non è disponibile»: questa prova fallisce prima che arrivi in demo.
set -euo pipefail
cd "$(dirname "$0")/.."
DEMO=demo/asem-demo.php
# Nomi che compaiono tra apici ma non sono azioni di admin-post.php (filtri del plugin).
NON_AZIONI='asem_front_links'

azioni=$(grep -rhoE "'asem_front_[a-z_]+'" wordpress --include=*.php | tr -d "'" | sort -u | grep -vxE "$NON_AZIONI" || true)
classificate=$(sed -n '/^\$asem_demo_blocked = array(/,/^) );/p' "$DEMO" | grep -oE "'asem_front_[a-z_]+'" | tr -d "'" | sort -u)
[ -n "$azioni" ] || { echo "Nessuna azione asem_front_* trovata in wordpress/: il percorso o il formato è cambiato, la prova non è valida." >&2; exit 2; }
[ -n "$classificate" ] || { echo "Nessuna azione classificata trovata in $DEMO: la struttura del file è cambiata, la prova non è valida." >&2; exit 2; }

mancanti=$(comm -23 <(echo "$azioni") <(echo "$classificate"))
if [ -n "$mancanti" ]; then
	echo "Azioni del plugin NON classificate in $DEMO (aggiungile a \$asem_demo_allowed se sono sicure per i visitatori, a \$asem_demo_blocked se no):" >&2
	echo "$mancanti" | sed 's/^/  - /' >&2
	exit 1
fi
echo "Azioni asem_front_* del plugin: $(echo "$azioni" | wc -l | tr -d ' '), tutte classificate."
