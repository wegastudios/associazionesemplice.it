<?php

declare(strict_types=1);

use App\Modules\Core\Contracts\OrgSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Cambio email dell'identità unica (docs/IDENTITA_API.md): si conferma dal link mandato al NUOVO indirizzo. Solo aggiunte. */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE core.staff_email_changes (
                id         uuid        PRIMARY KEY,
                token_hash text        NOT NULL UNIQUE,
                person_id  uuid        NOT NULL REFERENCES core.staff_people(id) ON DELETE CASCADE,
                new_email  text        NOT NULL CHECK (new_email = lower(new_email) AND length(new_email) <= 254),
                expires_at timestamptz NOT NULL,
                used_at    timestamptz,
                created_at timestamptz NOT NULL DEFAULT now()
            )
        SQL);
        OrgSchema::grant('core.staff_email_changes', ['SELECT', 'INSERT', 'UPDATE', 'DELETE']);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE core.staff_email_changes');
    }
};
