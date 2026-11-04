<?php

declare(strict_types=1);

use App\Modules\Core\Contracts\OrgSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Certificati medici (DATABASE.md §5): tipo, medico e nome del file cifrati, file cifrato fuori dal database. Le date
 * restano in chiaro per scadenze e promemoria. Ogni lettura dei dati sanitari finisce nel registro delle azioni.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE app.medical_certs (
                org_id        uuid        NOT NULL REFERENCES app.orgs(id),
                id            uuid        NOT NULL,
                person_id     uuid        NOT NULL,
                kind_enc      bytea       NOT NULL,
                doctor_enc    bytea,
                issued_on     date        NOT NULL,
                expires_on    date        NOT NULL,
                file_path     text        CHECK (length(file_path) <= 200),
                file_name_enc bytea,
                file_mime     text        CHECK (length(file_mime) <= 100),
                file_size     bigint      CHECK (file_size >= 0),
                file_sha256   char(64),
                created_by    uuid,
                created_at    timestamptz NOT NULL DEFAULT now(),
                updated_at    timestamptz NOT NULL DEFAULT now(),
                deleted_at    timestamptz,
                PRIMARY KEY (org_id, id),
                FOREIGN KEY (org_id, person_id) REFERENCES app.people(org_id, id),
                CHECK (expires_on >= issued_on),
                CHECK ((file_path IS NULL) = (file_sha256 IS NULL))
            )
        SQL);
        DB::statement('CREATE INDEX medical_certs_person ON app.medical_certs (org_id, person_id, expires_on) WHERE deleted_at IS NULL');
        DB::statement('CREATE INDEX medical_certs_expiry ON app.medical_certs (org_id, expires_on) WHERE deleted_at IS NULL');
        OrgSchema::isolate('app.medical_certs');
        OrgSchema::grant('app.medical_certs', ['SELECT', 'INSERT', 'UPDATE']);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE app.medical_certs');
    }
};
