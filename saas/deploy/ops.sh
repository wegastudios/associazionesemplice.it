#!/usr/bin/env bash
# Gira sul server (sub-account cPanel), lanciato dal workflow «SaaS · manutenzione server» via SSH.
# Uso: ops.sh <diagnosi|chiave-catalogo|percorso-pgdump|ciclo-vita|prova-cancellazione|catalogo|bozza-listino> [cartella-bin-postgresql]
#
# Azioni fisse e limitate (non è una shell remota):
#   diagnosi          sola lettura: stato (impostata/vuota) delle variabili che contano, client PostgreSQL presenti, ultimi errori
#   chiave-catalogo   se SAAS_PORTAL_SIGNING_KEY è vuota genera il seme in shared/.env (permessi 600, copia del file prima),
#                     svuota la cache della configurazione e stampa SOLO kid e parte pubblica x. Il seme non si stampa mai.
#   percorso-pgdump   imposta SAAS_PG_DUMP e SAAS_PG_RESTORE nella cartella indicata, solo se è la versione 16
set -euo pipefail
ACTION="${1:?azione}"; ARG="${2:-}"
BASE="${SAAS_BASE:-$HOME/saas}"
PHP="${SAAS_PHP:-/usr/local/bin/php}"
ENVFILE="$BASE/shared/.env"
[ -f "$ENVFILE" ] || { echo "Manca $ENVFILE." >&2; exit 1; }

