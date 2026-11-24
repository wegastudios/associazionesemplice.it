#!/usr/bin/env bash
# Gira sul server (sub-account cPanel), lanciato dal workflow «SaaS · manutenzione server» via SSH.
# Uso: ops.sh <diagnosi|chiave-catalogo|percorso-pgdump|ciclo-vita|prova-cancellazione|catalogo|bozza-listino|accendi-ciclo-vita|spegni-cancellazione|pubblica-catalogo|soci-in-vendita|spegni-avvisi|conta-fotografie|sblocca-utente|cancellazioni|permessi-backup|interruttori|verifica-registro|indici-registro|formato-dry-run|coda|avvisa-catalogo|imposta-segreto-proxy|copie-env|pulisci-copie-env|permessi-db|ripristina-permessi|misure|db-connection-limit-60|db-connection-limit-off> [cartella-bin-postgresql]
#
# Il log del workflow è pubblico: dettagli di configurazione su file in shared/ (600), nel log solo esiti, conteggi e percorso.
# Azioni fisse e limitate (non è una shell remota):
#   diagnosi          sola lettura: stato (impostata/vuota) delle variabili che contano, client PostgreSQL presenti, ultimi errori
#   chiave-catalogo   se SAAS_PORTAL_SIGNING_KEY è vuota genera il seme in shared/.env (permessi 600, copia del file prima),
#                     svuota la cache della configurazione e stampa SOLO kid e parte pubblica x. Il seme non si stampa mai.
#   copie-env         sola lettura: elenca cosa cancellerebbe la rotazione delle copie shared/.env.bak-<data> (massimo 2, nessuna oltre 7 giorni)
#   pulisci-copie-env applica la rotazione una volta e accende quella automatica; cancella SOLO .env.bak-<14 cifre>, mai il .env
#   percorso-pgdump   imposta SAAS_PG_DUMP e SAAS_PG_RESTORE nella cartella indicata, solo se è la versione 16
set -euo pipefail
ACTION="${1:?azione}"; ARG="${2:-}"
BASE="${SAAS_BASE:-$HOME/saas}"
PHP="${SAAS_PHP:-/usr/local/bin/php}"
ENVFILE="$BASE/shared/.env"
[ -f "$ENVFILE" ] || { echo "Manca $ENVFILE." >&2; exit 1; }

