<?php

declare(strict_types=1);

use App\Modules\Core\Contracts\OrgSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Domande di adesione da confermare: la domanda del modulo pubblico resta «da confermare» finché chi l'ha scritta non apre
 * il link ricevuto per email (si conserva solo l'impronta del link, con una scadenza). Le domande già presenti restano
 * quelle che erano: il valore di partenza di `unconfirmed` è falso. Serve anche il permesso di cancellare, per la
 * pulizia automatica delle domande mai confermate o respinte. Nessun dato esistente viene toccato.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE app.applications ADD COLUMN unconfirmed boolean NOT NULL DEFAULT false, ADD COLUMN confirm_hash text, ADD COLUMN confirm_expires_at timestamptz, ADD COLUMN confirmed_at timestamptz');
        DB::statement("ALTER TABLE app.applications ADD CONSTRAINT applications_unconfirmed_check CHECK (NOT unconfirmed OR status = 'pending')");
        DB::statement('CREATE UNIQUE INDEX applications_confirm_hash ON app.applications (confirm_hash) WHERE confirm_hash IS NOT NULL');
        DB::statement('CREATE INDEX applications_prune ON app.applications (org_id, submitted_at) WHERE unconfirmed');

        $app = OrgSchema::appRole();
        DB::statement("GRANT UPDATE (unconfirmed, confirm_hash, confirm_expires_at, confirmed_at) ON app.applications TO {$app}");
        DB::statement("GRANT DELETE ON app.applications TO {$app}");
    }

    public function down(): void {}
};
