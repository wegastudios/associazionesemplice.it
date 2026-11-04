<?php

declare(strict_types=1);

use App\Modules\Core\Contracts\OrgSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * - app.consent_events: ogni consenso dato o revocato, solo in aggiunta (è la prova da mostrare); lo stato attuale è
 *   l'ultimo evento per persona e canale (vista app.current_consents).
 * - app.reminders: promemoria già mandati, uno per persona, tipo e riferimento (la scadenza): mai doppioni.
 * - app.applications: domande di adesione online, con tutti i dati del modulo cifrati.
 */
return new class extends Migration
{
    public function up(): void
    {
        $app = OrgSchema::appRole();

        DB::statement(<<<'SQL'
            CREATE TABLE app.consent_events (
                org_id             uuid        NOT NULL REFERENCES app.orgs(id),
                id                 uuid        NOT NULL,
                person_id          uuid        NOT NULL,
                channel            text        NOT NULL CHECK (channel IN ('privacy', 'rules', 'images', 'sms', 'newsletter')),
                granted            boolean     NOT NULL,
                source             text        NOT NULL CHECK (source IN ('paper', 'oral', 'web', 'admin')),
                given_by_person_id uuid,
                recorded_by        uuid,
                occurred_at        timestamptz NOT NULL DEFAULT now(),
                PRIMARY KEY (org_id, id),
                FOREIGN KEY (org_id, person_id)          REFERENCES app.people(org_id, id),
                FOREIGN KEY (org_id, given_by_person_id) REFERENCES app.people(org_id, id)
            )
        SQL);
        DB::statement('CREATE INDEX consent_events_person ON app.consent_events (org_id, person_id, channel, occurred_at)');
        DB::statement('CREATE INDEX consent_events_given_by ON app.consent_events (org_id, given_by_person_id) WHERE given_by_person_id IS NOT NULL');
        OrgSchema::isolate('app.consent_events');
        OrgSchema::grant('app.consent_events', ['SELECT', 'INSERT']);

        DB::statement(<<<'SQL'
            CREATE VIEW app.current_consents WITH (security_invoker = true) AS
            SELECT DISTINCT ON (org_id, person_id, channel) *
            FROM app.consent_events
            ORDER BY org_id, person_id, channel, occurred_at DESC, id DESC
        SQL);
        DB::statement("GRANT SELECT ON app.current_consents TO {$app}");

        DB::statement(<<<'SQL'
            CREATE TABLE app.reminders (
                org_id    uuid        NOT NULL REFERENCES app.orgs(id),
                person_id uuid        NOT NULL,
                kind      text        NOT NULL CHECK (kind ~ '^[a-z_]{1,30}$'),
                ref       text        NOT NULL CHECK (length(ref) <= 60),
                sent_at   timestamptz NOT NULL DEFAULT now(),
                PRIMARY KEY (org_id, person_id, kind, ref),
                FOREIGN KEY (org_id, person_id) REFERENCES app.people(org_id, id)
            )
        SQL);
        OrgSchema::isolate('app.reminders');
        OrgSchema::grant('app.reminders', ['SELECT', 'INSERT', 'DELETE']);

        DB::statement(<<<'SQL'
            CREATE TABLE app.applications (
                org_id       uuid        NOT NULL REFERENCES app.orgs(id),
                id           uuid        NOT NULL,
                status       text        NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'approved', 'rejected')),
                first_name   text        NOT NULL CHECK (length(first_name) <= 120),
                last_name    text        NOT NULL CHECK (length(last_name) <= 120),
                minor        boolean     NOT NULL,
                data_enc     bytea       NOT NULL,
                submitted_at timestamptz NOT NULL DEFAULT now(),
                decided_at   timestamptz,
                decided_by   uuid,
                reason       text        CHECK (length(reason) <= 200),
                person_id    uuid,
                PRIMARY KEY (org_id, id),
                FOREIGN KEY (org_id, person_id) REFERENCES app.people(org_id, id),
                CHECK ((status = 'pending') = (decided_at IS NULL))
            )
        SQL);
        DB::statement('CREATE INDEX applications_status ON app.applications (org_id, status, submitted_at)');
        DB::statement('CREATE INDEX applications_person ON app.applications (org_id, person_id) WHERE person_id IS NOT NULL');
        OrgSchema::isolate('app.applications');
        OrgSchema::grant('app.applications', ['SELECT', 'INSERT']);
        DB::statement("GRANT UPDATE (status, decided_at, decided_by, reason, person_id) ON app.applications TO {$app}");
    }

    public function down(): void
    {
        DB::statement('DROP TABLE app.applications');
        DB::statement('DROP TABLE app.reminders');
        DB::statement('DROP VIEW app.current_consents');
        DB::statement('DROP TABLE app.consent_events');
    }
};
