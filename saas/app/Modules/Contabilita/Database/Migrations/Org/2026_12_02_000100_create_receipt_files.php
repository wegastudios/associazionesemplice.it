<?php

declare(strict_types=1);

use App\Modules\Core\Contracts\OrgSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Ricevute definitive: il PDF si crea una volta sola, si salva cifrato (chiave dell'associazione) e se ne registra
 * l'impronta SHA-256. Download ed email servono sempre quel file; se manca o è alterato non si rifà di nascosto.
 * `source`: emissione | lotto (ricevute vecchie fissate dopo) | rifatta (da «Verifica delle ricevute»).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE app.receipt_files (
                org_id        uuid        NOT NULL REFERENCES app.orgs(id),
                receipt_id    uuid        NOT NULL,
                path          text        NOT NULL,
                original_name text        NOT NULL,
                size_bytes    integer     NOT NULL CHECK (size_bytes > 0),
                sha256        char(64)    NOT NULL,
                source        text        NOT NULL CHECK (source IN ('emissione', 'lotto', 'rifatta')),
                fixed_at      timestamptz NOT NULL DEFAULT now(),
                PRIMARY KEY (org_id, receipt_id),
                FOREIGN KEY (org_id, receipt_id) REFERENCES app.receipts(org_id, id)
            )
        SQL);
        OrgSchema::isolate('app.receipt_files');
        OrgSchema::grant('app.receipt_files', ['SELECT', 'INSERT']);
        DB::statement('GRANT UPDATE (path, original_name, size_bytes, sha256, source, fixed_at) ON app.receipt_files TO '.OrgSchema::appRole());
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS app.receipt_files');
    }
};
