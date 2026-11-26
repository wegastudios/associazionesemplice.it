#!/usr/bin/env bash
# Prova del blocco «permessi del gruppo» di saas/deploy/remote.sh: il blocco si ESEGUE davvero (bash -euo pipefail, come il
# rilascio) con un php finto e una cartella temporanea. Il blocco è estratto dalla riga «GROUP_FILE=» alla riga della pulizia
# («tail -n +6»): se remote.sh cambia forma e l'estrazione non trova nulla, la prova fallisce.
# Casi: interruttore spento senza file; acceso e riuscito (file 600); acceso e fallito (il rilascio non si ferma);
# più di 5 file vecchi (ne restano al massimo 5).
set -euo pipefail
REMOTE="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../deploy" && pwd)/remote.sh"
T="$(mktemp -d)"; trap 'rm -rf "$T"' EXIT

BLOCK="$T/blocco.sh"
{
    echo 'set -euo pipefail'
    awk '/^GROUP_FILE=/{p=1} p{print} p && /tail -n \+6/{exit}' "$REMOTE"
} > "$BLOCK"
if ! grep -q 'saas:db:revoke-group' "$BLOCK" || ! grep -q 'tail -n +6' "$BLOCK"; then
    echo "FAIL: blocco della revoca non trovato in remote.sh"; exit 1
fi

# php finto: «artisan saas:db:revoke-group --if-enabled» si comporta secondo FAKE_MODE.
FAKE="$T/php"
cat > "$FAKE" <<'PHP'
#!/usr/bin/env bash
case "${FAKE_MODE:-}" in
    spento) exit 0 ;;
    riuscito) echo "ruolo di gruppo: permessi riportati"; exit 0 ;;
    fallito) echo "errore finto" >&2; exit 1 ;;
esac
exit 99
PHP
chmod +x "$FAKE"

fail=0
ok() { if [ "$2" = "$3" ]; then echo "ok   $1"; else echo "FAIL $1: atteso '$3', trovato '$2'"; fail=1; fi; }

# Esegue il blocco in una base nuova con $3 file vecchi; imposta RC, OUT, ERR, B.
run_block() {
    local mode="$1" old="${3:-0}" i
    B="$T/base-$2"; rm -rf "$B"; mkdir -p "$B/shared"
    for ((i = 1; i <= old; i++)); do
        : > "$B/shared/revoca-gruppo-2020010${i}T000000.txt"
        touch -d "2020-01-0${i} 00:00:00" "$B/shared/revoca-gruppo-2020010${i}T000000.txt"
    done
    RC=0
    OUT="$(BASE="$B" PHP="$FAKE" FAKE_MODE="$mode" bash -euo pipefail "$BLOCK" 2>"$T/err")" || RC=$?
    ERR="$(cat "$T/err")"
}
count() { find "$B/shared" -name 'revoca-gruppo-*.txt' | wc -l | tr -d ' '; }

# (a) interruttore spento, nessun file preesistente: il blocco non ferma il rilascio e non lascia file.
run_block spento a
ok "spento: exit 0 del blocco" "$RC" "0"
ok "spento: nessun file lasciato" "$(count)" "0"
ok "spento: nessun messaggio" "$OUT$ERR" ""

# (b) acceso e riuscito: file creato (600 su Linux) e messaggio «fatto».
run_block riuscito b
ok "riuscito: exit 0 del blocco" "$RC" "0"
ok "riuscito: un file creato" "$(count)" "1"
ok "riuscito: messaggio" "$OUT" "permessi del gruppo: fatto"
F="$(find "$B/shared" -name 'revoca-gruppo-*.txt' | head -n 1)"
ok "riuscito: dettaglio nel file" "$(cat "$F")" "ruolo di gruppo: permessi riportati"
if [ "$(uname -s)" = "Linux" ]; then
    ok "riuscito: permessi 600" "$(stat -c %a "$F")" "600"
fi

# (c) acceso e fallito: il blocco non abortisce, avvisa su stderr, il file resta.
run_block fallito c
ok "fallito: exit 0 del blocco" "$RC" "0"
ok "fallito: messaggio su stderr" "$ERR" "permessi del gruppo: da rivedere, vedi il controllo di salute"
ok "fallito: niente su stdout" "$OUT" ""
ok "fallito: file conservato" "$(count)" "1"
F="$(find "$B/shared" -name 'revoca-gruppo-*.txt' | head -n 1)"
ok "fallito: dettaglio nel file" "$(cat "$F")" "errore finto"

# (d) più di 5 file vecchi: ne restano al massimo 5, sia con un nuovo file (acceso) sia senza (spento).
run_block riuscito d 8
ok "vecchi, acceso: exit 0 del blocco" "$RC" "0"
ok "vecchi, acceso: al massimo 5 file" "$(count)" "5"
run_block spento e 8
ok "vecchi, spento: exit 0 del blocco" "$RC" "0"
ok "vecchi, spento: al massimo 5 file" "$(count)" "5"

exit "$fail"
