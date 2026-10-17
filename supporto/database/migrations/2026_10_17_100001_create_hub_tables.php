<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tabelle dell'hub. Le migrazioni di entrambi i ruoli girano ovunque (schema unico, come il codice):
 * le tabelle del ruolo non usato restano vuote.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Siti di supporto registrati: credenziali e regole per portale
        Schema::create('sites', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name', 120);
            $table->string('client_id', 40)->unique();
            $table->string('secret_hash', 64);
            $table->string('previous_secret_hash', 64)->nullable();
            $table->timestamp('previous_secret_expires_at')->nullable();
            // Segreto HMAC per la firma degli eventi (fase 2), cifrato
            $table->text('hmac_secret_encrypted')->nullable();
            $table->string('url', 255)->nullable();
            $table->string('portal_email', 190);
            // Livelli di priorita (0-4) che il sito puo assegnare
            $table->json('allowed_priorities');
            $table->boolean('allow_reopen')->default(true);
            // Indirizzi aggiuntivi avvisati per le priorita alte
            $table->json('notify_emails')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        // Contatore unico degli id dei ticket (riga con id = 1, bloccata in transazione)
        Schema::create('ticket_counters', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedBigInteger('last_id')->default(0);
        });
        DB::table('ticket_counters')->insert(['id' => 1, 'last_id' => 0]);

        Schema::create('tickets', function (Blueprint $table) {
            // Id anonimo e crescente tra tutti i portali: assegnato dal generatore, non auto-incrementale
            $table->unsignedBigInteger('id')->primary();
            $table->foreignUlid('site_id')->constrained()->restrictOnDelete();
            $table->uuid('request_uuid');
            $table->string('subject', 200);
            $table->unsignedTinyInteger('priority')->default(2);
            // open | pending | on_hold | solved | closed
            $table->string('status', 20)->default('open');
            $table->string('topic', 120)->nullable();
            $table->string('plan', 120)->nullable();
            $table->string('customer_name', 150)->nullable();
            $table->string('customer_email', 190)->nullable();
            $table->unsignedBigInteger('related_ticket_id')->nullable();
            // Accettazione della privacy registrata dal sito all'apertura del ticket
            $table->timestamp('privacy_accepted_at')->nullable();
            $table->string('privacy_version', 40)->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();
            $table->unique(['site_id', 'request_uuid']);
            $table->index(['status', 'priority']);
            $table->index('last_activity_at');
        });

        // Una sola email per ticket e per livello raggiunto
        Schema::create('priority_notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('ticket_id');
            $table->unsignedTinyInteger('level');
            $table->json('recipients');
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['ticket_id', 'level']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('priority_notifications');
        Schema::dropIfExists('tickets');
        Schema::dropIfExists('ticket_counters');
        Schema::dropIfExists('sites');
    }
};
