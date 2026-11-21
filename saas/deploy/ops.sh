#!/usr/bin/env bash
# Gira sul server (sub-account cPanel), lanciato dal workflow «SaaS · manutenzione server» via SSH.
# Uso: ops.sh <diagnosi|chiave-catalogo|percorso-pgdump|ciclo-vita|prova-cancellazione|catalogo|bozza-listino|accendi-ciclo-vita|spegni-cancellazione|pubblica-catalogo|soci-in-vendita|spegni-avvisi|conta-fotografie|sblocca-utente|cancellazioni|permessi-backup|interruttori|verifica-registro|formato-dry-run|coda|avvisa-catalogo|imposta-segreto-proxy|permessi-db|ripristina-permessi> [cartella-bin-postgresql]
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
  avvisa-catalogo)
    # Rimanda al portale l'avviso firmato «catalogo cambiato» per la revisione in vigore (non cambia il catalogo).
    cd "$BASE/current"
    "$PHP" artisan saas:catalog:notify-portal
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
  accendi-ciclo-vita)
    # Accende cancellazione notturna, avvisi email e potatura del registro (decisione scritta della titolare, 13/11/2026).
    for v in SAAS_PURGE_ENABLED SAAS_NOTICES_ENABLED SAAS_AUDIT_PRUNE_ENABLED; do set_var "$v" 1; done
    refresh
    for v in SAAS_PURGE_ENABLED SAAS_NOTICES_ENABLED SAAS_AUDIT_PRUNE_ENABLED; do echo "$v = [$(get "$v")]"; done
    ;;
  spegni-cancellazione)
    # Spegne solo la cancellazione notturna dei dati scaduti (avvisi e potatura del registro restano come sono).
    set_var SAAS_PURGE_ENABLED 0
    refresh
    echo "SAAS_PURGE_ENABLED = [$(get SAAS_PURGE_ENABLED)]  (0 = spenta)"
    ;;
  pubblica-catalogo)
    # Pubblica la bozza del catalogo come revisione successiva (decisione scritta della titolare, 13/11/2026). Autore: la
    # prima persona admin dello staff. Il portale legge la nuova revisione da sé.
    cd "$BASE/current"
    "$PHP" artisan tinker --execute='
      $id = DB::connection("pgsql")->table("core.platform_staff")->where("role", "admin")->whereNull("disabled_at")->orderBy("created_at")->value("id");
      if ($id === null) { echo "Nessuna persona admin nello staff.", PHP_EOL; exit(1); }
      $rev = app(App\Modules\Piattaforma\Catalogo\CatalogPublisher::class)->publish((string) $id, "Listino a tre piani, pacchetti soci e vincoli per piano");
      echo "Pubblicata la revisione ", $rev, PHP_EOL;
    '
    ;;
  soci-in-vendita)
    # Mette in vendita «500 soci in più» e «1.000 soci in più» e pubblica (richiesta scritta della titolare, 13/11/2026).
    cd "$BASE/current"
    "$PHP" artisan tinker --execute='
      $id = DB::connection("pgsql")->table("core.platform_staff")->where("role", "admin")->whereNull("disabled_at")->orderBy("created_at")->value("id");
      if ($id === null) { echo "Nessuna persona admin nello staff.", PHP_EOL; exit(1); }
      $p = app(App\Modules\Piattaforma\Catalogo\CatalogPublisher::class);
      $d = $p->draft();
      $n = 0;
      foreach ($d["addons"] as $i => $a) { if (in_array($a["code"], ["members_500", "members_1000"], true)) { $d["addons"][$i]["visible"] = true; $n++; } }
      if ($n !== 2) { echo "Pacchetti soci non trovati.", PHP_EOL; exit(1); }
      $p->saveDraft($d, (string) $id);
      echo "Pubblicata la revisione ", $p->publish((string) $id, "Pacchetti soci in vendita sulla Grande"), PHP_EOL;
    '
    ;;
  conta-fotografie)
    # Solo lettura: per ogni associazione, quante fotografie del libro soci ha, se la sua chiave c'è e se è cancellata.
    # Serve prima di cifrare le fotografie (una associazione senza chiave o già cancellata, con fotografie, va trattata a parte).
    cd "$BASE/current"
    "$PHP" artisan tinker --execute='
      $o = DB::connection("pgsql_owner");
      foreach ($o->table("core.orgs")->get(["id", "deleted_at"]) as $org) {
        $n = $o->transaction(function () use ($o, $org) {
          $o->select("SELECT set_config(?, ?, true)", ["app.org_id", $org->id]);
          return $o->table("app.member_book_snapshots")->count();
        });
        if ($n > 0) {
          $key = $o->table("core.org_keys")->where("org_id", $org->id)->exists();
          echo $org->id, " fotografie=", $n, " chiave=", $key ? "si" : "NO", " cancellata=", $org->deleted_at === null ? "no" : "SI", PHP_EOL;
        }
      }
      echo "fine", PHP_EOL;
    '
    ;;
  cancellazioni)
    # Sola lettura (decisione del titolare, 16/11/2026): com'è andata la cancellazione notturna e che cosa resta delle associazioni
    # sospese, chiuse o cancellate. Nessun nome né slug: l uscita finisce nel log pubblico del workflow, solo identificativi tecnici e conteggi. Nessuna scrittura.
    cd "$BASE/current"
    echo "== Registro dello staff: cancellazioni dal 13/11 =="
    "$PHP" artisan tinker --execute='
      $c = DB::connection("pgsql_owner");
      $n = 0;
      $rows = $c->table("core.platform_audit")->whereIn("action", ["org.purged", "org.purge_failed", "org.purge_stopped"])->where("created_at", ">=", "2026-11-13")->orderBy("created_at")->get(["created_at", "action", "subject", "details"]);
      foreach ($rows as $r) { $n++; echo $r->created_at, " ", $r->action, " ", $r->subject ?? "-", " ", $r->details, PHP_EOL; }
      if ($n === 0) { echo "(nessuna riga: la cancellazione non ha preso ne tentato nessuna associazione)", PHP_EOL; }
      echo "== Avvisi registrati (la cancellazione parte solo dopo l ultimo avviso) ==", PHP_EOL;
      foreach ($c->table("core.org_notices")->get(["kind", "sent_at"])->groupBy("kind") as $k => $g) { echo $k, ": ", $g->count(), PHP_EOL; }
      echo "== Associazioni sospese, chiuse o cancellate ==", PHP_EOL;
      $names = app(App\Modules\Core\Contracts\DatabaseNames::class);
      $orgs = $c->table("core.orgs")->where(fn ($q) => $q->whereIn("status", ["suspended", "closed"])->orWhereNotNull("deleted_at"))->orderBy("id")->get(["id", "status", "suspended_at", "deleted_at", "database"]);
      foreach ($orgs as $o) {
        $ent = $c->table("core.org_entitlements")->where("org_id", $o->id)->first(["status", "until"]);
        $users = $c->transaction(function () use ($c, $o) { $c->select("SELECT set_config(?, ?, true)", ["app.org_id", $o->id]); return $c->table("core.org_users")->where("org_id", $o->id)->count(); });
        $domains = $c->transaction(function () use ($c, $o) { $c->select("SELECT set_config(?, ?, true)", ["app.org_id", $o->id]); return $c->table("core.org_domains")->where("org_id", $o->id)->count(); });
        $keys = $c->transaction(function () use ($c, $o) { $c->select("SELECT set_config(?, ?, true)", ["app.org_id", $o->id]); return $c->table("core.org_keys")->where("org_id", $o->id)->count(); });
        $data = is_string($o->database) && $o->database !== "" ? DB::connection($names->ownerConnection($o->database)) : $c;
        $rowsLeft = $data->transaction(function () use ($data, $o) {
          $data->select("SELECT set_config(?, ?, true)", ["app.org_id", $o->id]);
          $tot = 0;
          $tables = $data->select("SELECT c.table_schema || ? || c.table_name AS t FROM information_schema.columns c JOIN information_schema.tables t ON t.table_schema = c.table_schema AND t.table_name = c.table_name WHERE c.column_name = ? AND t.table_type = ? AND c.table_schema IN (?, ?, ?, ?) ORDER BY 1", [".", "org_id", "BASE TABLE", "app", "sites", "portal", "audit"]);
          foreach ($tables as $t) { if (preg_match("/^(app|sites|portal|audit)\.[a-z][a-z0-9_]*$/", $t->t) === 1) { $tot += (int) $data->scalar("SELECT count(*) FROM ".$t->t." WHERE org_id = ?", [$o->id]); } }
          return $tot;
        });
        echo $o->id, " stato=", $o->status, " sospesa=", $o->suspended_at ?? "-", " cancellata=", $o->deleted_at ?? "no", " diritti=", $ent->status ?? "-", " until=", $ent->until ?? "-", " righe=", $rowsLeft, " utenti=", $users, " domini=", $domains, " chiavi=", $keys, " cartella=", Storage::disk("org_files")->exists($o->id) ? "si" : "no", PHP_EOL;
      }
      echo "fine (", $orgs->count(), " associazioni)", PHP_EOL;
    ' || true
    echo "== Log: righe sulla cancellazione dal 14/11 =="
    grep -hiE "purge|cancellat" "$BASE/shared/storage/logs/"*.log 2>/dev/null | grep -E "^\[2026-11-(1[4-9]|2[0-9])" | tail -40 | cut -c1-300 || true
    ;;
  permessi-backup)
    # Sola lettura: privilegi del ruolo dei backup fuori da SELECT. Atteso: nessuna riga.
    cd "$BASE/current"
    "$PHP" artisan tinker --execute='
      $role = DB::connection("pgsql_owner")->getConfig("backup_role");
      $rows = DB::connection("pgsql_owner")->select("SELECT table_schema, table_name, privilege_type FROM information_schema.role_table_grants WHERE grantee = ? AND privilege_type <> ? ORDER BY 1, 2, 3", [$role, "SELECT"]);
      echo "Ruolo dei backup: ", $role, PHP_EOL, "Privilegi oltre SELECT: ", count($rows), PHP_EOL;
      foreach ($rows as $r) { echo $r->table_schema, ".", $r->table_name, " ", $r->privilege_type, PHP_EOL; }
    ' || true
    ;;
  verifica-registro)
    # Sola lettura: verifica la catena del registro delle azioni di tutte le associazioni e stampa l'esito (nessun dato personale).
    cd "$BASE/current"
    "$PHP" artisan saas:audit:verify --full || true
    ;;
  formato-dry-run)
    # Sola lettura: prova del passaggio dei campi cifrati al formato legato alla riga (0x02). Non scrive nulla e stampa
    # solo conteggi per associazione e tabella; esce con errore se qualche campo non si decifra. Il passaggio vero
    # (saas:crypt:upgrade-format senza --dry-run) NON è tra queste azioni.
    cd "$BASE/current"
    "$PHP" artisan saas:crypt:upgrade-format --dry-run
    ;;
  coda)
    # Sola lettura: stato della coda dei lavori, senza dati personali (solo numeri). Se il lavoratore (cron ogni minuto)
    # è fermo, i lavori restano in attesa e il più vecchio invecchia.
    cd "$BASE/current"
    "$PHP" artisan tinker --execute='
      $db = DB::connection("pgsql");
      $pending = $db->table("core.jobs")->whereNull("reserved_at")->count();
      $reserved = $db->table("core.jobs")->whereNotNull("reserved_at")->count();
      $oldest = $db->table("core.jobs")->whereNull("reserved_at")->min("available_at");
      $failed = $db->table("core.failed_jobs")->count();
      $failed24 = $db->table("core.failed_jobs")->where("failed_at", ">", now()->subDay())->count();
      echo "In attesa: ", $pending, PHP_EOL, "In lavorazione: ", $reserved, PHP_EOL;
      echo "Il più vecchio in attesa da (minuti): ", is_numeric($oldest) ? max(0, intdiv(time() - (int) $oldest, 60)) : "nessuno", PHP_EOL;
      echo "Falliti in tutto: ", $failed, PHP_EOL, "Falliti nelle ultime 24 ore: ", $failed24, PHP_EOL;
    ' || true
    ;;
  interruttori)
    # Sola lettura: valore degli interruttori NON segreti (acceso/spento, remoto/locale). Niente altro dal file d'ambiente.
    for v in SAAS_UNIFIED_OPERATORS SAAS_UNIFIED_LOGIN SAAS_REQUIRE_TWO_FACTOR SAAS_STAFF_SSO SAAS_STAFF_ISSUER SAAS_NOTICES_ENABLED SAAS_PURGE_ENABLED SAAS_KEY_VAULT SAAS_DEMO; do
      val="$(get "$v")"
      [ -n "$val" ] && echo "$v = $val" || echo "$v = (non impostato: vale il predefinito)"
    done
    ;;
  sblocca-utente)
    # Sblocco dal supporto (decisione scritta del titolare, 16/11/2026): toglie la verifica in due passaggi a chi lavora
    # nel gestionale e ha finito le richieste per email; azzera i conteggi, annota l'evento e avvisa la persona. L'email è il terzo argomento.
    EMAIL="${3:-}"
    [[ "$EMAIL" =~ ^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$ ]] || { echo "Email non valida." >&2; exit 1; }
    cd "$BASE/current"
    "$PHP" artisan saas:user:reset-2fa "$EMAIL"
    ;;
  spegni-avvisi)
    # Spegne gli avvisi email del gestionale (restano solo le email del portale) e dice se ne sono già partiti.
    set_var SAAS_NOTICES_ENABLED 0
    refresh
    echo "SAAS_NOTICES_ENABLED = [$(get SAAS_NOTICES_ENABLED)]"
    cd "$BASE/current"
    "$PHP" artisan tinker --execute='
      $n = DB::connection("pgsql_owner")->table("core.org_notices")->get(["kind", "sent_at"]);
      echo "Avvisi registrati: ", $n->count(), PHP_EOL;
      foreach ($n->groupBy("kind") as $k => $rows) { echo $k, ": ", $rows->count(), PHP_EOL; }
    ' || true
    ;;
  percorso-pgdump)
    [[ "$ARG" =~ ^/[A-Za-z0-9_./-]+$ ]] || { echo "Cartella non valida." >&2; exit 1; }
    [ -x "$ARG/pg_dump" ] && [ -x "$ARG/pg_restore" ] || { echo "In $ARG mancano pg_dump o pg_restore." >&2; exit 1; }
    "$ARG/pg_dump" --version | grep -qE ' 16\.' || { echo "Non è la versione 16: $("$ARG/pg_dump" --version)" >&2; exit 1; }
    set_var SAAS_PG_DUMP "$ARG/pg_dump"; set_var SAAS_PG_RESTORE "$ARG/pg_restore"
    echo "Impostati $ARG/pg_dump e $ARG/pg_restore."
    refresh
    ;;
  imposta-segreto-proxy)
    # Segreto condiviso con il portale (D9): il portale scrive il valore in shared/proxy-secret.txt (600) via SSH; qui lo si valida,
    # lo si mette in SAAS_PROXY_SECRET, si cancella il file. Si stampa solo l'esito e la lunghezza, mai il valore.
    SECRET_FILE="$BASE/shared/proxy-secret.txt"
    [ -f "$SECRET_FILE" ] || { echo "Manca $SECRET_FILE: il portale deve scriverlo prima (permessi 600)." >&2; exit 1; }
    value="$(tr -d '\r\n' < "$SECRET_FILE")"
    [[ "$value" =~ ^[A-Za-z0-9+/=_-]{32,200}$ ]] || { rm -f "$SECRET_FILE"; echo "Segreto non valido (32-200 caratteri alfanumerici, + / = _ -). File cancellato." >&2; exit 1; }
    set_var SAAS_PROXY_SECRET "$value"
    rm -f "$SECRET_FILE"
    refresh
    echo "SAAS_PROXY_SECRET impostata (${#value} caratteri). File temporaneo cancellato."
    echo "SAAS_TRUSTED_PROXIES = [$(get SAAS_TRUSTED_PROXIES)] (con il segreto non si usa più: si può svuotare dopo la prova)"
    ;;
  permessi-db)
    # Riduce i permessi del ruolo applicativo. Prima salva i permessi attuali in shared/grants-backup-<data>.sql
    # (ripristino: azione ripristina-permessi); poi toglie CREATE sugli schemi, TRUNCATE/REFERENCES/TRIGGER sulle tabelle e la lettura di public.migrations.
    cd "$BASE/current"
    GRANTS_BACKUP="$BASE/shared/grants-backup-$(date -u +%Y%m%d%H%M%S).sql" "$PHP" artisan tinker --execute='
      $db = DB::connection("pgsql_owner");
      $role = DB::connection("pgsql")->selectOne("select current_user as u")->u;
      $r = "\"".str_replace("\"", "\"\"", $role)."\"";
      $lines = [];
      foreach (["public", "app", "audit", "sites", "core"] as $schema) {
        if ($db->selectOne("select has_schema_privilege(?, ?, ?) as h", [$role, $schema, "CREATE"])->h) { $lines[] = "GRANT CREATE ON SCHEMA {$schema} TO {$r}"; }
      }
      $rows = $db->select("select table_schema, table_name, privilege_type from information_schema.role_table_grants where grantee = ? and privilege_type in (?, ?, ?, ?)", [$role, "TRUNCATE", "REFERENCES", "TRIGGER", "SELECT"]);
      foreach ($rows as $row) {
        if ($row->privilege_type === "SELECT" && !($row->table_schema === "public" && $row->table_name === "migrations")) { continue; }
        $lines[] = "GRANT {$row->privilege_type} ON {$row->table_schema}.{$row->table_name} TO {$r}";
      }
      $file = getenv("GRANTS_BACKUP");
      file_put_contents($file, implode(";\n", $lines).(count($lines) ? ";\n" : ""));
      chmod($file, 0600);
      echo "Permessi attuali salvati in ", $file, " (", count($lines), " righe)", PHP_EOL;
      foreach (["public", "app", "audit", "sites", "core"] as $schema) { $db->statement("REVOKE CREATE ON SCHEMA {$schema} FROM {$r}"); }
      foreach (["app", "audit", "sites", "core"] as $schema) { $db->statement("REVOKE TRUNCATE, REFERENCES, TRIGGER ON ALL TABLES IN SCHEMA {$schema} FROM {$r}"); }
      if ($db->selectOne("select to_regclass(?) as t", ["public.migrations"])->t !== null) { $db->statement("REVOKE ALL ON public.migrations FROM {$r}"); }
      echo "Permessi ridotti per il ruolo ", $role, PHP_EOL;
      // I poteri in più arrivano dal ruolo di cui il ruolo applicativo fa parte (eredita CREATE, TRUNCATE, ecc.):
      // NOINHERIT li toglie senza toccare i permessi diretti. Se la applicazione non legge più le sue tabelle, si torna indietro da soli.
      file_put_contents($file, "ALTER ROLE {$r} INHERIT;\n", FILE_APPEND);
      $db->statement("ALTER ROLE {$r} NOINHERIT");
      try {
        $app = DB::connection("pgsql");
        $app->purge();
        foreach (["app.orgs", "core.users", "core.sessions"] as $table) { $app->selectOne("select 1 from {$table} limit 1"); }
        echo "NOINHERIT applicato, l\u{0027}applicazione legge ancora le sue tabelle", PHP_EOL;
      } catch (Throwable $e) {
        $db->statement("ALTER ROLE {$r} INHERIT");
        echo "NOINHERIT annullato: ", substr($e->getMessage(), 0, 200), PHP_EOL;
      }
    '
    echo "== Controllo =="
    "$PHP" artisan saas:health --skip-migrations | grep -E "permessi|ruolo|ERRORE|AVVISO" || true
    ;;
  svuota-proxy-fidati)
    # Solo con SAAS_PROXY_SECRET impostata: l'elenco dei proxy fidati non serve più (conta il segreto condiviso).
    [ -n "$(get SAAS_PROXY_SECRET)" ] || { echo "SAAS_PROXY_SECRET è vuota: non svuoto l'elenco." >&2; exit 1; }
    set_var SAAS_TRUSTED_PROXIES ""
    refresh
    echo "SAAS_TRUSTED_PROXIES = [$(get SAAS_TRUSTED_PROXIES)]"
    ;;
  prova-recupero)
    # Prova che sull'hosting Argon2id con i parametri della copia di recupero funziona (memoria, tempo): cifra e riapre in memoria
    # un contenuto di prova con una frase di prova. Non scrive file, non tocca chiavi vere, non stampa segreti.
    cd "$BASE/current"
    "$PHP" artisan tinker --execute='
      $t = microtime(true);
      $frase = "frase di prova soltanto per questa verifica";
      $sealed = App\Modules\Cifratura\Keys\RecoveryFile::seal(["service_url" => "https://prova.invalid"], $frase);
      $open = App\Modules\Cifratura\Keys\RecoveryFile::open($sealed, $frase);
      $ok = ($open["items"]["service_url"] ?? null) === "https://prova.invalid";
      echo "Cifratura e riapertura di prova: ", $ok ? "riuscite" : "FALLITE", " in ", round(microtime(true) - $t, 2), " s; memory_limit PHP = ", ini_get("memory_limit"), "; picco ", round(memory_get_peak_usage(true) / 1048576), " MiB", PHP_EOL;
      try { App\Modules\Cifratura\Keys\RecoveryFile::open($sealed, "altra frase sbagliata di prova"); echo "ERRORE: frase sbagliata accettata", PHP_EOL; } catch (Throwable $e) { echo "Frase sbagliata rifiutata come previsto", PHP_EOL; }
    '
    mkdir -p "$HOME/recupero" && chmod 700 "$HOME/recupero" && echo "Cartella recupero pronta nella home (permessi 700, fuori dalla cartella web)."
    ;;
  prova-ip)
    # Sola lettura: quante richieste ha contato il limite dell'API dello staff per l'indirizzo dato (secondo argomento, pg_bin_dir).
    # Serve a provare che dietro il proxy del portale il SaaS vede l'indirizzo vero del visitatore e non quello del server.
    [[ "$ARG" =~ ^[0-9a-fA-F:.]{3,45}$ ]] || { echo "Indirizzo IP non valido." >&2; exit 1; }
    cd "$BASE/current"
    PROVA_IP="$ARG" "$PHP" artisan tinker --execute='
      $ip = getenv("PROVA_IP");
      $k = "staff-api|".$ip;
      echo "IP ", $ip, ": richieste contate = ", max(Illuminate\Support\Facades\RateLimiter::attempts(md5("staff-api".$k)), Illuminate\Support\Facades\RateLimiter::attempts("staff-api".$k)), PHP_EOL;
    '
    ;;
  permessi-diagnosi)
    # Sola lettura: da dove arrivano i permessi (ACL di uno schema e di una tabella, ruoli di cui fa parte il ruolo applicativo).
    cd "$BASE/current"
    "$PHP" artisan tinker --execute='
      $db = DB::connection("pgsql_owner");
      $role = DB::connection("pgsql")->selectOne("select current_user as u")->u;
      echo "ruolo: ", $role, PHP_EOL;
      foreach ($db->select("select p.rolname from pg_auth_members m join pg_roles r on r.oid = m.member join pg_roles p on p.oid = m.roleid where r.rolname = ?", [$role]) as $x) { echo "membro di: ", $x->rolname, PHP_EOL; }
      foreach ($db->select("select nspname, nspacl::text as acl from pg_namespace where nspname in (?, ?, ?, ?, ?)", ["public", "app", "audit", "sites", "core"]) as $x) { echo "schema ", $x->nspname, ": ", $x->acl, PHP_EOL; }
      foreach ($db->select("select n.nspname || ? || c.relname as t, c.relacl::text as acl from pg_class c join pg_namespace n on n.oid = c.relnamespace where n.nspname = ? and c.relkind = ? limit 2", [".", "app", "r"]) as $x) { echo $x->t, ": ", $x->acl, PHP_EOL; }
      foreach ($db->select("select pg_get_userbyid(defaclrole) as chi, defaclnamespace::regnamespace::text as schema, defaclobjtype as tipo, defaclacl::text as acl from pg_default_acl") as $x) { echo "default: ", $x->chi, " ", $x->schema, " ", $x->tipo, " ", $x->acl, PHP_EOL; }
    '
    ;;
  ripristina-permessi)
    # Rimette i permessi dall'ultimo file grants-backup-*.sql (comando di ritorno per permessi-db).
    cd "$BASE/current"
    latest="$(ls -1t "$BASE"/shared/grants-backup-*.sql 2>/dev/null | head -1)"
    [ -n "$latest" ] || { echo "Nessun file grants-backup trovato." >&2; exit 1; }
    GRANTS_BACKUP="$latest" "$PHP" artisan tinker --execute='
      $db = DB::connection("pgsql_owner");
      $n = 0;
      foreach (array_filter(array_map("trim", explode(";\n", (string) file_get_contents(getenv("GRANTS_BACKUP"))))) as $sql) { $db->statement($sql); $n++; }
      echo "Ripristinate ", $n, " concessioni da ", basename(getenv("GRANTS_BACKUP")), PHP_EOL;
    '
    ;;
  *) echo "Azione sconosciuta: $ACTION" >&2; exit 1 ;;
esac
