<?php

declare(strict_types=1);

use App\Modules\Core\Contracts\OrgSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Accesso unico (docs/LOGIN_UNICO_E_DATABASE.md, passo 2): il secondo livello (facoltativo) e il recupero password
 * vivono nell'elenco unico dello staff. Solo aggiunte: nulla di quanto esiste cambia.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE core.staff_people ADD COLUMN two_factor_secret text, ADD COLUMN two_factor_confirmed_at timestamptz');

        // Recupero password: del codice resta solo l'impronta; vale 1 ora e una volta sola.
        DB::statement(<<<'SQL'
            CREATE TABLE core.staff_resets (
                id         uuid        PRIMARY KEY,
                token_hash text        NOT NULL UNIQUE,
                person_id  uuid        NOT NULL REFERENCES core.staff_people(id) ON DELETE CASCADE,
                expires_at timestamptz NOT NULL,
                used_at    timestamptz,
                created_at timestamptz NOT NULL DEFAULT now()
            )
        SQL);
        OrgSchema::grant('core.staff_resets', ['SELECT', 'INSERT', 'UPDATE', 'DELETE']);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE core.staff_resets');
        DB::statement('ALTER TABLE core.staff_people DROP COLUMN two_factor_secret, DROP COLUMN two_factor_confirmed_at');
    }
};
