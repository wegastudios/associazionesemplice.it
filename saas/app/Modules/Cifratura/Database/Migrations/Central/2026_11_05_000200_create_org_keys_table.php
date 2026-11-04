<?php

declare(strict_types=1);

use App\Modules\Core\Contracts\OrgSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Chiavi dati delle associazioni, solo cifrate con la chiave principale. Il ruolo dell'applicazione le legge e le
 * aggiunge, ma non le modifica né le cancella: la distruzione (saas:keys:destroy) passa dal proprietario.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE core.org_keys (
                org_id      uuid        NOT NULL REFERENCES core.orgs(id),
                version     integer     NOT NULL CHECK (version > 0),
                wrapped_key bytea       NOT NULL,
                created_at  timestamptz NOT NULL DEFAULT now(),
                PRIMARY KEY (org_id, version)
            )
        SQL);
        OrgSchema::grant('core.org_keys', ['SELECT', 'INSERT']);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE core.org_keys');
    }
};
