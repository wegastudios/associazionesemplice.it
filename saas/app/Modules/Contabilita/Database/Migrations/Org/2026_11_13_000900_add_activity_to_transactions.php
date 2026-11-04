<?php

declare(strict_types=1);

use App\Modules\Core\Contracts\OrgSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Centro di costo dei movimenti: l'attività a cui si riferiscono (la chiave esterna la aggiunge il modulo Attività).
 * È un dato di analisi, non contabile: è l'unica cosa di un movimento che si può cambiare dopo, finché non è stornato
 * e l'esercizio è aperto. Il resto resta immutabile.
 */
return new class extends Migration
{
    public function up(): void
    {
        $app = OrgSchema::appRole();
        DB::statement('ALTER TABLE app.transactions ADD COLUMN activity_id uuid');
        DB::statement('CREATE INDEX transactions_activity ON app.transactions (org_id, activity_id) WHERE activity_id IS NOT NULL');
        DB::statement("GRANT UPDATE (activity_id) ON app.transactions TO {$app}");

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
                    RAISE EXCEPTION 'La data del movimento non appartiene all''esercizio indicato.';
                END IF;
                IF fy.status <> 'open' THEN
                    RAISE EXCEPTION 'L''esercizio % è chiuso: riaprilo per registrare o stornare movimenti.', fy.year;
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
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE app.transactions DROP COLUMN activity_id');
    }
};
