#!/usr/bin/env bash
# Rimette nella demo l'ultima copia del database salvata prima di un azzeramento (o quella indicata: bash restore-last.sh ~/demo-backups/demo-AAAAMMGG-HHMMSS.sql).
set -euo pipefail
cd "$HOME/public_html/demo"
f="${1:-$(ls -1t "$HOME"/demo-backups/demo-*.sql | head -1)}"
[ -f "$f" ] || { echo "Nessuna copia trovata." >&2; exit 1; }
wp db import "$f"
wp cache flush >/dev/null 2>&1 || true
echo "Demo ripristinata da $f"
