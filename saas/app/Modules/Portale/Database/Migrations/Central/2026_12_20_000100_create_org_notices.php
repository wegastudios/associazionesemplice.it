<?php

declare(strict_types=1);

use App\Modules\Core\Contracts\OrgSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Avvisi del ciclo di vita già inviati (scadenza, sospensione, cancellazione, fine prova): uno per tipo e per
 * scadenza, così un avviso non parte due volte e chi rinnova e scade di nuovo li riceve di nuovo.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE core.org_notices (
                org_id  uuid        NOT NULL REFERENCES core.orgs(id),
                kind    text        NOT NULL CHECK (kind ~ '^[a-z0-9_]{2,40}$'),
                until   date        NOT NULL,
                sent_at timestamptz NOT NULL DEFAULT now(),
                PRIMARY KEY (org_id, kind, until)
            )
        SQL);
        OrgSchema::grant('core.org_notices', ['SELECT', 'INSERT']);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE core.org_notices');
    }
};
