<?php

declare(strict_types=1);

namespace App\Modules\Audit\Log;

use Illuminate\Database\ConnectionInterface;

/**
 * Rifiuto del TRUNCATE sul registro delle azioni (docs/DATABASE.md §7). TRUNCATE non fa scattare i trigger di riga: un
 * trigger di istruzione lo rifiuta a chiunque non sia la connessione del proprietario. Va sulla tabella e su ogni
 * partizione, perché una partizione si può svuotare anche da sola. Unica definizione della funzione: la usano la
 * migrazione che l'ha introdotta e Partitions, che la crea se in un database mancasse.
 */
final class TruncateGuard
{
    public const TRIGGER = 'log_no_truncate';

    public const FUNCTION_SQL = <<<'SQL'
        CREATE OR REPLACE FUNCTION audit.reject_truncate() RETURNS trigger
        LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
        BEGIN
            -- Solo la connessione del proprietario (stesso controllo su session_user di audit.reject_change).
            IF session_user = current_user THEN
                RETURN NULL;
            END IF;
            RAISE EXCEPTION 'Il registro delle azioni è solo in aggiunta';
        END
        $$;
        SQL;

    /** La funzione c'è sempre dopo questa chiamata: se manca la crea (con il ruolo proprietario, che ne diventa il proprietario). */
    public static function ensureFunction(ConnectionInterface $owner): void
    {
        $exists = $owner->selectOne("SELECT to_regprocedure('audit.reject_truncate()') IS NOT NULL AS ok");
        if (! is_object($exists) || ($exists->ok ?? false) !== true) {
            $owner->unprepared(self::FUNCTION_SQL);
        }
    }

    /** Il trigger sulla tabella (o partizione) indicata; la funzione deve già esserci (ensureFunction). */
    public static function protect(ConnectionInterface $owner, string $table): void
    {
        $owner->statement('CREATE OR REPLACE TRIGGER '.self::TRIGGER." BEFORE TRUNCATE ON {$table} FOR EACH STATEMENT EXECUTE FUNCTION audit.reject_truncate()");
    }
}
