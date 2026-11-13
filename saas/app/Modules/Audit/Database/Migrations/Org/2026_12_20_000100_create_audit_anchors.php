<?php

declare(strict_types=1);

use App\Modules\Core\Contracts\OrgSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Ancora della catena: quando le partizioni più vecchie del registro si cancellano (si tengono l'anno corrente e il
 * precedente), l'ultimo numero e l'ultimo sigillo cancellati restano qui, senza dati personali, e la verifica parte da lì.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE audit.anchors (
                org_id      uuid        NOT NULL REFERENCES app.orgs(id),
                seq         bigint      NOT NULL CHECK (seq > 0),
                hash        bytea       NOT NULL,
                anchored_at timestamptz NOT NULL DEFAULT now(),
                PRIMARY KEY (org_id)
            )
        SQL);
        OrgSchema::isolate('audit.anchors');
        OrgSchema::grant('audit.anchors', ['SELECT']);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE audit.anchors');
    }
};
