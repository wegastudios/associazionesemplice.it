<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Le tabelle dello schema `portal` si chiamano come nel portale (il suo codice le cerca per nome): orgs → saas_orgs, outbox → saas_outbox. */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['orgs' => 'saas_orgs', 'outbox' => 'saas_outbox'] as $old => $new) {
            $has = static fn (string $t): bool => DB::selectOne("SELECT 1 AS x FROM information_schema.tables WHERE table_schema = 'portal' AND table_name = ?", [$t]) !== null;
            if ($has($old) && ! $has($new)) {
                DB::statement("ALTER TABLE portal.{$old} RENAME TO {$new}");
            }
        }
    }

    public function down(): void {}
};
