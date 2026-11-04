<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Verifica in due passaggi (TOTP, Laravel Fortify). Segreto e codici di recupero sono cifrati da Laravel con la
 * chiave dell'applicazione prima di arrivare qui.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('core.users', function (Blueprint $table): void {
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestampTz('two_factor_confirmed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('core.users', function (Blueprint $table): void {
            $table->dropColumn(['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at']);
        });
    }
};
