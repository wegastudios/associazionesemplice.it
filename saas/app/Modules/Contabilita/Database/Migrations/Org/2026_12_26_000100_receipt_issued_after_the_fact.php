<?php

declare(strict_types=1);

use App\Modules\Core\Contracts\OrgSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * La ricevuta di un incasso già registrato si può emettere dopo (pagamenti online da controllare: il movimento entra
 * subito in prima nota, la ricevuta nasce quando la segreteria ha controllato). Unica novità: un movimento di incasso non
 * annullato, senza ricevuta, in un anno fiscale aperto, può ricevere la sua ricevuta una volta sola e nient'altro cambia.
 * Il resto della funzione è identico a quello della migrazione precedente.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('GRANT UPDATE (receipt_id) ON app.transactions TO '.OrgSchema::appRole());
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION app.transaction_guard() RETURNS trigger
            LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
            DECLARE
                fy app.fiscal_years%ROWTYPE;
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    -- Solo la cancellazione definitiva dell'associazione: transazione che ha impostato app.purging E
                    -- connessione del proprietario (session_user = current_user, che qui è il proprietario della funzione).
                    -- L'utente dell'applicazione non ha comunque il permesso DELETE su questa tabella.
                    IF current_setting('app.purging', true) = OLD.org_id::text AND session_user = current_user THEN
                        RETURN OLD;
                    END IF;
                    RAISE EXCEPTION 'I movimenti della prima nota non si eliminano: si annullano.';
                END IF;

                SELECT * INTO fy FROM app.fiscal_years WHERE org_id = NEW.org_id AND year = NEW.fiscal_year;
                IF NOT FOUND OR NEW.tx_date < fy.starts_on OR NEW.tx_date > fy.ends_on THEN
                    RAISE EXCEPTION 'La data del movimento non appartiene all''anno fiscale indicato.';
                END IF;
                IF fy.status <> 'open' THEN
                    RAISE EXCEPTION 'L''anno fiscale % è chiuso: per modificarlo va riaperto dal Rendiconto (si può riaprire solo l''ultimo anno chiuso).', fy.year;
                END IF;

                -- Cambio del mese di inizio dell'anno fiscale (app.realign_fiscal_years): cambia solo l'anno fiscale.
                IF TG_OP = 'UPDATE' AND current_setting('app.realigning', true) = NEW.org_id::text
                   AND (to_jsonb(NEW) - 'fiscal_year') = (to_jsonb(OLD) - 'fiscal_year') THEN
                    RETURN NEW;
                END IF;

                IF TG_OP = 'INSERT' THEN
                    IF NEW.voided_at IS NOT NULL THEN
                        RAISE EXCEPTION 'Un movimento nuovo non può essere già annullato.';
                    END IF;
                    -- Un anno che precede uno già chiuso non riceve movimenti nuovi, salvo che ne avesse già.
                    IF EXISTS (SELECT 1 FROM app.fiscal_years c WHERE c.org_id = NEW.org_id AND c.status <> 'open' AND c.starts_on > fy.ends_on)
                       AND NOT EXISTS (SELECT 1 FROM app.transactions t WHERE t.org_id = NEW.org_id AND t.fiscal_year = fy.year) THEN
                        RAISE EXCEPTION 'L''anno fiscale % precede un anno già chiuso: non si registrano movimenti in un anno precedente a uno chiuso (per correggere l''anno chiuso va riaperto dal Rendiconto).', fy.year;
                    END IF;
                    IF EXISTS (SELECT 1 FROM app.accounts WHERE org_id = NEW.org_id AND id = NEW.account_id AND closed_at IS NOT NULL) THEN
                        RAISE EXCEPTION 'Il conto è chiuso: riaprilo per registrare movimenti.';
                    END IF;
                    RETURN NEW;
                END IF;

                -- UPDATE: la ricevuta emessa dopo (una volta sola, su un incasso non annullato), il centro di costo di un movimento non annullato, oppure l'annullamento, una volta sola. Nient'altro.
                IF OLD.voided_at IS NULL AND NEW.voided_at IS NULL AND OLD.receipt_id IS NULL AND NEW.receipt_id IS NOT NULL
                   AND (to_jsonb(NEW) - 'receipt_id') = (to_jsonb(OLD) - 'receipt_id') THEN
                    IF NEW.type <> 'income' THEN
                        RAISE EXCEPTION 'La ricevuta si emette solo per un incasso.';
                    END IF;
                    RETURN NEW;
                END IF;
                IF OLD.voided_at IS NULL AND NEW.voided_at IS NULL
                   AND (to_jsonb(NEW) - 'activity_id') = (to_jsonb(OLD) - 'activity_id') THEN
                    IF NEW.type NOT IN ('income', 'expense') THEN
                        RAISE EXCEPTION 'Un giroconto non si assegna a un''attività.';
                    END IF;
                    RETURN NEW;
                END IF;
                IF OLD.voided_at IS NOT NULL OR NEW.voided_at IS NULL
                   OR (to_jsonb(NEW) - 'voided_at' - 'void_reason' - 'voided_by') <> (to_jsonb(OLD) - 'voided_at' - 'void_reason' - 'voided_by') THEN
                    RAISE EXCEPTION 'I movimenti della prima nota non si modificano: si annullano.';
                END IF;
                RETURN NEW;
            END
            $$;
        SQL);
    }

    public function down(): void
    {
        // La funzione precedente resta quella dell'ultima migrazione che la definisce: non si ripristina qui.
    }
};
