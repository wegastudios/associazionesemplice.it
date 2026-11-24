#!/bin/sh
# Finto server per Test-ScaricaBackup.ps1. Modi (FAKE_MODE): real (default), dead, evil.
cmd=""
for a in "$@"; do cmd="$a"; done
printf '%s\n' "$cmd" >> "$FAKE_LOG"
here="$(dirname "$0")"
case "${FAKE_MODE:-real}" in
    dead)
        echo "ssh: connect to host: Connection timed out" >&2
        exit 255
        ;;
    evil)
        case "$cmd" in
            list)
                printf '%s\t10\n' \
                    '../evil/2026/11/evil-20261124T010101Z.dump.enc' \
                    '/etc/passwd' \
                    'CON/2026/11/CON-20261124T010101Z.dump.enc' \
                    'CON/2026/11/CON-20261124T010101Z.dump.enc.json' \
                    'nul/2026/11/nul-20261124T010101Z.dump.enc' \
                    'COM1/2026/11/COM1-20261124T010101Z.dump.enc' \
                    'lpt9/2026/11/lpt9-20261124T010101Z.dump.enc' \
                    'shared/2026/11/../../../x-20261124T010101Z.dump.enc' \
                    'shared\2026\11\shared-20261124T010101Z.dump.enc' \
                    'C:/Windows/2026/11/C-20261124T010101Z.dump.enc'
                exit 0
                ;;
            *) exit 1 ;;
        esac
        ;;
    *)
        HOME="$FAKE_HOME" SSH_ORIGINAL_COMMAND="$cmd" exec sh "$here/../backup-readonly.sh"
        ;;
esac
