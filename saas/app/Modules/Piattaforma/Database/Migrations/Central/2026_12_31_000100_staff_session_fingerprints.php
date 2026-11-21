<?php

declare(strict_types=1);

use App\Modules\Core\Contracts\OrgSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sessioni dello staff: dal rilascio il database non conserva più l'identificativo vero, ma solo la sua impronta
 * (stessa colonna `sid`, stesso formato di 32 caratteri esadecimali). Le sessioni esistenti sono a scadenza di 8 ore e
 * hanno l'identificativo in chiaro: si tolgono, e chi è dentro rifà l'ingresso una volta. Le aree ricevono invece un
 * riferimento proprio, salvato anch'esso solo come impronta, valido per il solo controllo di sessione di quell'area.
 * Solo aggiunte (più lo svuotamento delle sessioni a scadenza).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DELETE FROM core.staff_sessions');
        DB::statement("COMMENT ON COLUMN core.staff_sessions.sid IS 'Impronta (HMAC) dell''identificativo di sessione, mai il valore vero'");

        DB::statement(<<<'SQL'
            CREATE TABLE core.staff_area_sessions (
                ref_hash    text        PRIMARY KEY CHECK (ref_hash ~ '^[0-9a-f]{32}$'),
                session_sid text        NOT NULL REFERENCES core.staff_sessions(sid) ON DELETE CASCADE,
                area        text        NOT NULL CHECK (area IN ('wordpress', 'saas', 'supporto', 'operator')),
                created_at  timestamptz NOT NULL DEFAULT now()
            )
        SQL);
        DB::statement('CREATE INDEX staff_area_sessions_session ON core.staff_area_sessions (session_sid)');
        OrgSchema::grant('core.staff_area_sessions', ['SELECT', 'INSERT', 'DELETE']);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE core.staff_area_sessions');
    }
};
