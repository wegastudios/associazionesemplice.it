#!/usr/bin/env bash
# Gira sul server (sub-account cPanel), lanciato dal rilascio via SSH. Uso: remote.sh <pacchetto.tar.gz> <sha256>
#
#   ~/saas/releases/<data>-<revisione>/   una cartella per versione (si tengono le ultime 5)
#   ~/saas/shared/.env                    configurazione (fuori dal repository, permessi 600)
#   ~/saas/shared/storage/                log, cache, file cifrati delle associazioni
#   ~/saas/current -> releases/…          versione in uso; la cartella pubblica del sottodominio è ~/saas/current/public
#
# Ordine: controlli, migrazioni (in due tempi: la versione in uso continua a funzionare), cache, cambio del link,
# controllo di salute, ritorno automatico alla versione precedente se il controllo fallisce.
set -euo pipefail
PACKAGE="${1:?pacchetto}"; EXPECTED="${2:?sha256}"
BASE="${SAAS_BASE:-$HOME/saas}"
PHP="${SAAS_PHP:-/usr/local/bin/php}"   # da cron e da SSH: php da solo è la versione CGI
KEEP=5

[ "$(sha256sum "$PACKAGE" | cut -d' ' -f1)" = "$EXPECTED" ] || { echo "Impronta del pacchetto diversa: rilascio annullato." >&2; exit 1; }
[ -f "$BASE/shared/.env" ] || { echo "Manca $BASE/shared/.env: vedi docs/RILASCIO.md." >&2; exit 1; }

REV="$(basename "$PACKAGE" .tar.gz | sed 's/^saas-//')"
RELEASE="$BASE/releases/$(date -u +%Y%m%d%H%M%S)-$REV"
mkdir -p "$BASE/releases" "$BASE/shared/storage/app/org-files" "$BASE/shared/storage/app/tmp" "$BASE/shared/storage/logs" \
         "$BASE/shared/storage/framework/cache" "$BASE/shared/storage/framework/sessions" "$BASE/shared/storage/framework/views"
chmod 700 "$BASE/shared/storage/app/org-files" "$BASE/shared/storage/app/tmp"
# Il web server (altro utente) deve attraversare releases/ per servire public/ della versione in uso; senza questo,
# il sottodominio risponde 404 anche ai file statici. Solo attraversamento: l'elenco delle versioni resta chiuso.
chmod 711 "$BASE/releases"
chmod 600 "$BASE/shared/.env"

mkdir -p "$RELEASE"
tar -xzf "$PACKAGE" -C "$RELEASE"
rm -f "$PACKAGE"
ln -sfn "$BASE/shared/.env" "$RELEASE/.env"
rm -rf "$RELEASE/storage" && ln -sfn "$BASE/shared/storage" "$RELEASE/storage"
mkdir -p "$RELEASE/bootstrap/cache"

cd "$RELEASE"
"$PHP" artisan saas:health --skip-migrations
"$PHP" artisan saas:migrate --force
"$PHP" artisan saas:audit:partitions
"$PHP" artisan optimize

PREVIOUS="$(readlink "$BASE/current" 2>/dev/null || true)"
ln -sfn "$RELEASE" "$BASE/current.new" && mv -Tf "$BASE/current.new" "$BASE/current"
"$PHP" artisan queue:restart

if ! "$PHP" artisan saas:health; then
    echo "Controllo di salute fallito dopo il cambio: torno alla versione precedente." >&2
    if [ -n "$PREVIOUS" ]; then
        ln -sfn "$PREVIOUS" "$BASE/current.new" && mv -Tf "$BASE/current.new" "$BASE/current"
    fi
    exit 1
fi

# Cron: un'unica riga, lo scheduler di Laravel fa il resto (code, backup, registro, portale).
LINE="* * * * * cd $BASE/current && $PHP artisan schedule:run >> $BASE/shared/storage/logs/cron.log 2>&1"
if command -v crontab >/dev/null; then
    ( crontab -l 2>/dev/null | grep -Fq "$BASE/current && $PHP artisan schedule:run" ) || ( crontab -l 2>/dev/null; echo "$LINE" ) | crontab -
else
    echo "Attenzione: crontab non disponibile, aggiungi a mano: $LINE" >&2
fi

# Si tengono le ultime versioni, mai quella in uso.
CURRENT="$(readlink "$BASE/current")"
ls -1dt "$BASE"/releases/*/ | sed 's#/$##' | tail -n +$((KEEP + 1)) | while read -r old; do
    [ "$old" = "$CURRENT" ] || rm -rf "$old"
done

echo "Rilasciata $(cat "$RELEASE/REVISION") in $RELEASE"
