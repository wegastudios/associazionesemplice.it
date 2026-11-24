#!/usr/bin/env bash
# Prova var_state di saas/deploy/ops.sh (azione «variabili»): solo stato, mai il valore dei segreti. Nessun server, nessun PHP.
set -euo pipefail
OPS="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../deploy" && pwd)/ops.sh"
T="$(mktemp -d)"; trap 'rm -rf "$T"' EXIT
ENVFILE="$T/.env"
printf 'APP_DEBUG=false\nSAAS_PURGE_ENABLED=1\nSAAS_KEY_VAULT=remote\nMAIL_HOST=smtp.segreto.example\nSAAS_MASTER_KEY=base64:SEGRETISSIMO\nREDIS_PASSWORD=\nSAAS_STAFF_SSO=strano\n' > "$ENVFILE"
eval "$(awk '/stato delle variabili: inizio/{p=1} p{print} /stato delle variabili: fine/{exit}' "$OPS")"
get() { grep -E "^$1=" "$ENVFILE" | tail -1 | cut -d= -f2- || true; }

fail=0
ok() { if [ "$2" = "$3" ]; then echo "ok   $1"; else echo "FAIL $1: atteso '$3', trovato '$2'"; fail=1; fi; }
ok "false = spento"        "$(var_state bool APP_DEBUG "$(get APP_DEBUG)")" "APP_DEBUG = spento/false"
ok "1 = acceso"            "$(var_state bool SAAS_PURGE_ENABLED "$(get SAAS_PURGE_ENABLED)")" "SAAS_PURGE_ENABLED = acceso/1"
ok "assente = predefinito" "$(var_state bool SAAS_UNIFIED_OPERATORS "$(get SAAS_UNIFIED_OPERATORS)")" "SAAS_UNIFIED_OPERATORS = non impostata: vale il predefinito"
ok "scelta nota"           "$(var_state scelta SAAS_KEY_VAULT "$(get SAAS_KEY_VAULT)")" "SAAS_KEY_VAULT = remote"
ok "host: solo impostata"  "$(var_state segreta MAIL_HOST "$(get MAIL_HOST)")" "MAIL_HOST = impostata"
ok "chiave: solo impostata" "$(var_state segreta SAAS_MASTER_KEY "$(get SAAS_MASTER_KEY)")" "SAAS_MASTER_KEY = impostata"
ok "riga vuota"            "$(var_state segreta REDIS_PASSWORD "$(get REDIS_PASSWORD)")" "REDIS_PASSWORD = vuota"
ok "bool strano: non mostrato" "$(var_state bool SAAS_STAFF_SSO "$(get SAAS_STAFF_SSO)")" "SAAS_STAFF_SSO = impostata"
out="$(var_state segreta MAIL_HOST "$(get MAIL_HOST)"; var_state segreta SAAS_MASTER_KEY "$(get SAAS_MASTER_KEY)")"
case "$out" in *segreto*|*SEGRETISSIMO*) echo "FAIL: valore stampato"; fail=1 ;; *) echo "ok   nessun valore segreto in uscita" ;; esac
exit "$fail"
