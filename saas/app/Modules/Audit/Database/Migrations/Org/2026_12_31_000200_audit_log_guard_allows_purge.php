<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Il registro delle azioni resta solo in aggiunta. Fa eccezione la cancellazione definitiva di un'associazione
 * (Core\Org\DatabaseOrgPurger), con la stessa regola dei movimenti della prima nota: si cancellano solo righe di
 * quell'associazione, solo nella transazione che ha impostato `app.purging` al suo identificativo, e solo dalla
 * connessione del proprietario. Così la cancellazione non deve più togliere il trigger (un blocco sull'intera tabella
 * condivisa per tutta la durata). Le modifiche restano sempre rifiutate.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION audit.reject_change() RETURNS trigger
            LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
            BEGIN
                -- session_user = current_user: connessione del proprietario (current_user, qui, è il proprietario della
                -- funzione). L'utente dell'applicazione non ha comunque il permesso DELETE su questa tabella.
                IF TG_OP = 'DELETE' AND current_setting('app.purging', true) = OLD.org_id::text AND session_user = current_user THEN
                    RETURN OLD;
                END IF;
                RAISE EXCEPTION 'Il registro delle azioni è solo in aggiunta';
            END
            $$;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION audit.reject_change() RETURNS trigger
            LANGUAGE plpgsql SECURITY INVOKER AS $$
            BEGIN
                RAISE EXCEPTION 'Il registro delle azioni è solo in aggiunta';
            END
            $$;
        SQL);
    }
};
