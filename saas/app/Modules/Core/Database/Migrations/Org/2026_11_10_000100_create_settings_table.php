<?php

declare(strict_types=1);

use App\Modules\Core\Contracts\OrgSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Impostazioni dell'associazione, chiave e valore (Core\Contracts\OrgSettings). Niente dati personali qui. */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE app.settings (
                org_id     uuid        NOT NULL REFERENCES app.orgs(id),
                key        text        NOT NULL CHECK (key ~ '^[a-z][a-z0-9_.]{1,60}$'),
                value      jsonb       NOT NULL,
                updated_at timestamptz NOT NULL DEFAULT now(),
                PRIMARY KEY (org_id, key)
            )
        SQL);
        OrgSchema::isolate('app.settings');
        OrgSchema::grant('app.settings', ['SELECT', 'INSERT', 'UPDATE']);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE app.settings');
    }
};