get() { grep -E "^$1=" "$ENVFILE" | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//' -e "s/^'//" -e "s/'\$//" || true; }

# --- rotazione delle copie del .env: inizio (testata in saas/tests/shell/ops-env-bak.sh) ---
# Copie fatte da set_var: <env>.bak-<AAAAMMGGHHMMSS> (UTC). Se ne tengono al massimo 2 (le più recenti) e nessuna oltre 7 giorni:
# l'età si legge dal nome, non da mtime (cp -p conserva la data del .env). Tocca SOLO i file che combaciano con quel nome:
# mai il .env attivo, mai altri file della cartella. Le copie restano 600 (non si ricreano, si cancellano soltanto).
ENV_BAK_KEEP="${ENV_BAK_KEEP:-2}"; ENV_BAK_DAYS="${ENV_BAK_DAYS:-7}"
ROTATION_FLAG="$BASE/shared/.env-rotazione-copie"
env_bak_prune() { # list|delete: stampa i percorsi da cancellare e, con delete, li cancella
  local mode="$1" cutoff i=0 n f ts files=()
  cutoff="$(date -u -d "$ENV_BAK_DAYS days ago" +%Y%m%d%H%M%S)"
  for f in "$ENVFILE".bak-*; do
    ts="${f##*.bak-}"
    [ -f "$f" ] && [ ! -L "$f" ] && [[ "$ts" =~ ^[0-9]{14}$ ]] && files+=("$f")
  done
  for ((n = ${#files[@]} - 1; n >= 0; n--)); do  # dalla più recente (il nome si ordina come la data)
    f="${files[$n]}"; ts="${f##*.bak-}"; i=$((i + 1))
    if [ "$i" -gt "$ENV_BAK_KEEP" ] || [ "$ts" -lt "$cutoff" ]; then
      if [ "$mode" = delete ]; then rm -f -- "$f"; fi
      echo "$f"
    fi
  done
  return 0
}
# --- rotazione delle copie del .env: fine ---

set_var() { # nome valore: sostituisce la riga o la aggiunge; il file resta 600
  local name="$1" value="$2" tmp
  cp -p "$ENVFILE" "$ENVFILE.bak-$(date -u +%Y%m%d%H%M%S)"
  # Rotazione automatica solo dopo l'azione pulisci-copie-env (decisione del titolare): prima non cancella nulla.
  if [ -f "$ROTATION_FLAG" ]; then env_bak_prune delete >/dev/null; fi
  tmp="$(mktemp "$BASE/shared/.env.XXXXXX")"
  if grep -qE "^$name=" "$ENVFILE"; then
    awk -v n="$name" -v v="$value" 'BEGIN{FS=OFS="="} $1==n {print n "=" v; next} {print}' "$ENVFILE" > "$tmp"
  else
    cat "$ENVFILE" > "$tmp"; printf '%s=%s\n' "$name" "$value" >> "$tmp"
  fi
  chmod 600 "$tmp"; mv "$tmp" "$ENVFILE"
}

# Uscite con dettagli del server (versioni, impostazioni, ruoli, indirizzi): il log del workflow è pubblico, quindi finiscono
# in un file in shared/ con permessi 600 (umask 077) e nel log compare solo il percorso. Al massimo 5 file per azione.
newfile() { # prefisso: crea il file vuoto (600) e ne stampa il percorso
  local f="$BASE/shared/$1-$(date -u +%Y%m%dT%H%M%S).txt"
  ( umask 077; : > "$f" ); chmod 600 "$f"; echo "$f"
}
prune_files() { # prefisso: tiene i 5 file più recenti
  ls -1t "$BASE"/shared/"$1"-*.txt 2>/dev/null | tail -n +6 | while read -r old; do rm -f "$old"; done
}
onoff() { case "$1" in 1|true|on|yes) echo acceso ;; "") echo "non impostato" ;; *) echo spento ;; esac; }

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
    for v in SAAS_PG_DUMP SAAS_PG_RESTORE; do
      [ -n "$(get "$v")" ] && echo "$v = impostata" || echo "$v = VUOTA"
    done
    # Percorsi, versioni dei client e righe del log vanno su file (il log del workflow è pubblico).
    F="$(newfile diagnosi)"
    {
      echo "SAAS_PG_DUMP = $(get SAAS_PG_DUMP)"; echo "SAAS_PG_RESTORE = $(get SAAS_PG_RESTORE)"
      echo "== Client PostgreSQL sul server =="
      for p in /usr/pgsql-*/bin/pg_dump /usr/lib/postgresql/*/bin/pg_dump /usr/local/pgsql*/bin/pg_dump "$HOME"/*/bin/pg_dump "$HOME"/bin/pg_dump "$(command -v pg_dump || true)"; do
        [ -n "$p" ] && [ -x "$p" ] && echo "$p -> $("$p" --version 2>&1 | head -1)"
      done
      echo "== Ultimi errori del log =="
      grep -hE "ERROR|CRITICAL" "$BASE/shared/storage/logs/"*.log 2>/dev/null | tail -8 | cut -c1-300 || true
    } > "$F" 2>&1 || true
    prune_files diagnosi
    echo "Client PostgreSQL e ultimi errori: uscita salvata in $F"
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
    # Gli elenchi (identificativi, partizioni) vanno su file; nel log solo esiti e stato acceso/spento.
    cd "$BASE/current"
    F="$(newfile ciclo-vita)"
    {
      echo "== Cancellazione dati (solo elenco) =="; "$PHP" artisan saas:portal:purge-expired --dry-run || true
      echo "== Registro: partizioni da cancellare (solo elenco) =="; "$PHP" artisan saas:audit:prune --dry-run || true
    } > "$F" 2>&1 || true
    prune_files ciclo-vita
    echo "Elenchi di cancellazione e potatura: uscita salvata in $F"
    echo "== Registro: verifica delle catene =="; "$PHP" artisan saas:audit:verify 2>/dev/null | tail -5 || true
    echo "== Tabelle nuove =="
    "$PHP" artisan tinker --execute='$m = 0; foreach (["core.org_notices", "audit.anchors"] as $t) { if (! DB::connection("pgsql")->selectOne("select to_regclass(?) is not null as ok", [$t])->ok) { $m++; } } echo $m === 0 ? "tabelle nuove: ok" : "tabelle nuove: ne mancano ".$m, PHP_EOL;' 2>/dev/null || true
    echo "== Interruttori (devono essere spenti) =="
    for v in SAAS_PURGE_ENABLED SAAS_NOTICES_ENABLED SAAS_AUDIT_PRUNE_ENABLED; do echo "$v: $(onoff "$(get "$v")")"; done
    ;;
  prova-cancellazione)
    # Crea un'associazione di prova, la cancella con il servizio vero e controlla che le altre restino identiche; poi verifica le catene.
    cd "$BASE/current"
    F="$(newfile prova-cancellazione)"; rc=0
    "$PHP" artisan saas:selftest:purge > "$F" 2>&1 || rc=$?
    prune_files prova-cancellazione
    [ "$rc" -eq 0 ] && echo "Prova di cancellazione: ok." || echo "Prova di cancellazione: FALLITA."
    echo "Uscita completa salvata in $F"
    echo "== Registro: verifica delle catene =="; "$PHP" artisan saas:audit:verify 2>/dev/null | tail -5 || true
    exit "$rc"
    ;;
  avvisa-catalogo)
    # Rimanda al portale l'avviso firmato «catalogo cambiato» per la revisione in vigore (non cambia il catalogo).
    cd "$BASE/current"
    F="$(newfile avvisa-catalogo)"; rc=0
    "$PHP" artisan saas:catalog:notify-portal > "$F" 2>&1 || rc=$?
    prune_files avvisa-catalogo
    [ "$rc" -eq 0 ] && echo "Avviso del catalogo: ok." || echo "Avviso del catalogo: FALLITO."
    echo "Uscita completa salvata in $F"
    exit "$rc"
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
    F="$(newfile bozza-listino)"; rc=0
    "$PHP" artisan saas:catalog:prepare-tiers "$EMAIL" > "$F" 2>&1 || rc=$?
    prune_files bozza-listino
    [ "$rc" -eq 0 ] && echo "Bozza del listino: ok." || echo "Bozza del listino: FALLITA."
    echo "Uscita completa salvata in $F"
    exit "$rc"
    ;;
  accendi-ciclo-vita)
    # Accende cancellazione notturna, avvisi email e potatura del registro (decisione scritta della titolare, 13/11/2026).
    for v in SAAS_PURGE_ENABLED SAAS_NOTICES_ENABLED SAAS_AUDIT_PRUNE_ENABLED; do set_var "$v" 1; done
    refresh
    for v in SAAS_PURGE_ENABLED SAAS_NOTICES_ENABLED SAAS_AUDIT_PRUNE_ENABLED; do echo "$v: $(onoff "$(get "$v")")"; done
    ;;
  spegni-cancellazione)
    # Spegne solo la cancellazione notturna dei dati scaduti (avvisi e potatura del registro restano come sono).
    set_var SAAS_PURGE_ENABLED 0
    refresh
    echo "SAAS_PURGE_ENABLED: $(onoff "$(get SAAS_PURGE_ENABLED)")"
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
    # L elenco per associazione va su file; nel log solo i conteggi.
    cd "$BASE/current"
    F="$(newfile conta-fotografie)"
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
    ' > "$F" 2>&1 || true
    prune_files conta-fotografie
    echo "Associazioni con fotografie: $(grep -c ' fotografie=' "$F" || true)"
    echo "di cui senza chiave: $(grep -c 'chiave=NO' "$F" || true)"
    echo "di cui già cancellate: $(grep -c 'cancellata=SI' "$F" || true)"
    echo "Elenco per associazione: uscita salvata in $F"
    ;;
  cancellazioni)
    # Sola lettura (decisione del titolare, 16/11/2026): com'è andata la cancellazione notturna e che cosa resta delle associazioni
    # sospese, chiuse o cancellate. Nessun nome né slug: l uscita finisce nel log pubblico del workflow, solo identificativi tecnici e conteggi. Nessuna scrittura.
    # L uscita completa (righe del registro, stato per associazione, log) va su file; nel log solo i conteggi.
    cd "$BASE/current"
    F="$(newfile cancellazioni)"
    {
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
    } > "$F" 2>&1 || true
    prune_files cancellazioni
    echo "Cancellazioni eseguite: $(grep -c ' org.purged ' "$F" || true)"
    echo "Cancellazioni fallite o fermate: $(grep -cE ' org.purge_(failed|stopped) ' "$F" || true)"
    echo "Associazioni sospese, chiuse o cancellate: $(grep -c ' stato=' "$F" || true)"
    echo "Dettaglio: uscita salvata in $F"
    ;;
  permessi-backup)
    # Sola lettura: privilegi del ruolo dei backup fuori da SELECT. Atteso: nessuna riga.
    # Ruolo ed elenco dei privilegi vanno su file; nel log solo il conteggio.
    cd "$BASE/current"
    F="$(newfile permessi-backup)"
    "$PHP" artisan tinker --execute='
      $role = DB::connection("pgsql_owner")->getConfig("backup_role");
      $rows = DB::connection("pgsql_owner")->select("SELECT table_schema, table_name, privilege_type FROM information_schema.role_table_grants WHERE grantee = ? AND privilege_type <> ? ORDER BY 1, 2, 3", [$role, "SELECT"]);
      echo "Ruolo dei backup: ", $role, PHP_EOL, "Privilegi oltre SELECT: ", count($rows), PHP_EOL;
      foreach ($rows as $r) { echo $r->table_schema, ".", $r->table_name, " ", $r->privilege_type, PHP_EOL; }
    ' > "$F" 2>&1 || true
    prune_files permessi-backup
    grep '^Privilegi oltre SELECT' "$F" || echo "Privilegi oltre SELECT: non disponibile"
    echo "Dettaglio: uscita salvata in $F"
    ;;
  verifica-registro)
    # Sola lettura: verifica la catena del registro delle azioni di tutte le associazioni e stampa l'esito (nessun dato personale).
    cd "$BASE/current"
    "$PHP" artisan saas:audit:verify --full || true
    ;;
  indici-registro)
    # Sola lettura, con il ruolo dei backup: letture di ogni indice del registro delle azioni, sommate sulle partizioni,
    # per ogni database, con la data dell'ultimo azzeramento delle statistiche (H28: conferma che log_org_subject non
    # lo legge nessuno prima di toglierlo). I nomi dei database vanno su file; nel log solo l'esito.
    cd "$BASE/current"
    F="$(newfile indici-registro)"; rc=0
    "$PHP" artisan saas:audit:index-usage > "$F" 2>&1 || rc=$?
    prune_files indici-registro
    [ "$rc" -eq 0 ] && echo "Indici del registro: letti su tutti i database." || echo "Indici del registro: ERRORE su qualche database."
    echo "Letture per indice: uscita salvata in $F"
    exit "$rc"
    ;;
  formato-dry-run)
    # Sola lettura: prova del passaggio dei campi cifrati al formato legato alla riga (0x02). Non scrive nulla e stampa
    # solo conteggi per associazione e tabella; esce con errore se qualche campo non si decifra. Il passaggio vero
    # (saas:crypt:upgrade-format senza --dry-run) NON è tra queste azioni.
    cd "$BASE/current"
    F="$(newfile formato-dry-run)"; rc=0
    "$PHP" artisan saas:crypt:upgrade-format --dry-run > "$F" 2>&1 || rc=$?
    prune_files formato-dry-run
    [ "$rc" -eq 0 ] && echo "Prova del formato: ok (nessun campo non decifrabile)." || echo "Prova del formato: ERRORE (qualche campo non si decifra)."
    echo "Conteggi per associazione e tabella: uscita salvata in $F"
    exit "$rc"
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
      if [ -z "$val" ]; then echo "$v = (non impostato: vale il predefinito)"
      elif [[ "$val" =~ ^(0|1|true|false|on|off|yes|no|local|remote)$ ]]; then echo "$v = $val"
      else echo "$v = impostato"; fi
    done
    ;;
  sblocca-utente)
    # Sblocco dal supporto (decisione scritta del titolare, 16/11/2026): toglie la verifica in due passaggi a chi lavora
    # nel gestionale e ha finito le richieste per email; azzera i conteggi, annota l'evento e avvisa la persona. L'email è il terzo argomento.
    EMAIL="${3:-}"
    [[ "$EMAIL" =~ ^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$ ]] || { echo "Email non valida." >&2; exit 1; }
    cd "$BASE/current"
    F="$(newfile sblocca-utente)"; rc=0
    "$PHP" artisan saas:user:reset-2fa "$EMAIL" > "$F" 2>&1 || rc=$?
    prune_files sblocca-utente
    [ "$rc" -eq 0 ] && echo "Sblocco eseguito." || echo "Sblocco FALLITO."
    echo "Uscita completa salvata in $F"
    exit "$rc"
    ;;
  spegni-avvisi)
    # Spegne gli avvisi email del gestionale (restano solo le email del portale) e dice se ne sono già partiti.
    set_var SAAS_NOTICES_ENABLED 0
    refresh
    echo "SAAS_NOTICES_ENABLED: $(onoff "$(get SAAS_NOTICES_ENABLED)")"
    cd "$BASE/current"
    "$PHP" artisan tinker --execute='
      $n = DB::connection("pgsql_owner")->table("core.org_notices")->get(["kind", "sent_at"]);
      echo "Avvisi registrati: ", $n->count(), PHP_EOL;
      foreach ($n->groupBy("kind") as $k => $rows) { echo $k, ": ", $rows->count(), PHP_EOL; }
    ' || true
    ;;
  percorso-pgdump)
    [[ "$ARG" =~ ^/[A-Za-z0-9_./-]+$ ]] || { echo "Cartella non valida." >&2; exit 1; }
    [ -x "$ARG/pg_dump" ] && [ -x "$ARG/pg_restore" ] || { echo "Nella cartella indicata mancano pg_dump o pg_restore." >&2; exit 1; }
    "$ARG/pg_dump" --version | grep -qE ' 16\.' || { echo "pg_dump nella cartella indicata non è la versione 16." >&2; exit 1; }
    set_var SAAS_PG_DUMP "$ARG/pg_dump"; set_var SAAS_PG_RESTORE "$ARG/pg_restore"
    echo "Impostati SAAS_PG_DUMP e SAAS_PG_RESTORE (versione 16)."
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
    echo "SAAS_TRUSTED_PROXIES: $([ -n "$(get SAAS_TRUSTED_PROXIES)" ] && echo impostata || echo vuota) (con il segreto non si usa più: si può svuotare dopo la prova)"
    ;;
  copie-env)
    # Sola lettura: nessuna cancellazione. Mostra cosa farebbe la rotazione (solo nomi, niente contenuti).
    echo "Rotazione automatica: $([ -f "$ROTATION_FLAG" ] && echo accesa || echo "spenta (non cancella nulla da sola)")"
    echo "Cancellerebbe (massimo $ENV_BAK_KEEP copie, nessuna oltre $ENV_BAK_DAYS giorni):"
    env_bak_prune list | sed -e 's#.*/#  #'
    ;;
  pulisci-copie-env)
    # Applica la rotazione una volta e accende quella automatica. Da lanciare solo dopo il via del titolare.
    n="$(env_bak_prune delete | wc -l)"
    ( umask 077; : > "$ROTATION_FLAG" )
    echo "Copie cancellate: $n. Rotazione automatica accesa (massimo $ENV_BAK_KEEP copie, nessuna oltre $ENV_BAK_DAYS giorni)."
    ;;
  permessi-db)
    # Riduce i permessi del ruolo applicativo. Prima salva i permessi attuali in shared/grants-backup-<data>.sql
    # (ripristino: azione ripristina-permessi); poi toglie CREATE sugli schemi, TRUNCATE/REFERENCES/TRIGGER sulle tabelle e la lettura di public.migrations.
    cd "$BASE/current"
    F="$(newfile permessi-db)"
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
      echo "Permessi attuali salvati in shared/ (", count($lines), " righe)", PHP_EOL;
      foreach (["public", "app", "audit", "sites", "core"] as $schema) { $db->statement("REVOKE CREATE ON SCHEMA {$schema} FROM {$r}"); }
      foreach (["app", "audit", "sites", "core"] as $schema) { $db->statement("REVOKE TRUNCATE, REFERENCES, TRIGGER ON ALL TABLES IN SCHEMA {$schema} FROM {$r}"); }
      if ($db->selectOne("select to_regclass(?) as t", ["public.migrations"])->t !== null) { $db->statement("REVOKE ALL ON public.migrations FROM {$r}"); }
      echo "Permessi ridotti per il ruolo applicativo", PHP_EOL;
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
        echo "NOINHERIT annullato: l applicazione non leggeva piu le sue tabelle", PHP_EOL;
      }
    ' 2>>"$F"
    echo "== Controllo =="
    "$PHP" artisan saas:health --skip-migrations 2>&1 | grep -E "permessi|ruolo|ERRORE|AVVISO" >> "$F" || true
    prune_files permessi-db
    echo "Righe con ERRORE: $(grep -c 'ERRORE' "$F" || true)"
    echo "Righe con AVVISO: $(grep -c 'AVVISO' "$F" || true)"
    echo "Dettaglio del controllo (e di eventuali errori): uscita salvata in $F"
    ;;
  svuota-proxy-fidati)
    # Solo con SAAS_PROXY_SECRET impostata: l'elenco dei proxy fidati non serve più (conta il segreto condiviso).
    [ -n "$(get SAAS_PROXY_SECRET)" ] || { echo "SAAS_PROXY_SECRET è vuota: non svuoto l'elenco." >&2; exit 1; }
    set_var SAAS_TRUSTED_PROXIES ""
    refresh
    echo "SAAS_TRUSTED_PROXIES: $([ -n "$(get SAAS_TRUSTED_PROXIES)" ] && echo impostata || echo vuota)"
    ;;
  prova-recupero)
    # Prova che sull'hosting Argon2id con i parametri della copia di recupero funziona (memoria, tempo): cifra e riapre in memoria
    # un contenuto di prova con una frase di prova. Non scrive file, non tocca chiavi vere, non stampa segreti.
    cd "$BASE/current"
    F="$(newfile prova-recupero)"
    "$PHP" artisan tinker --execute='
      $t = microtime(true);
      $frase = "frase di prova soltanto per questa verifica";
      $sealed = App\Modules\Cifratura\Keys\RecoveryFile::seal(["service_url" => "https://prova.invalid"], $frase);
      $open = App\Modules\Cifratura\Keys\RecoveryFile::open($sealed, $frase);
      $ok = ($open["items"]["service_url"] ?? null) === "https://prova.invalid";
      echo "Cifratura e riapertura di prova: ", $ok ? "riuscite" : "FALLITE", " in ", round(microtime(true) - $t, 2), " s", PHP_EOL;
      echo "memoria: memory_limit PHP = ", ini_get("memory_limit"), "; picco ", round(memory_get_peak_usage(true) / 1048576), " MiB", PHP_EOL;
      try { App\Modules\Cifratura\Keys\RecoveryFile::open($sealed, "altra frase sbagliata di prova"); echo "ERRORE: frase sbagliata accettata", PHP_EOL; } catch (Throwable $e) { echo "Frase sbagliata rifiutata come previsto", PHP_EOL; }
    ' > "$F" 2>&1 || true
    prune_files prova-recupero
    grep -v '^memoria:' "$F" || true
    echo "Dettaglio (memoria): uscita salvata in $F"
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
      echo "richieste contate per l indirizzo indicato = ", max(Illuminate\Support\Facades\RateLimiter::attempts(md5("staff-api".$k)), Illuminate\Support\Facades\RateLimiter::attempts("staff-api".$k)), PHP_EOL;
    '
    ;;
  permessi-diagnosi)
    # Sola lettura: da dove arrivano i permessi (ACL di uno schema e di una tabella, ruoli di cui fa parte il ruolo applicativo).
    # Tutta l uscita va su file (ruoli, ACL): nel log solo il percorso.
    cd "$BASE/current"
    F="$(newfile permessi-diagnosi)"
    "$PHP" artisan tinker --execute='
      $db = DB::connection("pgsql_owner");
      $role = DB::connection("pgsql")->selectOne("select current_user as u")->u;
      echo "ruolo: ", $role, PHP_EOL;
      foreach ($db->select("select p.rolname from pg_auth_members m join pg_roles r on r.oid = m.member join pg_roles p on p.oid = m.roleid where r.rolname = ?", [$role]) as $x) { echo "membro di: ", $x->rolname, PHP_EOL; }
      foreach ($db->select("select nspname, nspacl::text as acl from pg_namespace where nspname in (?, ?, ?, ?, ?)", ["public", "app", "audit", "sites", "core"]) as $x) { echo "schema ", $x->nspname, ": ", $x->acl, PHP_EOL; }
      foreach ($db->select("select n.nspname || ? || c.relname as t, c.relacl::text as acl from pg_class c join pg_namespace n on n.oid = c.relnamespace where n.nspname = ? and c.relkind = ? limit 2", [".", "app", "r"]) as $x) { echo $x->t, ": ", $x->acl, PHP_EOL; }
      foreach ($db->select("select pg_get_userbyid(defaclrole) as chi, defaclnamespace::regnamespace::text as schema, defaclobjtype as tipo, defaclacl::text as acl from pg_default_acl") as $x) { echo "default: ", $x->chi, " ", $x->schema, " ", $x->tipo, " ", $x->acl, PHP_EOL; }
    ' > "$F" 2>&1 || true
    prune_files permessi-diagnosi
    echo "permessi-diagnosi salvata in $F"
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
      echo "Ripristinate ", $n, " concessioni dall ultimo file di copia", PHP_EOL;
    '
    ;;
  misure)
    # Sola lettura (H1): solo numeri e impostazioni, mai dati delle associazioni né segreti (niente indirizzi, utenti o
    # nomi dei database: i ruoli compaiono come app/owner/altro). Ogni sezione è a sé: se un dato non c'è o manca un
    # permesso scrive «non disponibile» e va avanti. Le tabelle di sistema pg_stat_* si leggono con il ruolo proprietario
    # (pgsql_owner, quello delle migrazioni); se non si apre si ripiega sul ruolo dell'app (pgsql).
    # Tutta l uscita va su file in shared/ (600): nel log solo il percorso.
    cd "$BASE/current"
    F="$(newfile misure)"
    "$PHP" artisan tinker --execute='
      $sec = function (string $title, Closure $f): void {
        echo PHP_EOL, "== ", $title, " ==", PHP_EOL;
        try { $f(); } catch (Throwable $e) { echo "non disponibile (", get_class($e), ")", PHP_EOL; }
      };
      $mb = fn ($b): string => round(((float) $b) / 1048576, 1) . " MB";
      $db = null; $dbName = "";
      foreach (["pgsql_owner", "pgsql"] as $n) {
        try { $c = DB::connection($n); $c->select("select 1"); $db = $c; $dbName = $n; break; } catch (Throwable $e) {}
      }
      $role = function (?string $u): string {
        foreach (["pgsql" => "app", "pgsql_owner" => "owner"] as $n => $label) { if ($u !== null && $u === config("database.connections." . $n . ".username")) { return $label; } }
        return "altro";
      };

      $sec("PHP (riga di comando)", function () {
        echo "versione: ", PHP_VERSION, "  sapi: ", PHP_SAPI, PHP_EOL;
        foreach (["memory_limit", "realpath_cache_size", "realpath_cache_ttl", "max_execution_time"] as $k) { echo $k, " = ", var_export(ini_get($k), true), PHP_EOL; }
        foreach (["opcache", "apcu"] as $ext) { echo "estensione ", $ext, ": ", extension_loaded($ext) ? "caricata" : "assente", PHP_EOL; }
        foreach (["opcache.enable", "opcache.enable_cli", "opcache.memory_consumption", "opcache.max_accelerated_files", "opcache.validate_timestamps", "opcache.revalidate_freq", "opcache.jit", "opcache.interned_strings_buffer", "apc.enabled", "apc.shm_size", "apc.enable_cli"] as $k) {
          $v = ini_get($k);
          echo $k, " = ", $v === false ? "non disponibile" : $v, PHP_EOL;
        }
      });

      $sec("PostgreSQL: impostazioni (connessione " . ($dbName ?: "nessuna") . ")", function () use ($db) {
        foreach ($db->select("select name, setting, unit from pg_settings where name in (?,?,?,?,?,?,?,?,?,?,?,?) order by name", ["server_version", "max_connections", "shared_buffers", "work_mem", "effective_cache_size", "random_page_cost", "jit", "ssl", "autovacuum", "maintenance_work_mem", "max_wal_size", "track_activity_query_size"]) as $r) {
          echo $r->name, " = ", $r->setting, $r->unit ? " " . $r->unit : "", PHP_EOL;
        }
      });

      $sec("PostgreSQL: connessioni in uso per ruolo (solo conteggi)", function () use ($db, $role) {
        $tot = 0;
        foreach ($db->select("select usename, coalesce(state, ?) as state, count(*) as n from pg_stat_activity where datname = current_database() group by 1, 2 order by 3 desc", ["senza stato"]) as $r) { echo $role($r->usename), " / ", $r->state, ": ", $r->n, PHP_EOL; $tot += (int) $r->n; }
        echo "totale su questo database: ", $tot, PHP_EOL;
        echo "totale sul server: ", $db->selectOne("select count(*) as n from pg_stat_activity")->n, PHP_EOL;
      });

      $sec("PostgreSQL: dimensione delle tabelle (prime 25)", function () use ($db, $mb) {
        foreach ($db->select("select n.nspname as s, c.relname as t, pg_total_relation_size(c.oid) as tot, pg_relation_size(c.oid) as tab, pg_indexes_size(c.oid) as idx from pg_class c join pg_namespace n on n.oid = c.relnamespace where c.relkind in (?, ?) and n.nspname not in (?, ?, ?) order by 3 desc limit 25", ["r", "p", "pg_catalog", "information_schema", "pg_toast"]) as $r) {
          echo $r->s, ".", $r->t, ": totale ", $mb($r->tot), ", tabella ", $mb($r->tab), ", indici ", $mb($r->idx), PHP_EOL;
        }
      });

      $sec("PostgreSQL: righe vive e morte, ultimo autovacuum (prime 25 per righe)", function () use ($db) {
        foreach ($db->select("select schemaname as s, relname as t, n_live_tup as live, n_dead_tup as dead, last_autovacuum as av, last_autoanalyze as aa, autovacuum_count as avn from pg_stat_user_tables order by n_live_tup desc limit 25") as $r) {
          echo $r->s, ".", $r->t, ": vive ", $r->live, ", morte ", $r->dead, ", autovacuum ", $r->av ?? "mai", " (", $r->avn, "), analyze ", $r->aa ?? "mai", PHP_EOL;
        }
      });

      $sec("PostgreSQL: indici (i 40 piu grandi; idx_scan = letture dall ultimo azzeramento delle statistiche)", function () use ($db, $mb) {
        echo "statistiche azzerate il: ", $db->selectOne("select coalesce(stats_reset::text, ?) as d from pg_stat_database where datname = current_database()", ["mai"])->d, PHP_EOL;
        foreach ($db->select("select schemaname as s, relname as t, indexrelname as i, idx_scan as n, pg_relation_size(indexrelid) as b from pg_stat_user_indexes order by 5 desc limit 40") as $r) {
          echo $r->s, ".", $r->i, " su ", $r->t, ": idx_scan ", $r->n, ", ", $mb($r->b), $r->n == 0 ? "  <-- mai letto" : "", PHP_EOL;
        }
      });

      $sec("PostgreSQL: pg_stat_statements (prime 10 per tempo totale, solo SELECT/INSERT/UPDATE/DELETE/WITH)", function () use ($db) {
        if (! $db->selectOne("select 1 as x from pg_extension where extname = ?", ["pg_stat_statements"])) { echo "estensione non installata", PHP_EOL; return; }
        foreach ($db->select("select calls, round(total_exec_time::numeric, 1) as tot, round(mean_exec_time::numeric, 2) as mean, rows, left(regexp_replace(query, ?, ?, ?), 110) as q from pg_stat_statements where dbid = (select oid from pg_database where datname = current_database()) and query ~* ? order by total_exec_time desc limit 10", ["\\s+", " ", "g", "^(select|insert|update|delete|with)"]) as $r) {
          echo "chiamate ", $r->calls, ", totale ", $r->tot, " ms, medio ", $r->mean, " ms, righe ", $r->rows, ": ", $r->q, PHP_EOL;
        }
      });

      $sec("Apertura di una connessione (10 aperture, media)", function () {
        $cfg = config("database.connections.pgsql");
        $host = (string) ($cfg["host"] ?? "");
        $port = (string) ($cfg["port"] ?? "5432");
        $kind = $host !== "" && $host[0] === "/" ? "socket" : (in_array($host, ["127.0.0.1", "localhost", "::1"], true) ? "locale" : "remoto");
        echo "database: ", $kind, ", sslmode configurato: ", $cfg["sslmode"] ?? "prefer", PHP_EOL;
        $time = function (string $label, string $dsnHost, string $ssl) use ($cfg, $port): void {
          $ms = [];
          try {
            for ($i = 0; $i < 10; $i++) {
              $t = hrtime(true);
              $pdo = new PDO("pgsql:host=" . $dsnHost . ";port=" . $port . ";dbname=" . $cfg["database"] . ($ssl !== "" ? ";sslmode=" . $ssl : ""), $cfg["username"], $cfg["password"], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
              $pdo->query("select 1");
              $ms[] = (hrtime(true) - $t) / 1e6;
              $pdo = null;
            }
            echo $label, ": media ", round(array_sum($ms) / count($ms), 2), " ms (min ", round(min($ms), 2), ", max ", round(max($ms), 2), ")", PHP_EOL;
          } catch (Throwable $e) { echo $label, ": non disponibile (", get_class($e), ")", PHP_EOL; }
        };
        if ($kind === "socket") {
          $time("socket locale", $host, "");
        } else {
          $time("TCP come configurato", $host, (string) ($cfg["sslmode"] ?? "prefer"));
          if ($kind === "locale") { $time("TCP senza TLS (sslmode=disable)", $host, "disable"); }
          $found = false;
          foreach (["/var/run/postgresql", "/run/postgresql", "/tmp"] as $dir) {
            if (@file_exists($dir . "/.s.PGSQL." . $port)) { $time("socket locale", $dir, ""); $found = true; break; }
          }
          if (! $found) { echo "socket locale: non trovato nelle cartelle comuni", PHP_EOL; }
        }
      });

      $sec("Redis", function () {
        $uses = [config("cache.default"), config("queue.default"), config("session.driver")];
        echo "usato da cache/coda/sessione: ", in_array("redis", $uses, true) ? "si" : "no", PHP_EOL;
        if (! in_array("redis", $uses, true) && empty(config("database.redis.default.host"))) { echo "non configurato", PHP_EOL; return; }
        $con = Illuminate\Support\Facades\Redis::connection();
        $info = $con->info();
        $flat = [];
        array_walk_recursive($info, function ($v, $k) use (&$flat) { $flat[$k] = $v; });
        foreach (["used_memory", "maxmemory", "maxmemory_policy", "connected_clients"] as $k) { echo $k, " = ", $flat[$k] ?? "n.d.", PHP_EOL; }
        echo "chiavi (dbsize) = ", $con->dbsize(), PHP_EOL;
      });

      $sec("Coda (core.jobs)", function () use ($db) {
        $r = $db->selectOne("select count(*) as n, count(*) filter (where reserved_at is null) as waiting, min(created_at) as oldest from core.jobs");
        echo "righe in core.jobs: ", $r->n, " (in attesa ", $r->waiting, ")", PHP_EOL;
        echo "la piu vecchia da (minuti): ", $r->oldest === null ? "coda vuota" : max(0, intdiv(time() - (int) $r->oldest, 60)), PHP_EOL;
      });
    ' > "$F" 2>&1 || true
    prune_files misure
    echo "misure salvate in $F"
    ;;
  db-connection-limit-60|db-connection-limit-off)
    # Limite di connessioni del database del gestionale (ALTER DATABASE ... CONNECTION LIMIT), col ruolo proprietario
    # (pgsql_owner, quello delle migrazioni). Il limite vale per ogni ruolo non superutente di QUESTO database; gli altri
    # database (per associazione, supporto) non cambiano. random_page_cost e work_mem non si toccano.
    #   db-connection-limit-60   prima misura il picco (20 campioni, uno al secondo, tutti i ruoli, comprese queste
    #                            connessioni) e rifiuta se picco + margine di 10 (backup, ripristino, migrazioni al rilascio) supera 60
    #   db-connection-limit-off  comando inverso: CONNECTION LIMIT -1 (nessun limite)
    # Nel log solo esito e numeri; la ripartizione per ruolo va su file in shared/ (600).
    cd "$BASE/current"
    F="$(newfile limite-connessioni)"
    if [ "$ACTION" = "db-connection-limit-60" ]; then LIMIT=60; else LIMIT=-1; fi
    rc=0
    LIMIT="$LIMIT" MARGIN=10 "$PHP" artisan tinker --execute='
      $limit = (int) getenv("LIMIT"); $margin = (int) getenv("MARGIN");
      $db = DB::connection("pgsql_owner");
      $name = $db->selectOne("select current_database() as d")->d;
      $q = "\"".str_replace("\"", "\"\"", $name)."\"";
      $label = function (?string $u): string {
        foreach (["pgsql" => "app", "pgsql_owner" => "owner"] as $n => $l) { if ($u !== null && $u === config("database.connections." . $n . ".username")) { return $l; } }
        return "altro";
      };
      $before = (int) $db->selectOne("select datconnlimit as l from pg_database where datname = current_database()")->l;
      echo "limite prima: ", $before, PHP_EOL;
      echo "max_connections del server: ", $db->selectOne("select setting from pg_settings where name = ?", ["max_connections"])->setting, PHP_EOL;
      if ($limit > 0) {
        $peak = 0; $byRole = [];
        for ($i = 0; $i < 20; $i++) {
          $n = 0;
          foreach ($db->select("select usename, count(*) as n from pg_stat_activity where datname = current_database() group by 1") as $r) {
            $n += (int) $r->n; $k = $label($r->usename); $byRole[$k] = max($byRole[$k] ?? 0, (int) $r->n);
          }
          $peak = max($peak, $n);
          if ($i < 19) { sleep(1); }
        }
        foreach ($byRole as $k => $v) { echo "picco per ruolo ", $k, ": ", $v, PHP_EOL; }
        echo "picco misurato: ", $peak, " (20 campioni in 19 secondi), margine: ", $margin, PHP_EOL;
        if ($peak + $margin > $limit) { echo "RIFIUTATO: picco + margine supera ", $limit, PHP_EOL; echo "esito: rifiutato", PHP_EOL; exit(3); }
      }
      $db->statement("ALTER DATABASE {$q} CONNECTION LIMIT {$limit}");
      $after = (int) $db->selectOne("select datconnlimit as l from pg_database where datname = current_database()")->l;
      echo "limite dopo: ", $after, PHP_EOL;
      echo $after === $limit ? "esito: ok" : "esito: errore", PHP_EOL;
      exit($after === $limit ? 0 : 1);
    ' > "$F" 2>&1 || true
    # Il codice d uscita di tinker non e affidabile (psysh esce con 1 anche dopo exit(0)): l esito si legge dalla riga
    # marcatore finale scritta dal PHP. Senza marcatore (eccezione, connessione rifiutata) e ERRORE.
    if grep -qx 'esito: ok' "$F"; then rc=0
    elif grep -qx 'esito: rifiutato' "$F"; then rc=3
    else rc=1; fi
    prune_files limite-connessioni
    grep -E '^(picco misurato|limite prima|limite dopo|RIFIUTATO)' "$F" | sed -E 's/ \(20 campioni.*//' || true
    if [ "$rc" -eq 0 ]; then
      if [ "$LIMIT" -eq -1 ]; then echo "limite connessioni: nessuno"; else echo "limite connessioni: $LIMIT"; fi
    elif [ "$rc" -eq 3 ]; then echo "limite connessioni: NON applicato (picco troppo alto)"
    else echo "limite connessioni: ERRORE"; fi
    echo "Dettaglio (per ruolo, eventuali errori): uscita salvata in $F"
    exit "$rc"
    ;;
  *) echo "Azione sconosciuta: $ACTION" >&2; exit 1 ;;
esac
