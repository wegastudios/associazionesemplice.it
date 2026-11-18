<?php

declare(strict_types=1);

use App\Modules\Core\Contracts\OrgSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Punto di controllo della verifica del registro: l'ultimo numero e l'ultimo sigillo trovati giusti. La verifica di ogni
 * notte riparte da lì invece di ripercorrere tutta la catena; una volta a settimana si rifà intera. Senza dati personali.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE audit.verify_checkpoints (
                org_id      uuid        NOT NULL REFERENCES app.orgs(id),
                seq         bigint      NOT NULL CHECK (seq > 0),
                hash        bytea       NOT NULL,
                verified_at timestamptz NOT NULL DEFAULT now(),
                PRIMARY KEY (org_id)
            )
        SQL);
        OrgSchema::isolate('audit.verify_checkpoints');
        OrgSchema::grant('audit.verify_checkpoints', ['SELECT', 'INSERT', 'UPDATE']);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE audit.verify_checkpoints');
    }
};
