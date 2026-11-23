<?php

declare(strict_types=1);

use App\Modules\Audit\Log\TruncateGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Il registro delle azioni resta solo in aggiunta. Fa eccezione la cancellazione definitiva di un'associazione
 * (Core\Org\DatabaseOrgPurger), con la stessa regola dei movimenti della prima nota: si cancellano solo righe di
 * quell'associazione, solo nella transazione che ha impostato `app.purging` al suo identificativo, e solo dalla
 * connessione del proprietario. Così la cancellazione non deve più togliere il trigger (un blocco sull'intera tabella
 * condivisa per tutta la durata). Le modifiche restano sempre rifiutate.
 *
 * TRUNCATE non fa scattare i trigger di riga: un trigger di istruzione lo rifiuta a chiunque non sia la connessione del
 * proprietario, con o senza `app.purging` (la cancellazione definitiva non lo usa). Va sulla tabella e su ogni
 * partizione, perché una partizione si può svuotare anche da sola; le partizioni nuove lo ricevono da Partitions.
 * La funzione è in Audit\Log\TruncateGuard (Partitions la crea se un database ne fosse senza); saas:health controlla
 * che tutti i trigger ci siano.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION audit.reject_change() RETURNS trigger
            LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
            BEGIN
                -- La protezione è il controllo su session_user, non i permessi: il ruolo dell'applicazione può avere
                -- DELETE sulla tabella (lo eredita, per esempio, dal ruolo principale dell'hosting). session_user =
                -- current_user vale solo per la connessione del proprietario (current_user, qui, è il proprietario della
                -- funzione).
                IF TG_OP = 'DELETE' AND current_setting('app.purging', true) = OLD.org_id::text AND session_user = current_user THEN
                    RETURN OLD;
                END IF;
                RAISE EXCEPTION 'Il registro delle azioni è solo in aggiunta';
            END
            $$;
        SQL);

        // La funzione del rifiuto del TRUNCATE ha una sola definizione, condivisa con Partitions.
        DB::unprepared(TruncateGuard::FUNCTION_SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE TRIGGER log_no_truncate BEFORE TRUNCATE ON audit.log
                FOR EACH STATEMENT EXECUTE FUNCTION audit.reject_truncate();

            DO $$
            DECLARE
                part regclass;
            BEGIN
                FOR part IN SELECT inhrelid::regclass FROM pg_inherits WHERE inhparent = 'audit.log'::regclass LOOP
                    EXECUTE format('CREATE OR REPLACE TRIGGER log_no_truncate BEFORE TRUNCATE ON %s FOR EACH STATEMENT EXECUTE FUNCTION audit.reject_truncate()', part);
                END LOOP;
            END
            $$;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DO $$
            DECLARE
                part regclass;
            BEGIN
                FOR part IN SELECT inhrelid::regclass FROM pg_inherits WHERE inhparent = 'audit.log'::regclass LOOP
                    EXECUTE format('DROP TRIGGER IF EXISTS log_no_truncate ON %s', part);
                END LOOP;
            END
            $$;
            DROP TRIGGER IF EXISTS log_no_truncate ON audit.log;
            DROP FUNCTION IF EXISTS audit.reject_truncate();

            CREATE OR REPLACE FUNCTION audit.reject_change() RETURNS trigger
            LANGUAGE plpgsql SECURITY INVOKER AS $$
            BEGIN
                RAISE EXCEPTION 'Il registro delle azioni è solo in aggiunta';
            END
            $$;
        SQL);
    }
};
