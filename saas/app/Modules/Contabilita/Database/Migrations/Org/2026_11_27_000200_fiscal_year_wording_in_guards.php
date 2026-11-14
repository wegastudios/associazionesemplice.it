<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Stesse guardie di prima, con «anno fiscale» al posto di «esercizio» nei messaggi che arrivano a schermo.
 */
return new class extends Migration
{
    public function up(): void
    {
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
            CREATE OR REPLACE FUNCTION app.category_closed_year_guard() RETURNS trigger
            LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
            BEGIN
                IF (NEW.name, NEW.kind, NEW.fiscal_group) IS DISTINCT FROM (OLD.name, OLD.kind, OLD.fiscal_group)
                   AND EXISTS (
                       SELECT 1 FROM app.transactions t JOIN app.fiscal_years f ON f.org_id = t.org_id AND f.year = t.fiscal_year
                       WHERE t.org_id = OLD.org_id AND t.category_id = OLD.id AND f.status <> 'open') THEN
                    RAISE EXCEPTION 'La voce «%» è usata in un anno fiscale chiuso: per modificarla riapri l''anno fiscale.', OLD.name;
                END IF;
                RETURN NEW;
            END
            $$;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION app.account_closed_year_guard() RETURNS trigger
            LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
            BEGIN
                IF (NEW.name, NEW.type, NEW.opening_cents) IS DISTINCT FROM (OLD.name, OLD.type, OLD.opening_cents)
                   AND EXISTS (
                       SELECT 1 FROM app.transactions t JOIN app.fiscal_years f ON f.org_id = t.org_id AND f.year = t.fiscal_year
                       WHERE t.org_id = OLD.org_id AND t.account_id = OLD.id AND f.status <> 'open') THEN
                    RAISE EXCEPTION 'Il conto «%» ha movimenti in un anno fiscale chiuso: per modificarlo riapri l''anno fiscale.', OLD.name;
                END IF;
                RETURN NEW;
            END
            $$;
        SQL);
    }

    public function down(): void {}
};
