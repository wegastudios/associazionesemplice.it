#!/bin/sh
# Associazione Semplice - accesso SSH di SOLA LETTURA ai dump dei backup.
#
# Non si lancia a mano. Si mette come comando forzato (command="...") davanti alla chiave pubblica DEDICATA nel file
# ~/.ssh/authorized_keys del server (vedi saas/docs/COPIA_ONEDRIVE.md). Chi si collega con quella chiave non ottiene
# una shell: puo' fare solo due cose, entrambe di lettura:
#
#   list              elenco dei dump e dei .json (percorso relativo, tabulazione, dimensione in byte)
#   get <percorso>    il contenuto di UN file .dump.enc o .dump.enc.json, e solo quelli
#
# Non scrive, non cancella, non rinomina, non esegue altro. Ogni altro comando e' rifiutato. Il percorso e' controllato con
# un'espressione rigida (niente "..", niente barre iniziali, niente a capo) e poi si verifica che il file reale stia
# dentro la cartella dei backup e non sia un collegamento simbolico.
#
# Perche' non rrsync / scp / sftp: dal PC Windows non c'e' rsync, e rrsync non limita scp/sftp in modo semplice;
# su cPanel condiviso internal-sftp con ChrootDirectory non e' disponibile. Questo script e' corto e si legge in un minuto.
#
# Installazione sul server (SOLO dopo il si' del titolare, vedi la guida): copiare il file in ~/saas/backup-readonly.sh
# (fuori da releases/, cosi' i rilasci non lo toccano) con permessi 700.

set -u
umask 077

BASE="${HOME}/saas/shared/storage/app/backups"
PREFIX="production"
ROOT="${BASE}/${PREFIX}"

deny() {
    echo "backup-readonly: comando non ammesso" >&2
    exit 126
}

cmd="${SSH_ORIGINAL_COMMAND:-}"

# Niente a capo dentro il comando: cambierebbero il significato dei controlli riga per riga.
case "$cmd" in
    *"
"*) deny ;;
esac

case "$cmd" in
    list)
        [ -d "$ROOT" ] || exit 1
        cd "$ROOT" || exit 1
        # find non segue i collegamenti simbolici; solo file normali con i due nomi ammessi.
        exec find . -type f \( -name '*.dump.enc' -o -name '*.dump.enc.json' \) -printf '%P\t%s\n'
        ;;
    "get "*)
        rel="${cmd#get }"
        # <database>/<anno>/<mese>/<database>-<AAAAMMGG>T<HHMMSS>Z.dump.enc[.json]
        if ! printf '%s\n' "$rel" | grep -Eq '^[A-Za-z0-9_-]{1,64}/[0-9]{4}/[0-9]{2}/[A-Za-z0-9_-]{1,64}-[0-9]{8}T[0-9]{6}Z\.dump\.enc(\.json)?$'; then
            deny
        fi
        file="${ROOT}/${rel}"
        [ -f "$file" ] || exit 1
        [ ! -L "$file" ] || deny
        real="$(readlink -f -- "$file")" || deny
        root_real="$(readlink -f -- "$ROOT")" || deny
        case "$real" in
            "${root_real}/"*) ;;
            *) deny ;;
        esac
        exec cat -- "$real"
        ;;
    *)
        deny
        ;;
esac
