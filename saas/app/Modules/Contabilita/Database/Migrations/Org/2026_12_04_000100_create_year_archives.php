<?php

declare(strict_types=1);

use App\Modules\Core\Contracts\OrgSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Archivio degli anni chiusi: un file cifrato per anno con le ricevute in PDF (solo file, mai righe di dati).
 * `receipt_files.archived_year` segna le ricevute il cui file singolo è stato tolto perché sta nell'archivio.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE app.year_archives (
                org_id        uuid        NOT NULL REFERENCES app.orgs(id),
                fiscal_year   integer     NOT NULL,
                path          text        NOT NULL,
                original_name text        NOT NULL,
                size_bytes    bigint      NOT NULL CHECK (size_bytes > 0),
                sha256        char(64)    NOT NULL,
                file_count    integer     NOT NULL CHECK (file_count >= 0),
                archived_at   timestamptz NOT NULL DEFAULT now(),
                archived_by   uuid,
                PRIMARY KEY (org_id, fiscal_year)
            )
        SQL);
        OrgSchema::isolate('app.year_archives');
        OrgSchema::grant('app.year_archives', ['SELECT', 'INSERT', 'DELETE']);
        DB::statement('ALTER TABLE app.receipt_files ADD COLUMN IF NOT EXISTS archived_year integer');
        DB::statement('GRANT UPDATE (archived_year) ON app.receipt_files TO '.OrgSchema::appRole());
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE app.receipt_files DROP COLUMN IF EXISTS archived_year');
        DB::statement('DROP TABLE IF EXISTS app.year_archives');
    }
};
