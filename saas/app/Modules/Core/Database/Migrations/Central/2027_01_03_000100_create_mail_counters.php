<?php

declare(strict_types=1);

use App\Modules\Core\Contracts\OrgSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Contatori della posta per giorno e ora UTC e per mailer (Core\Mail\MailCounters), scritti solo con il servizio di
 * invio principale acceso: se Redis si svuota, la quota del giorno riparte da qui invece che da zero.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE core.mail_counters (
                day       date     NOT NULL,
                hour      smallint NOT NULL CHECK (hour BETWEEN 0 AND 23),
                provider  text     NOT NULL CHECK (provider ~ '^[a-z0-9_-]{1,40}$'),
                sent      integer  NOT NULL DEFAULT 0,
                bulk_sent integer  NOT NULL DEFAULT 0,
                errors    integer  NOT NULL DEFAULT 0,
                fallbacks integer  NOT NULL DEFAULT 0,
                PRIMARY KEY (day, hour, provider)
            )
        SQL);
        OrgSchema::grant('core.mail_counters', ['SELECT', 'INSERT', 'UPDATE']);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE core.mail_counters');
    }
};
