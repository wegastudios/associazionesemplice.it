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
BASE="${SAAS_BASE:?SAAS_BASE obbligatoria: indica la cartella da rilasciare (~/saas o ~/saas-demo)}"
PHP="${SAAS_PHP:-/usr/local/bin/php}"   # da cron e da SSH: php da solo è la versione CGI
KEEP=5

[ "$(sha256sum "$PACKAGE" | cut -d' ' -f1)" = "$EXPECTED" ] || { echo "Impronta del pacchetto diversa: rilascio annullato." >&2; exit 1; }
[ -f "$BASE/shared/.env" ] || { echo "Manca il file di configurazione condiviso: vedi docs/RILASCIO.md." >&2; exit 1; }

# Il log del workflow è pubblico: il dettaglio del controllo di salute (avvisi, permessi, configurazione) va solo in un
# file riservato sul server (shared/health-<data>.txt, permessi 600, ultimi 5); nel log escono solo l'esito e il conteggio.
# I problemi della posta in coda qui sono avvisi: non dipendono dalla versione e non devono annullare il rilascio.
health() {
    local rc=0 stamp file warns
    stamp="$(date -u +%Y%m%dT%H%M%S)"
    file="$BASE/shared/health-$stamp.txt"
    ( umask 077; "$PHP" artisan saas:health --mail-warnings-only ${IGNORE_BALANCES_OPT[@]+"${IGNORE_BALANCES_OPT[@]}"} "$@" > "$file" 2>&1 ) || rc=$?
    chmod 600 "$file" 2>/dev/null || true
    warns="$(grep -c '^AVVISO' "$file" 2>/dev/null || true)"
    if [ "$rc" -eq 0 ]; then
        echo "controllo di salute: ok (${warns:-0} avvisi)"
    else
        echo "controllo di salute: ERRORE (dettaglio in shared/health-$stamp.txt, leggilo via ssh)"
    fi
    ls -1t "$BASE"/shared/health-*.txt 2>/dev/null | tail -n +6 | while read -r old; do rm -f "$old"; done
    return "$rc"
}

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
# «ignora-saldi» (input del workflow, RILASCIO.md): solo per il rilascio che corregge un difetto dei saldi dei conti. Il
# controllo di salute tratta come avviso SOLO la voce «saldi dei conti»; si scrive nel log del rilascio e nel registro
# dell'amministrazione, e se l'annotazione non riesce il rilascio si ferma.
IGNORE_BALANCES_OPT=()
if [ "${SAAS_IGNORE_BALANCES:-false}" = "true" ]; then
    ACTOR="${SAAS_DEPLOY_ACTOR:-}"
    [[ "$ACTOR" =~ ^[A-Za-z0-9][A-Za-z0-9-]{0,38}$ ]] || { echo "ignora-saldi senza un utente valido: rilascio annullato." >&2; exit 1; }
    echo "ATTENZIONE: controllo dei saldi dei conti IGNORATO per questo rilascio, lanciato da $ACTOR (gli altri controlli restano bloccanti)."
    "$PHP" artisan saas:deploy:ignore-balances "$ACTOR" "$REV"
    IGNORE_BALANCES_OPT=(--ignore-balances)
fi
health --skip-migrations
# saas:migrate aspetta la fine del backup orario, se è in corso, e riprova le istruzioni che non ottengono un blocco.
"$PHP" artisan saas:migrate --force
# I mesi del registro sono pronti con tre mesi di anticipo: se ora non si riesce (backup in corso) il rilascio prosegue,
# il controllo di salute lo segnala e ci riprova il lavoro mensile.
"$PHP" artisan saas:audit:partitions || echo "Partizioni del registro non preparate ora: ci riprova il lavoro mensile." >&2
"$PHP" artisan saas:tmp:purge --minutes=60 || true
"$PHP" artisan optimize
# Il catalogo non si scrive più da una lettura: i giorni di conservazione della nuova configurazione si allineano qui.
"$PHP" artisan saas:catalog:align

PREVIOUS="$(readlink "$BASE/current" 2>/dev/null || true)"
ln -sfn "$RELEASE" "$BASE/current.new" && mv -Tf "$BASE/current.new" "$BASE/current"
"$PHP" artisan queue:restart
# Il PHP web riparte dal codice nuovo: OPcache e cache dei percorsi azzerati con la rotta firmata, poi due richieste di
# riscaldamento. Al meglio: se non risponde si prosegue (il controllo dei file ogni 2 secondi porta comunque il codice nuovo).
"$PHP" artisan saas:web:refresh || echo "Azzeramento di OPcache non riuscito: si prosegue." >&2

if ! health; then
    echo "Controllo di salute fallito dopo il cambio: torno alla versione precedente." >&2
    if [ -n "$PREVIOUS" ]; then
        ln -sfn "$PREVIOUS" "$BASE/current.new" && mv -Tf "$BASE/current.new" "$BASE/current"
        ( cd "$PREVIOUS" && "$PHP" artisan saas:web:refresh --no-warm ) || true
    fi
    exit 1
fi

# Solo la demo: il portale lancia ~/saas-demo/reset.sh per azzerarla (comando fisso, aggiornato a ogni rilascio).
if [ "$(basename "$BASE")" = "saas-demo" ] && [ -f "$RELEASE/deploy/demo-reset.sh" ]; then
    install -m 755 "$RELEASE/deploy/demo-reset.sh" "$BASE/reset.sh"
fi

# Cron: un'unica riga, lo scheduler di Laravel fa il resto (code, backup, registro, portale).
LINE="* * * * * cd $BASE/current && $PHP artisan schedule:run >> $BASE/shared/storage/logs/cron.log 2>&1"
if command -v crontab >/dev/null; then
    ( crontab -l 2>/dev/null | grep -Fq "$BASE/current && $PHP artisan schedule:run" ) || ( crontab -l 2>/dev/null; echo "$LINE" ) | crontab -
else
    echo "Attenzione: crontab non disponibile, configura lo scheduler a mano (vedi docs/RILASCIO.md)." >&2
fi

# Si tengono le ultime versioni, mai quella in uso.
CURRENT="$(readlink "$BASE/current")"
ls -1dt "$BASE"/releases/*/ | sed 's#/$##' | tail -n +$((KEEP + 1)) | while read -r old; do
    [ "$old" = "$CURRENT" ] || rm -rf "$old"
done

echo "Rilasciata $(cat "$RELEASE/REVISION")"
