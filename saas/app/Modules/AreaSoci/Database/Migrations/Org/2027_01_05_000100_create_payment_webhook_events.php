<?php

declare(strict_types=1);

use App\Modules\Core\Contracts\OrgSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Eventi dei webhook dei pagamenti (H14): il webhook verifica la firma, salva qui l'evento una volta sola (chiave: il suo
 * id presso il gestore) e risponde; la registrazione dell'incasso la fa un lavoro in coda (SettleOnlinePayment), che
 * segna l'evento «done» o, dopo l'ultimo tentativo, «failed» (un nuovo invio dello stesso evento lo rimette in coda).
 * Un evento rimasto «queued» senza lavoro lo rimette in coda la passata ogni 5 minuti.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE app.payment_webhook_events (
                org_id       uuid        NOT NULL REFERENCES app.orgs(id),
                id           uuid        NOT NULL,
                provider     text        NOT NULL CHECK (provider IN ('stripe', 'paypal')),
                event_id     text        NOT NULL CHECK (length(event_id) BETWEEN 1 AND 255),
                payment_id   uuid        NOT NULL,
                paid_cents   bigint      NOT NULL CHECK (paid_cents BETWEEN 0 AND 100000000),
                status       text        NOT NULL DEFAULT 'queued' CHECK (status IN ('queued', 'done', 'failed')),
                received_at  timestamptz NOT NULL DEFAULT now(),
                processed_at timestamptz,
                PRIMARY KEY (org_id, id),
                UNIQUE (org_id, provider, event_id),
                FOREIGN KEY (org_id, payment_id) REFERENCES app.online_payments(org_id, id),
                CHECK ((status = 'done') = (processed_at IS NOT NULL))
            )
        SQL);
        DB::statement('CREATE INDEX payment_webhook_events_payment ON app.payment_webhook_events (org_id, payment_id)');
        // La passata ogni 5 minuti (saas:payments:requeue-webhooks) legge solo gli eventi ancora in coda.
        DB::statement("CREATE INDEX payment_webhook_events_queued ON app.payment_webhook_events (org_id, received_at) WHERE status = 'queued'");
        OrgSchema::isolate('app.payment_webhook_events');
        OrgSchema::grant('app.payment_webhook_events', ['SELECT', 'INSERT', 'UPDATE']);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE app.payment_webhook_events');
    }
};
