<?php

declare(strict_types=1);

use App\Modules\Core\Contracts\OrgSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Eventi con prenotazioni (dal plugin: sessions, bookings, waitlist, attendance, listini): le date di un'attività,
 * le prenotazioni alla singola data con il contributo fissato al momento (listino o pacchetto di ingressi), la lista
 * d'attesa quando i posti sono finiti, le presenze delle lezioni e delle date.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE app.activities DROP CONSTRAINT activities_kind_check');
        DB::statement("ALTER TABLE app.activities ADD CONSTRAINT activities_kind_check CHECK (kind IN ('course', 'event', 'recurring'))");
        DB::statement("ALTER TABLE app.activities ADD COLUMN price_lists jsonb NOT NULL DEFAULT '[]' CHECK (jsonb_typeof(price_lists) = 'array')");

        DB::statement(<<<'SQL'
            CREATE TABLE app.activity_sessions (
                org_id       uuid        NOT NULL REFERENCES app.orgs(id),
                id           uuid        NOT NULL,
                activity_id  uuid        NOT NULL,
                session_date date        NOT NULL,
                start_time   char(5)     CHECK (start_time ~ '^([01]\d|2[0-3]):[0-5]\d$'),
                end_time     char(5)     CHECK (end_time ~ '^([01]\d|2[0-3]):[0-5]\d$'),
                location     text        CHECK (length(location) <= 190),
                capacity     integer     CHECK (capacity BETWEEN 1 AND 100000),
                cancelled_at timestamptz,
                created_at   timestamptz NOT NULL DEFAULT now(),
                updated_at   timestamptz NOT NULL DEFAULT now(),
                PRIMARY KEY (org_id, id),
                FOREIGN KEY (org_id, activity_id) REFERENCES app.activities(org_id, id),
                CHECK (end_time IS NULL OR start_time IS NULL OR end_time > start_time)
            )
        SQL);
        DB::statement("CREATE UNIQUE INDEX activity_sessions_when ON app.activity_sessions (org_id, activity_id, session_date, coalesce(start_time, ''))");
        DB::statement('CREATE INDEX activity_sessions_date ON app.activity_sessions (org_id, session_date)');
        OrgSchema::isolate('app.activity_sessions');
        OrgSchema::grant('app.activity_sessions', ['SELECT', 'INSERT', 'UPDATE']);

        DB::statement(<<<'SQL'
            CREATE TABLE app.session_bookings (
                org_id          uuid        NOT NULL REFERENCES app.orgs(id),
                id              uuid        NOT NULL,
                session_id      uuid        NOT NULL,
                person_id       uuid        NOT NULL,
                status          text        NOT NULL DEFAULT 'booked' CHECK (status IN ('booked', 'cancelled')),
                fee_due_cents   bigint      NOT NULL DEFAULT 0 CHECK (fee_due_cents BETWEEN 0 AND 100000000),
                price_list      text        CHECK (length(price_list) <= 40),
                package_entries smallint    NOT NULL DEFAULT 0 CHECK (package_entries BETWEEN 0 AND 50),
                package_of      uuid,
                created_by      uuid,
                created_at      timestamptz NOT NULL DEFAULT now(),
                updated_at      timestamptz NOT NULL DEFAULT now(),
                cancelled_at    timestamptz,
                PRIMARY KEY (org_id, id),
                UNIQUE (org_id, session_id, person_id),
                FOREIGN KEY (org_id, session_id) REFERENCES app.activity_sessions(org_id, id),
                FOREIGN KEY (org_id, person_id)  REFERENCES app.people(org_id, id),
                FOREIGN KEY (org_id, package_of) REFERENCES app.session_bookings(org_id, id),
                CHECK ((status = 'cancelled') = (cancelled_at IS NOT NULL)),
                CHECK (package_of IS NULL OR (package_entries = 0 AND fee_due_cents = 0))
            )
        SQL);
        DB::statement('CREATE INDEX session_bookings_person ON app.session_bookings (org_id, person_id)');
        DB::statement('CREATE INDEX session_bookings_package ON app.session_bookings (org_id, package_of) WHERE package_of IS NOT NULL');
        OrgSchema::isolate('app.session_bookings');
        OrgSchema::grant('app.session_bookings', ['SELECT', 'INSERT', 'UPDATE']);

        DB::statement(<<<'SQL'
            CREATE TABLE app.booking_payments (
                org_id         uuid        NOT NULL REFERENCES app.orgs(id),
                transaction_id uuid        NOT NULL,
                booking_id     uuid        NOT NULL,
                created_at     timestamptz NOT NULL DEFAULT now(),
                PRIMARY KEY (org_id, transaction_id),
                FOREIGN KEY (org_id, transaction_id) REFERENCES app.transactions(org_id, id),
                FOREIGN KEY (org_id, booking_id)     REFERENCES app.session_bookings(org_id, id)
            )
        SQL);
        DB::statement('CREATE INDEX booking_payments_booking ON app.booking_payments (org_id, booking_id)');
        OrgSchema::isolate('app.booking_payments');
        OrgSchema::grant('app.booking_payments', ['SELECT', 'INSERT']);

        DB::statement(<<<'SQL'
            CREATE TABLE app.session_waitlist (
                org_id      uuid        NOT NULL REFERENCES app.orgs(id),
                id          uuid        NOT NULL,
                session_id  uuid        NOT NULL,
                person_id   uuid        NOT NULL,
                status      text        NOT NULL DEFAULT 'waiting' CHECK (status IN ('waiting', 'promoted', 'left')),
                created_at  timestamptz NOT NULL DEFAULT now(),
                resolved_at timestamptz,
                PRIMARY KEY (org_id, id),
                FOREIGN KEY (org_id, session_id) REFERENCES app.activity_sessions(org_id, id),
                FOREIGN KEY (org_id, person_id)  REFERENCES app.people(org_id, id)
            )
        SQL);
        DB::statement("CREATE UNIQUE INDEX session_waitlist_waiting ON app.session_waitlist (org_id, session_id, person_id) WHERE status = 'waiting'");
        DB::statement('CREATE INDEX session_waitlist_person ON app.session_waitlist (org_id, person_id)');
        OrgSchema::isolate('app.session_waitlist');
        OrgSchema::grant('app.session_waitlist', ['SELECT', 'INSERT', 'UPDATE']);

        DB::statement(<<<'SQL'
            CREATE TABLE app.attendance (
                org_id      uuid        NOT NULL REFERENCES app.orgs(id),
                activity_id uuid        NOT NULL,
                lesson_date date        NOT NULL,
                person_id   uuid        NOT NULL,
                present     boolean     NOT NULL,
                marked_by   uuid,
                marked_at   timestamptz NOT NULL DEFAULT now(),
                PRIMARY KEY (org_id, activity_id, lesson_date, person_id),
                FOREIGN KEY (org_id, activity_id) REFERENCES app.activities(org_id, id),
                FOREIGN KEY (org_id, person_id)   REFERENCES app.people(org_id, id)
            )
        SQL);
        DB::statement('CREATE INDEX attendance_person ON app.attendance (org_id, person_id)');
        OrgSchema::isolate('app.attendance');
        OrgSchema::grant('app.attendance', ['SELECT', 'INSERT', 'UPDATE', 'DELETE']);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE app.attendance');
        DB::statement('DROP TABLE app.session_waitlist');
        DB::statement('DROP TABLE app.booking_payments');
        DB::statement('DROP TABLE app.session_bookings');
        DB::statement('DROP TABLE app.activity_sessions');
        DB::statement('ALTER TABLE app.activities DROP COLUMN price_lists');
        DB::statement('ALTER TABLE app.activities DROP CONSTRAINT activities_kind_check');
        DB::statement("ALTER TABLE app.activities ADD CONSTRAINT activities_kind_check CHECK (kind IN ('course', 'event'))");
    }
};
