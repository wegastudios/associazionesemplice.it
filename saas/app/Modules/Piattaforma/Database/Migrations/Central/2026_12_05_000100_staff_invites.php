<?php

declare(strict_types=1);

use App\Modules\Core\Contracts\OrgSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Inviti dello staff (docs/UTENTI_E_ACCESSI.md §4): si usano una volta sola e scadono dopo 7 giorni. Si conserva solo
 * l'impronta del codice, mai il codice. Solo una tabella nuova.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE core.staff_invites (
                id         uuid        PRIMARY KEY,
                token_hash text        NOT NULL UNIQUE CHECK (token_hash ~ '^[0-9a-f]{64}$'),
                email      text        NOT NULL CHECK (email = lower(email) AND length(email) <= 254),
                name       text        NOT NULL CHECK (length(name) BETWEEN 1 AND 200),
                roles      jsonb       NOT NULL DEFAULT '{}',
                created_by uuid        REFERENCES core.staff_people(id),
                expires_at timestamptz NOT NULL,
                used_at    timestamptz,
                created_at timestamptz NOT NULL DEFAULT now()
            )
        SQL);
        DB::statement('CREATE INDEX staff_invites_email ON core.staff_invites (email)');
        OrgSchema::grant('core.staff_invites', ['SELECT', 'INSERT', 'UPDATE']);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE core.staff_invites');
    }
};
