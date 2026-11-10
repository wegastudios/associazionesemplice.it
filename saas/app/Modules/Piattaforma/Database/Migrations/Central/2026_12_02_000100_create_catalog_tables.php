<?php

declare(strict_types=1);

use App\Modules\Core\Contracts\OrgSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Catalogo pubblicato dall'amministrazione (CONTRATTO_PORTALE.md §2). Le revisioni si aggiungono e non si cambiano mai:
 * restano tutte, perché un abbonamento già venduto resta sulla sua versione del piano. La bozza è una sola.
 * Tabelle di piattaforma: niente `org_id`, schema `core` (DATABASE.md §10).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE core.catalog_revisions (
                rev          bigint      PRIMARY KEY CHECK (rev > 0),
                document     jsonb       NOT NULL,
                published_by uuid        REFERENCES core.platform_staff(id),
                note         text,
                published_at timestamptz NOT NULL DEFAULT now()
            )
        SQL);
        OrgSchema::grant('core.catalog_revisions', ['SELECT', 'INSERT']);

        DB::statement(<<<'SQL'
            CREATE TABLE core.catalog_draft (
                id         smallint    PRIMARY KEY CHECK (id = 1),
                document   jsonb       NOT NULL,
                updated_by uuid        REFERENCES core.platform_staff(id),
                updated_at timestamptz NOT NULL DEFAULT now()
            )
        SQL);
        OrgSchema::grant('core.catalog_draft', ['SELECT', 'INSERT', 'UPDATE', 'DELETE']);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE core.catalog_draft');
        DB::statement('DROP TABLE core.catalog_revisions');
    }
};
