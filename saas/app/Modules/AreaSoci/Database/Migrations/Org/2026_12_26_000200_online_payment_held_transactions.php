<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Pagamenti online da controllare: i movimenti entrano subito in prima nota ma la ricevuta si emette solo quando la
 * segreteria li segna «controllati». Qui si ricordano i movimenti in attesa di ricevuta: un gruppo (una ricevuta) per voce.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE app.online_payments ADD COLUMN held_transactions jsonb NOT NULL DEFAULT '[]' CHECK (jsonb_typeof(held_transactions) = 'array')");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE app.online_payments DROP COLUMN held_transactions');
    }
};
