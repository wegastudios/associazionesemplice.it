<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Rimborso dei pagamenti online «da controllare» (per esempio una quota già pagata): quanto è stato restituito e quando.
 * I soldi tornano al socio dal conto Stripe o PayPal dell'associazione; il registro di controllo conserva ogni rimborso.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE app.online_payments ADD COLUMN refunded_cents bigint NOT NULL DEFAULT 0 CHECK (refunded_cents >= 0), ADD COLUMN refunded_at timestamptz');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE app.online_payments DROP COLUMN refunded_cents, DROP COLUMN refunded_at');
    }
};
