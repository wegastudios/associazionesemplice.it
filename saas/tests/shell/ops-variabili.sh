#!/usr/bin/env bash
# Prova di saas/deploy/ops.sh: lettura del .env (commenti in riga), var_state (solo stato, mai il valore dei segreti)
# e azioni «variabili» / «interruttori» (lo stato va su file 600 in shared/, nel log solo «salvato in»). Nessun server, nessun PHP vero.
set -euo pipefail
OPS="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../deploy" && pwd)/ops.sh"
T="$(mktemp -d)"; trap 'rm -rf "$T"' EXIT
ENVFILE="$T/.env"
cat > "$ENVFILE" <<'EOF'
APP_DEBUG=false
SAAS_PURGE_ENABLED=1
SAAS_KEY_VAULT=remote
MAIL_HOST=smtp.segreto.example
SAAS_MASTER_KEY=base64:SEGRETISSIMO
REDIS_PASSWORD=
SAAS_STAFF_SSO=strano
SAAS_BACKUP_SFTP_HOST=   # host del modello, da compilare
SAAS_BACKUP_SFTP_USERNAME=# solo commento
SAAS_BACKUP_SFTP_FINGERPRINT="# anche tra virgolette"  # commento dopo
MAIL_USERNAME=utente.vero   # commento dopo il valore
MAIL_PASSWORD="pa ss#word"
SAAS_BACKUP_SFTP_KEY_PATH=/home/x/chiave#1
SAAS_DEMO='true' # commento
APP_ENV=production # local o production
EOF
# Carica solo le funzioni di lettura e di stato (dai marcatori), senza eseguire le azioni.
eval "$(awk '/lettura del .env: inizio/{p=1} p{print} /lettura del .env: fine/{exit}' "$OPS")"
eval "$(awk '/stato delle variabili: inizio/{p=1} p{print} /stato delle variabili: fine/{exit}' "$OPS")"

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

# Commenti in riga del .env
ok "commento in riga dopo spazi -> vuota"   "$(var_state segreta SAAS_BACKUP_SFTP_HOST "$(get SAAS_BACKUP_SFTP_HOST)")" "SAAS_BACKUP_SFTP_HOST = vuota"
ok "valore che inizia con # -> vuota"        "$(var_state segreta SAAS_BACKUP_SFTP_USERNAME "$(get SAAS_BACKUP_SFTP_USERNAME)")" "SAAS_BACKUP_SFTP_USERNAME = vuota"
ok "get: valore prima del commento"          "$(get MAIL_USERNAME)" "utente.vero"
ok "valore dopo commento: impostata"         "$(var_state segreta MAIL_USERNAME "$(get MAIL_USERNAME)")" "MAIL_USERNAME = impostata"
ok "tra virgolette: # e spazi restano"       "$(get MAIL_PASSWORD)" "pa ss#word"
ok "# senza spazio davanti: fa parte del valore" "$(get SAAS_BACKUP_SFTP_KEY_PATH)" "/home/x/chiave#1"
ok "virgolette singole + commento"           "$(get SAAS_DEMO)" "true"
ok "bool con commento"                       "$(var_state bool SAAS_DEMO "$(get SAAS_DEMO)")" "SAAS_DEMO = acceso/true"
ok "scelta con commento in riga"             "$(var_state scelta APP_ENV "$(get APP_ENV)")" "APP_ENV = production"

# Azioni complete con un php finto: il log deve avere solo «salvato in», lo stato va nel file 600.
B="$T/base"; mkdir -p "$B/shared" "$B/current"; cp "$ENVFILE" "$B/shared/.env"
FAKE="$T/php"
cat > "$FAKE" <<'EOF'
#!/usr/bin/env bash
if [ "${1:-}" = "-r" ]; then exit 1; fi
echo "saas.crypto.vault = remote"; echo "mail.default = smtp"; echo "filesystems.disks.backups.host = vuota"
EOF
chmod +x "$FAKE"
for act in variabili interruttori; do
  log="$(SAAS_BASE="$B" SAAS_PHP="$FAKE" bash "$OPS" "$act" 2>&1)"
  ok "$act: il log ha una sola riga" "$(printf '%s\n' "$log" | wc -l | tr -d ' ')" "1"
  case "$log" in "$act: salvato in $B/shared/$act-"*.txt) echo "ok   $act: il log dice solo «salvato in»" ;; *) echo "FAIL $act: log inatteso: $log"; fail=1 ;; esac
  case "$log" in *=*|*acceso*|*spento*|*impostat*|*vuota*|*SEGRET*|*segreto*) echo "FAIL $act: stato nel log"; fail=1 ;; *) echo "ok   $act: nessuno stato nel log" ;; esac
  f="$(ls -1 "$B"/shared/"$act"-*.txt | head -1)"
  [ -s "$f" ] && echo "ok   $act: file scritto" || { echo "FAIL $act: file vuoto o assente"; fail=1; }
  if grep -q 'SEGRETISSIMO\|segreto.example\|utente.vero\|pa ss#word' "$f"; then echo "FAIL $act: segreto nel file"; fail=1; else echo "ok   $act: nessun segreto nel file"; fi
done
f="$(ls -1 "$B"/shared/variabili-*.txt | head -1)"
grep -qx 'SAAS_BACKUP_SFTP_HOST = vuota' "$f" && echo "ok   variabili: commento -> vuota nel file" || { echo "FAIL variabili: SFTP host"; fail=1; }
grep -qx 'saas.crypto.vault = remote' "$f" && echo "ok   variabili: scelta effettiva nel file" || { echo "FAIL variabili: scelta effettiva"; fail=1; }
touch "$T/p"; chmod 600 "$T/p"
if [ "$(stat -c %a "$T/p" 2>/dev/null || echo x)" = "600" ]; then ok "file 600" "$(stat -c %a "$f")" "600"; else echo "skip permessi 600 (file system senza permessi)"; fi
# Potatura: al massimo 5 file per azione
for i in 1 2 3 4 5 6 7; do : > "$B/shared/variabili-2026010${i}T000000.txt"; done
SAAS_BASE="$B" SAAS_PHP="$FAKE" bash "$OPS" variabili >/dev/null 2>&1
ok "potatura: al massimo 5 file" "$(ls -1 "$B"/shared/variabili-*.txt | wc -l | tr -d ' ')" "5"
exit "$fail"
