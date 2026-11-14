<?php

declare(strict_types=1);

use App\Modules\Core\Contracts\OrgSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Annualità chiuse, ultimi pezzi (docs/ANNUALITA_CHIUSE.md):
 * - fotografia del rendiconto alla chiusura dell'esercizio (solo in aggiunta; alla riapertura la versione vigente si
 *   segna superata, `superseded_at`, e alla nuova chiusura ne nasce un'altra);
 * - voci e conti usati da movimenti di un esercizio chiuso non cambiano nome, natura, raggruppamento, tipo o saldo
 *   di partenza: o si riapre l'esercizio o restano così (ordine, tolta o chiusura del conto restano consentiti).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE app.statement_snapshots (
                org_id        uuid        NOT NULL REFERENCES app.orgs(id),
                id            uuid        NOT NULL,
                fiscal_year   integer     NOT NULL,
                version       integer     NOT NULL CHECK (version >= 1),
                starts_on     date        NOT NULL,
                ends_on       date        NOT NULL,
                payload       jsonb       NOT NULL,
                created_by    uuid,
                created_at    timestamptz NOT NULL DEFAULT now(),
                superseded_at timestamptz,
                PRIMARY KEY (org_id, id),
                UNIQUE (org_id, fiscal_year, version),
                FOREIGN KEY (org_id, fiscal_year) REFERENCES app.fiscal_years(org_id, year)
            )
        SQL);
        OrgSchema::isolate('app.statement_snapshots');
        OrgSchema::grant('app.statement_snapshots', ['SELECT', 'INSERT']);
        DB::statement('GRANT UPDATE (superseded_at) ON app.statement_snapshots TO '.OrgSchema::appRole());

        DB::unprepared(<<<'SQL'
            CREATE FUNCTION app.category_closed_year_guard() RETURNS trigger
            LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
            BEGIN
                IF (NEW.name, NEW.kind, NEW.fiscal_group) IS DISTINCT FROM (OLD.name, OLD.kind, OLD.fiscal_group)
                   AND EXISTS (
                       SELECT 1 FROM app.transactions t JOIN app.fiscal_years f ON f.org_id = t.org_id AND f.year = t.fiscal_year
                       WHERE t.org_id = OLD.org_id AND t.category_id = OLD.id AND f.status <> 'open') THEN
                    RAISE EXCEPTION 'La voce «%» è usata in un esercizio chiuso: per modificarla riapri l''esercizio.', OLD.name;
                END IF;
                RETURN NEW;
            END
            $$;
        SQL);
        DB::statement('CREATE TRIGGER categories_closed_year_guard BEFORE UPDATE ON app.categories FOR EACH ROW EXECUTE FUNCTION app.category_closed_year_guard()');

        DB::unprepared(<<<'SQL'
            CREATE FUNCTION app.account_closed_year_guard() RETURNS trigger
            LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
            BEGIN
                IF (NEW.name, NEW.type, NEW.opening_cents) IS DISTINCT FROM (OLD.name, OLD.type, OLD.opening_cents)
                   AND EXISTS (
                       SELECT 1 FROM app.transactions t JOIN app.fiscal_years f ON f.org_id = t.org_id AND f.year = t.fiscal_year
                       WHERE t.org_id = OLD.org_id AND t.account_id = OLD.id AND f.status <> 'open') THEN
                    RAISE EXCEPTION 'Il conto «%» ha movimenti in un esercizio chiuso: per modificarlo riapri l''esercizio.', OLD.name;
                END IF;
                RETURN NEW;
            END
            $$;
        SQL);
        DB::statement('CREATE TRIGGER accounts_closed_year_guard BEFORE UPDATE ON app.accounts FOR EACH ROW EXECUTE FUNCTION app.account_closed_year_guard()');

        foreach (['category_closed_year_guard()', 'account_closed_year_guard()'] as $function) {
            DB::statement("REVOKE ALL ON FUNCTION app.{$function} FROM PUBLIC");
        }
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER accounts_closed_year_guard ON app.accounts');
        DB::statement('DROP TRIGGER categories_closed_year_guard ON app.categories');
        DB::statement('DROP FUNCTION app.account_closed_year_guard()');
        DB::statement('DROP FUNCTION app.category_closed_year_guard()');
        DB::statement('DROP TABLE app.statement_snapshots');
    }
};