get() { grep -E "^$1=" "$ENVFILE" | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//' -e "s/^'//" -e "s/'\$//" || true; }

set_var() { # nome valore: sostituisce la riga o la aggiunge; il file resta 600
  local name="$1" value="$2" tmp
  cp -p "$ENVFILE" "$ENVFILE.bak-$(date -u +%Y%m%d%H%M%S)"
  tmp="$(mktemp "$BASE/shared/.env.XXXXXX")"
  if grep -qE "^$name=" "$ENVFILE"; then
    awk -v n="$name" -v v="$value" 'BEGIN{FS=OFS="="} $1==n {print n "=" v; next} {print}' "$ENVFILE" > "$tmp"
  else
    cat "$ENVFILE" > "$tmp"; printf '%s=%s\n' "$name" "$value" >> "$tmp"
  fi
  chmod 600 "$tmp"; mv "$tmp" "$ENVFILE"
}

refresh() { (cd "$BASE/current" && "$PHP" artisan config:clear >/dev/null && "$PHP" artisan optimize >/dev/null && echo "Cache della configurazione ricreata."); }

public_of_seed() { SEED="$1" "$PHP" -r '
  $seed = base64_decode(getenv("SEED"), true);
  if ($seed === false || strlen($seed) !== SODIUM_CRYPTO_SIGN_SEEDBYTES) { fwrite(STDERR, "Seme non valido.\n"); exit(1); }
  $x = rtrim(strtr(base64_encode(sodium_crypto_sign_publickey(sodium_crypto_sign_seed_keypair($seed))), "+/", "-_"), "=");
  echo "saas-" . substr(hash("sha256", $x), 0, 8) . "=" . $x . PHP_EOL;'; }

case "$ACTION" in
  diagnosi)
    echo "== Variabili (solo stato) =="
    for v in SAAS_PORTAL_SIGNING_KEY SAAS_PORTAL_URL SAAS_PORTAL_PUBLIC_KEYS SAAS_BACKUP_PUBLIC_KEY SAAS_MASTER_KEY SAAS_KEY_SERVICE_URL; do
      [ -n "$(get "$v")" ] && echo "$v = impostata" || echo "$v = VUOTA"
    done
    echo "SAAS_PG_DUMP = $(get SAAS_PG_DUMP)"; echo "SAAS_PG_RESTORE = $(get SAAS_PG_RESTORE)"
    echo "== Client PostgreSQL sul server =="
    for p in /usr/pgsql-*/bin/pg_dump /usr/lib/postgresql/*/bin/pg_dump /usr/local/pgsql*/bin/pg_dump "$HOME"/*/bin/pg_dump "$HOME"/bin/pg_dump "$(command -v pg_dump || true)"; do
      [ -n "$p" ] && [ -x "$p" ] && echo "$p -> $("$p" --version 2>&1 | head -1)"
    done
    echo "== Ultimi errori del log =="
    grep -hE "ERROR|CRITICAL" "$BASE/shared/storage/logs/"*.log 2>/dev/null | tail -8 | cut -c1-300 || true
    ;;
  chiave-catalogo)
    current="$(get SAAS_PORTAL_SIGNING_KEY)"
    if [ -z "$current" ]; then
      seed="$("$PHP" -r 'echo base64_encode(random_bytes(SODIUM_CRYPTO_SIGN_SEEDBYTES));')"
      set_var SAAS_PORTAL_SIGNING_KEY "$seed"
      echo "Seme generato e salvato in shared/.env (non mostrato)."
      refresh
    else
      seed="$current"; echo "La chiave c'era già: non la cambio."
    fi
    echo "== Da fissare nel portale (kid=x) =="
    public_of_seed "$seed"
    ;;
  ciclo-vita)
    # Solo lettura: elenco di cosa cancellerebbero cancellazione e potatura del registro, verifica delle catene, tabelle nuove.
    cd "$BASE/current"
    echo "== Cancellazione dati (solo elenco) =="; "$PHP" artisan saas:portal:purge-expired --dry-run || true
    echo "== Registro: partizioni da cancellare (solo elenco) =="; "$PHP" artisan saas:audit:prune --dry-run || true
    echo "== Registro: verifica delle catene =="; "$PHP" artisan saas:audit:verify | tail -5 || true
    echo "== Tabelle nuove =="
    "$PHP" artisan tinker --execute='echo (DB::connection("pgsql")->selectOne("select to_regclass(?) is not null as ok", ["core.org_notices"])->ok ? "core.org_notices ok" : "core.org_notices MANCA"), PHP_EOL, (DB::connection("pgsql")->selectOne("select to_regclass(?) is not null as ok", ["audit.anchors"])->ok ? "audit.anchors ok" : "audit.anchors MANCA"), PHP_EOL;' || true
    echo "== Interruttori (devono essere spenti) =="
    for v in SAAS_PURGE_ENABLED SAAS_NOTICES_ENABLED SAAS_AUDIT_PRUNE_ENABLED; do echo "$v = [$(get "$v")]  (vuoto o 0 = spento)"; done
    ;;
  prova-cancellazione)
    # Crea un'associazione di prova, la cancella con il servizio vero e controlla che le altre restino identiche; poi verifica le catene.
    cd "$BASE/current"
    "$PHP" artisan saas:selftest:purge
    echo "== Registro: verifica delle catene =="; "$PHP" artisan saas:audit:verify | tail -5
    ;;
  catalogo)
    # Sola lettura: ultima revisione pubblicata e bozza (piani con versione, visibilità e operatori; pacchetti).
    cd "$BASE/current"
    "$PHP" artisan tinker --execute='
      $show = function (string $title, ?array $doc): void {
        echo "== {$title} ==", PHP_EOL;
        if ($doc === null) { echo "(nessuno)", PHP_EOL; return; }
        foreach ($doc["plans"] ?? [] as $p) { echo "piano ", $p["code"], " v", $p["plan_version"], " visibile=", $p["visible"] ? "si" : "no", " operatori=", $p["limits"]["operators"] ?? "?", " soci=", var_export($p["limits"]["members"] ?? null, true), PHP_EOL; }
        foreach ($doc["addons"] ?? [] as $a) { echo "addon ", $a["code"], " visibile=", $a["visible"] ? "si" : "no", PHP_EOL; }
        echo "terms.members: ", json_encode($doc["terms"]["members"] ?? null), PHP_EOL;
      };
      $rev = DB::connection("pgsql")->table("core.catalog_revisions")->orderByDesc("rev")->first(["rev", "document", "published_at"]);
      echo "Ultima revisione pubblicata: ", $rev === null ? "nessuna" : $rev->rev." del ".$rev->published_at, PHP_EOL;
      $show("Pubblicato", $rev === null ? null : json_decode($rev->document, true));
      $draft = DB::connection("pgsql")->table("core.catalog_draft")->first(["document", "updated_at"]);
      $show("Bozza (ultima modifica ".($draft->updated_at ?? "-").")", $draft === null ? null : json_decode($draft->document, true));
    '
    ;;
  bozza-listino)
    # Rifà la bozza del catalogo dal listino di partenza (piani non ancora pubblicati e condizioni mancanti). NON pubblica:
    # la pubblicazione la fa la titolare dal pannello. Autore della bozza: la prima persona dello staff con ruolo admin.
    cd "$BASE/current"
    EMAIL="$("$PHP" artisan tinker --execute='echo DB::connection("pgsql")->table("core.platform_staff")->where("role", "admin")->whereNull("disabled_at")->orderBy("created_at")->value("email");' | tail -1)"
    [ -n "$EMAIL" ] || { echo "Nessuna persona admin nello staff." >&2; exit 1; }
    "$PHP" artisan saas:catalog:prepare-tiers "$EMAIL"
    ;;
  percorso-pgdump)
    [[ "$ARG" =~ ^/[A-Za-z0-9_./-]+$ ]] || { echo "Cartella non valida." >&2; exit 1; }
    [ -x "$ARG/pg_dump" ] && [ -x "$ARG/pg_restore" ] || { echo "In $ARG mancano pg_dump o pg_restore." >&2; exit 1; }
    "$ARG/pg_dump" --version | grep -qE ' 16\.' || { echo "Non è la versione 16: $("$ARG/pg_dump" --version)" >&2; exit 1; }
    set_var SAAS_PG_DUMP "$ARG/pg_dump"; set_var SAAS_PG_RESTORE "$ARG/pg_restore"
    echo "Impostati $ARG/pg_dump e $ARG/pg_restore."
    refresh
    ;;
  *) echo "Azione sconosciuta: $ACTION" >&2; exit 1 ;;
esac
