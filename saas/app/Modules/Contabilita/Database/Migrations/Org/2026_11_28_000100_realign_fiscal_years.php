<?php

declare(strict_types=1);

use App\Modules\Core\Contracts\OrgSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Cambio del mese di inizio dell'anno fiscale, finché nessun anno è stato chiuso (decisione del titolare, 14/11/2026).
 * I movimenti sono immutabili: l'unica modifica concessa è l'anno fiscale a cui appartengono (le date restano), e solo
 * dentro questa funzione, che prima rifà gli anni fiscali dal nuovo mese. Le ricevute non si toccano.
 * - la chiave esterna movimenti → anni fiscali diventa differibile, per rifare gli anni nella stessa transazione;
 * - la guardia dei movimenti lascia passare il solo cambio di anno fiscale, nella transazione della funzione.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            DO $$
            DECLARE c text;
            BEGIN
                SELECT conname INTO c FROM pg_constraint
                WHERE conrelid = 'app.transactions'::regclass AND confrelid = 'app.fiscal_years'::regclass AND contype = 'f';
                EXECUTE format('ALTER TABLE app.transactions DROP CONSTRAINT %I', c);
            END $$;
            ALTER TABLE app.transactions ADD CONSTRAINT transactions_fiscal_year_fk
                FOREIGN KEY (org_id, fiscal_year) REFERENCES app.fiscal_years(org_id, year) DEFERRABLE INITIALLY IMMEDIATE;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION app.transaction_guard() RETURNS trigger
            LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
            DECLARE
                fy app.fiscal_years%ROWTYPE;
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'I movimenti della prima nota non si cancellano: si stornano.';
                END IF;

                SELECT * INTO fy FROM app.fiscal_years WHERE org_id = NEW.org_id AND year = NEW.fiscal_year;
                IF NOT FOUND OR NEW.tx_date < fy.starts_on OR NEW.tx_date > fy.ends_on THEN
                    RAISE EXCEPTION 'La data del movimento non appartiene all''anno fiscale indicato.';
                END IF;
                IF fy.status <> 'open' THEN
                    RAISE EXCEPTION 'L''anno fiscale % è chiuso: per modificarlo va riaperto.', fy.year;
                END IF;

                -- Cambio del mese di inizio dell'anno fiscale (app.realign_fiscal_years): cambia solo l'anno fiscale.
                IF TG_OP = 'UPDATE' AND current_setting('app.realigning', true) = NEW.org_id::text
                   AND (to_jsonb(NEW) - 'fiscal_year') = (to_jsonb(OLD) - 'fiscal_year') THEN
                    RETURN NEW;
                END IF;

                IF TG_OP = 'INSERT' THEN
                    IF NEW.voided_at IS NOT NULL THEN
                        RAISE EXCEPTION 'Un movimento nuovo non può essere già stornato.';
                    END IF;
                    IF EXISTS (SELECT 1 FROM app.accounts WHERE org_id = NEW.org_id AND id = NEW.account_id AND closed_at IS NOT NULL) THEN
                        RAISE EXCEPTION 'Il conto è chiuso: riaprilo per registrare movimenti.';
                    END IF;
                    RETURN NEW;
                END IF;

                -- UPDATE: il centro di costo di un movimento non stornato, oppure lo storno, una volta sola. Nient'altro.
                IF OLD.voided_at IS NULL AND NEW.voided_at IS NULL
                   AND (to_jsonb(NEW) - 'activity_id') = (to_jsonb(OLD) - 'activity_id') THEN
                    IF NEW.type NOT IN ('income', 'expense') THEN
                        RAISE EXCEPTION 'Un giroconto non si assegna a un''attività.';
                    END IF;
                    RETURN NEW;
                END IF;
                IF OLD.voided_at IS NOT NULL OR NEW.voided_at IS NULL
                   OR (to_jsonb(NEW) - 'voided_at' - 'void_reason' - 'voided_by') <> (to_jsonb(OLD) - 'voided_at' - 'void_reason' - 'voided_by') THEN
                    RAISE EXCEPTION 'I movimenti della prima nota non si modificano: si stornano.';
                END IF;
                RETURN NEW;
            END
            $$;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE FUNCTION app.realign_fiscal_years(p_month integer, p_today date) RETURNS integer
            LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
            DECLARE
                org uuid := NULLIF(current_setting('app.org_id', true), '')::uuid;
                moved integer;
            BEGIN
                IF org IS NULL OR p_month < 1 OR p_month > 12 THEN
                    RAISE EXCEPTION 'Mese non valido.';
                END IF;
                IF EXISTS (SELECT 1 FROM app.fiscal_years WHERE org_id = org AND status <> 'open')
                   OR EXISTS (SELECT 1 FROM app.statement_snapshots WHERE org_id = org)
                   OR EXISTS (SELECT 1 FROM app.member_book_snapshots WHERE org_id = org) THEN
                    RAISE EXCEPTION 'Dopo la prima chiusura il mese di inizio dell''anno fiscale non si cambia più.';
                END IF;

                PERFORM set_config('app.realigning', org::text, true);
                SET CONSTRAINTS transactions_fiscal_year_fk DEFERRED;

                DELETE FROM app.fiscal_years WHERE org_id = org;
                INSERT INTO app.fiscal_years (org_id, year, starts_on, ends_on)
                SELECT org, extract(year FROM s)::smallint, s, (s + interval '1 year' - interval '1 day')::date
                FROM (
                    SELECT DISTINCT make_date(extract(year FROM d)::int - CASE WHEN extract(month FROM d) < p_month THEN 1 ELSE 0 END, p_month, 1) AS s
                    FROM (SELECT tx_date AS d FROM app.transactions WHERE org_id = org UNION SELECT p_today) t
                ) x;

                UPDATE app.transactions SET fiscal_year = extract(year FROM make_date(extract(year FROM tx_date)::int - CASE WHEN extract(month FROM tx_date) < p_month THEN 1 ELSE 0 END, p_month, 1))::smallint
                WHERE org_id = org
                  AND fiscal_year IS DISTINCT FROM extract(year FROM make_date(extract(year FROM tx_date)::int - CASE WHEN extract(month FROM tx_date) < p_month THEN 1 ELSE 0 END, p_month, 1))::smallint;
                GET DIAGNOSTICS moved = ROW_COUNT;

                RETURN moved;
            END
            $$;
        SQL);
        DB::statement('REVOKE ALL ON FUNCTION app.realign_fiscal_years(integer, date) FROM PUBLIC');
        DB::statement('GRANT EXECUTE ON FUNCTION app.realign_fiscal_years(integer, date) TO '.OrgSchema::appRole());
        DB::statement('REVOKE DELETE ON app.fiscal_years FROM '.OrgSchema::appRole());
    }

    public function down(): void {}
};
