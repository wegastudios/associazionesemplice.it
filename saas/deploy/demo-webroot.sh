#!/usr/bin/env bash
# Cartella pubblica della demo del gestionale, soluzione B (docs/DEMO.md): public_html resta una cartella VERA di cPanel
# (document root del dominio principale dell'account, non si cambia) e contiene solo file stabili:
#   .htaccess   i blocchi di cPanel restano intatti; si aggiunge, tra due marcatori, il blocco di deploy/demo-htaccess.conf
#   index.php   avvio fisso: carica ~/saas-demo/current/public/index.php (non cambia a ogni rilascio)
#   assets, build, favicon.ico, favicon.svg   collegamenti simbolici a ~/saas-demo/current/public/... (se esistono)
# Non tocca MAI .user.ini, php.ini, .well-known, cgi-bin né altro. Non è lanciato dal rilascio: lo lancia il titolare, a mano.
#
# Uso (sul server, dall'account della demo):
#   bash ~/saas-demo/current/deploy/demo-webroot.sh stato        sola lettura: cosa c'è e cosa farebbe
#   bash ~/saas-demo/current/deploy/demo-webroot.sh installa     copia di sicurezza di .htaccess e index.php, poi li scrive
#   bash ~/saas-demo/current/deploy/demo-webroot.sh ripristina   rimette .htaccess e index.php come prima e toglie i collegamenti
#   bash ~/saas-demo/current/deploy/demo-webroot.sh prova [URL]  richiede dal dominio gli indirizzi che NON devono rispondere (403/404)
# Variabili: SAAS_BASE (~/saas-demo), DEMO_WEB (~/public_html).
set -euo pipefail

ACTION="${1:-stato}"
BASE="${SAAS_BASE:-$HOME/saas-demo}"
WEB="${DEMO_WEB:-$HOME/public_html}"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CONF="$HERE/demo-htaccess.conf"
BEGIN="# BEGIN AssociazioneSemplice demo"
END="# END AssociazioneSemplice demo"
BOOT_MARK="associazionesemplice-demo-avvio"
LINKS=(assets build favicon.ico favicon.svg)
BACKUPS="$BASE/shared/webroot-backup"

die() { echo "$*" >&2; exit 1; }
[ "$(basename "$BASE")" = "saas-demo" ] || die "SAAS_BASE deve essere la cartella saas-demo (non la produzione): $(basename "$BASE")."
[ -d "$WEB" ] || die "Manca la cartella pubblica: $WEB."
[ -f "$CONF" ] || die "Manca $CONF."

strip_block() { # file: stampa il file senza il nostro blocco (i blocchi di cPanel restano com'è)
  awk -v b="$BEGIN" -v e="$END" 'index($0, b) == 1 {skip=1} !skip {print} index($0, e) == 1 {skip=0}' "$1"
}

boot_php() {
  cat <<'PHP'
<?php

// associazionesemplice-demo-avvio: file stabile di public_html (lo scrive deploy/demo-webroot.sh, non cambia a ogni rilascio).
// Carica l'applicazione della versione in uso. clearstatcache(true) azzera la cache dei percorsi risolti: dopo un cambio di
// ~/saas-demo/current si parte subito dalla versione nuova, anche senza aspettare la scadenza della cache.
clearstatcache(true);

require dirname(__DIR__).'/saas-demo/current/public/index.php';
PHP
}

