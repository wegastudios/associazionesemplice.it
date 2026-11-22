<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * TRUNCATE non fa scattare la guardia di riga dei movimenti (app.transaction_guard): un trigger di istruzione lo rifiuta
 * a chiunque non sia la connessione del proprietario, con o senza `app.purging` (la cancellazione definitiva cancella
 * riga per riga). La protezione è il controllo su session_user, non i permessi: il ruolo dell'applicazione può avere
 * DELETE e TRUNCATE sulla tabella, per esempio ereditati dal ruolo principale dell'hosting. Lo stesso vale per il
 * commento dentro app.transaction_guard, che dice il contrario: la regola del trigger è quella giusta.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION app.reject_transactions_truncate() RETURNS trigger
            LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
            BEGIN
                -- session_user = current_user: solo la connessione del proprietario (current_user, qui, è il proprietario
                -- della funzione).
                IF session_user = current_user THEN
                    RETURN NULL;
                END IF;
                RAISE EXCEPTION 'I movimenti della prima nota non si eliminano: si annullano.';
            END
            $$;

            CREATE OR REPLACE TRIGGER transactions_no_truncate BEFORE TRUNCATE ON app.transactions
                FOR EACH STATEMENT EXECUTE FUNCTION app.reject_transactions_truncate();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS transactions_no_truncate ON app.transactions;
            DROP FUNCTION IF EXISTS app.reject_transactions_truncate();
        SQL);
    }
};
