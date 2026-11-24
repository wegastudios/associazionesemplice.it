#!/usr/bin/env bash
# Prova la rotazione delle copie .env.bak-* di saas/deploy/ops.sh su una cartella temporanea (nessun server, nessun PHP).
set -euo pipefail
OPS="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../deploy" && pwd)/ops.sh"
T="$(mktemp -d)"; trap 'rm -rf "$T"' EXIT
BASE="$T"; mkdir -p "$BASE/shared"; ENVFILE="$BASE/shared/.env"
# Carica solo rotazione + set_var (dal marcatore di inizio alla chiusura di set_var), senza eseguire le azioni.
eval "$(awk '/rotazione delle copie del .env: inizio/{p=1} p{print} p&&/^set_var\(\)/{s=1} s&&/^}/{exit}' "$OPS")"

fail=0
ok() { if [ "$2" = "$3" ]; then echo "ok   $1"; else echo "FAIL $1: atteso '$3', trovato '$2'"; fail=1; fi; }
ago() { date -u -d "$1 days ago" +%Y%m%d%H%M%S; }
reset() { rm -rf "$BASE/shared"; mkdir -p "$BASE/shared"; printf 'A=1\nB=2\n' > "$ENVFILE"; chmod 600 "$ENVFILE"; }
mk() { printf 'vecchio\n' > "$ENVFILE.bak-$1"; chmod 600 "$ENVFILE.bak-$1"; }
count() { ls -A "$BASE"/shared | grep -cE '^\.env\.bak-[0-9]{14}$' || true; }
names() { ( cd "$BASE/shared" && ls -A | sort | tr '\n' ' ' ); }

# 1) senza interruttore set_var non cancella nulla (anche con tante copie vecchie)
reset; for d in 30 20 15 6 5 4; do mk "$(ago $d)"; done
sleep 1; set_var A 9
ok "senza interruttore: 6 copie + 1 nuova" "$(count)" "7"

# 2) list è sola lettura e indica 5 da cancellare (restano le 2 più recenti)
ok "list: 5 da cancellare, nessuna cancellata" "$(env_bak_prune list | wc -l | tr -d ' ') $(count)" "5 7"

# 3) con interruttore: massimo 2 copie, la nuova resta, il .env non si tocca
: > "$ROTATION_FLAG"
sleep 1; set_var A 10
ok "con interruttore: 2 copie" "$(count)" "2"
ok ".env attivo aggiornato" "$(grep -c '^A=10$' "$ENVFILE")" "1"
PERM=1; [ "$(stat -c %a "$BASE/shared")" != "" ] && printf x > "$T/p" && chmod 600 "$T/p" && [ "$(stat -c %a "$T/p")" = 600 ] || PERM=0  # filesystem senza permessi (Windows): si saltano
if [ "$PERM" = 1 ]; then ok "permessi .env" "$(stat -c %a "$ENVFILE")" "600"; fi
if [ "$PERM" = 1 ]; then for f in "$BASE"/shared/.env.bak-*; do ok "permessi $(basename "$f")" "$(stat -c %a "$f")" "600"; done; fi

# 4) oltre 7 giorni: vanno via anche se sono solo 2
reset; mk "$(ago 9)"; mk "$(ago 8)"; : > "$ROTATION_FLAG"
set_var A 3
ok "2 copie vecchie di 7+ giorni: resta la sola nuova" "$(count)" "1"

# 5) copia recente (3 giorni) + nuova: restano entrambe
reset; mk "$(ago 3)"; : > "$ROTATION_FLAG"
set_var A 4
ok "copia di 3 giorni resta" "$(count)" "2"

# 6) altri file mai toccati (nomi simili inclusi)
reset; mk "$(ago 30)"; mk "$(ago 29)"; mk "$(ago 28)"; : > "$ROTATION_FLAG"
printf 'x\n' > "$BASE/.env.prima-portal-login"; printf 'x\n' > "$BASE/shared/.env.bak-manuale"; printf 'x\n' > "$BASE/shared/.env.bak-20200101"
printf 'x\n' > "$BASE/shared/.env.bak-20200101000000.txt"; printf 'x\n' > "$BASE/shared/.env.altro"; printf 'x\n' > "$BASE/shared/proxy-secret.txt"
printf 'x\n' > "$BASE/shared/permessi-db-20200101T000000.txt"
env_bak_prune delete >/dev/null
ok "altri file intatti, copie vere tutte cancellate (vecchie)" "$(names)" ".env .env-rotazione-copie .env.altro .env.bak-20200101 .env.bak-20200101000000.txt .env.bak-manuale permessi-db-20200101T000000.txt proxy-secret.txt "
ok "file fuori da shared intatto" "$(ls -A "$BASE" | grep -c prima-portal-login)" "1"

# 7) nessuna copia: nessun errore (set -e attivo)
reset; ok "nessuna copia" "$(env_bak_prune list | wc -l | tr -d ' ')" "0"

exit $fail