case "$ACTION" in
  stato)
    echo "== public_html: $WEB =="
    ls -la "$WEB" | sed 's/^/  /'
    echo "== versione in uso: $(readlink "$BASE/current" 2>/dev/null || echo 'nessuna (rilascia prima)') =="
    grep -q "$BEGIN" "$WEB/.htaccess" 2>/dev/null && echo ".htaccess: blocco della demo presente" || echo ".htaccess: blocco della demo ASSENTE"
    grep -q "$BOOT_MARK" "$WEB/index.php" 2>/dev/null && echo "index.php: avvio della demo presente" || echo "index.php: avvio della demo ASSENTE"
    for n in "${LINKS[@]}"; do
      if [ -L "$WEB/$n" ]; then echo "$n: collegamento -> $(readlink "$WEB/$n")"
      elif [ -e "$WEB/$n" ]; then echo "$n: esiste ed è VERO (non lo tocco)"
      else echo "$n: assente"; fi
    done
    for n in .user.ini php.ini .well-known; do [ -e "$WEB/$n" ] && echo "$n: c'è (non lo tocco mai)" || echo "$n: assente"; done
    ;;

  installa)
    [ -f "$BASE/current/public/index.php" ] || die "Manca $BASE/current/public/index.php: rilascia prima la demo."
    stamp="$(date -u +%Y%m%dT%H%M%S).$(date -u +%N)"
    dir="$BACKUPS/$stamp"
    mkdir -p "$dir"; chmod 700 "$BACKUPS" "$dir"
    # Copia di sicurezza: solo i due file che si scrivono. «assente» se non c'erano. Se sono già i nostri (seconda
    # installazione) la copia è quello che c'era prima di noi: .htaccess senza il nostro blocco, index.php «assente».
    if [ -f "$WEB/.htaccess" ] && [ ! -L "$WEB/.htaccess" ]; then strip_block "$WEB/.htaccess" > "$dir/.htaccess"; else echo assente > "$dir/.htaccess.assente"; fi
    if [ -f "$WEB/index.php" ] && [ ! -L "$WEB/index.php" ] && ! grep -q "$BOOT_MARK" "$WEB/index.php"; then cp -p "$WEB/index.php" "$dir/index.php"; else echo assente > "$dir/index.php.assente"; fi
    # index.php: se c'è un file che non è il nostro avvio, la copia è già fatta sopra; si sostituisce solo il nostro.
    tmp="$(mktemp "$WEB/.index.XXXXXX")"; boot_php > "$tmp"; chmod 644 "$tmp"; mv -f "$tmp" "$WEB/index.php"
    # .htaccess: tutto com'era (blocchi di cPanel compresi), senza un nostro blocco precedente, più il blocco nuovo in fondo.
    tmp="$(mktemp "$WEB/.htaccess.XXXXXX")"
    { if [ -f "$WEB/.htaccess" ]; then strip_block "$WEB/.htaccess"; fi; cat "$CONF"; } > "$tmp"
    chmod 644 "$tmp"; mv -f "$tmp" "$WEB/.htaccess"
    # Collegamenti ai file statici della versione in uso (il percorso non cambia: «current» è esso stesso un collegamento).
    for n in "${LINKS[@]}"; do
      if [ -e "$BASE/current/public/$n" ]; then
        if [ -L "$WEB/$n" ] || [ ! -e "$WEB/$n" ]; then ln -sfn "$BASE/current/public/$n" "$WEB/$n"; echo "collegamento: $n"
        else echo "ATTENZIONE: $WEB/$n esiste ed è vero: non lo tocco, spostalo tu." >&2; fi
      fi
    done
    echo "Fatto. Copia di sicurezza in $dir. Ora lancia: bash $HERE/demo-webroot.sh prova"
    ;;

  ripristina)
    last="$(ls -1d "$BACKUPS"/*/ 2>/dev/null | sort | head -1 || true)"
    [ -n "$last" ] || die "Nessuna copia di sicurezza in $BACKUPS."
    for f in .htaccess index.php; do
      if [ -f "${last}$f" ]; then cp -p "${last}$f" "$WEB/$f"; echo "$f: rimesso com'era"
      elif [ -f "${last}$f.assente" ]; then rm -f "$WEB/$f"; echo "$f: tolto (prima non c'era)"; fi
    done
    for n in "${LINKS[@]}"; do
      if [ -L "$WEB/$n" ] && [[ "$(readlink "$WEB/$n")" == "$BASE"/* ]]; then rm -f "$WEB/$n"; echo "collegamento tolto: $n"; fi
    done
    echo "Ripristinato da $last"
    ;;

  prova)
    URL="${2:-}"
    if [ -z "$URL" ] && [ -f "$BASE/shared/.env" ]; then URL="$(grep -E '^APP_URL=' "$BASE/shared/.env" | tail -1 | cut -d= -f2- | sed -e 's/[[:space:]]*#.*$//' -e 's/^"//' -e 's/"$//')"; fi
    [ -n "$URL" ] || die "Indica l'indirizzo: prova https://demosaas.associazionesemplice.it"
    URL="${URL%/}"; bad=0
    # Questi indirizzi NON devono dare né una pagina né un file: solo 400, 403 o 404.
    for p in /.env /.env.backup /.git/config /.user.ini /php.ini /.htaccess /composer.json /composer.lock /artisan /vendor/autoload.php \
             /storage/logs/laravel.log /storage /bootstrap/app.php /app /config/app.php /database /deploy/remote.sh /releases /saas-demo \
             /shared/.env /current /public/index.php /../.env /%2e%2e/.env /index.php/.env; do
      code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 15 --path-as-is "$URL$p" || echo 000)"
      case "$code" in 400|403|404) echo "ok    $code  $p" ;; *) echo "ERRORE $code  $p (deve essere 400, 403 o 404)"; bad=1 ;; esac
    done
    # E questi devono rispondere.
    for p in /up /accesso /area/accesso /robots.txt; do
      code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 15 "$URL$p" || echo 000)"
      case "$code" in 200) echo "ok    $code  $p" ;; *) echo "ERRORE $code  $p (deve essere 200)"; bad=1 ;; esac
    done
    robots="$(curl -s --max-time 15 "$URL/robots.txt" || true)"
    case "$robots" in *"Disallow: /"*) echo "ok    robots.txt nega tutto" ;; *) echo "ERRORE robots.txt non nega tutto"; bad=1 ;; esac
    exit "$bad"
    ;;

  *) die "Azione sconosciuta: $ACTION (stato, installa, ripristina, prova)." ;;
esac
