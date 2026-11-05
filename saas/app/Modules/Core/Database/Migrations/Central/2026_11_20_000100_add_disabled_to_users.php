<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Utente disattivato dall'amministrazione della piattaforma: non entra più, le sessioni aperte si chiudono. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('core.users', function (Blueprint $table): void {
            $table->timestampTz('disabled_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('core.users', function (Blueprint $table): void {
            $table->dropColumn('disabled_at');
        });
    }
};
