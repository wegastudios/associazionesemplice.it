<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Tabelle del sito di supporto di un portale (vuote nell'hub). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('topics', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('slug', 140)->unique();
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            // Un argomento non visibile resta usabile solo dallo staff
            $table->boolean('is_public')->default(true);
            $table->timestamps();
        });

        Schema::create('sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('topic_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('slug', 140);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
            $table->unique(['topic_id', 'slug']);
        });

        Schema::create('articles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('section_id')->constrained()->cascadeOnDelete();
            $table->string('title', 200);
            $table->string('slug', 220);
            $table->longText('body');
            $table->boolean('is_published')->default(false);
            $table->timestamps();
            $table->unique(['section_id', 'slug']);
        });

        // Piani del portale (gestiti dal portale, non dall'hub)
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('support_tickets', function (Blueprint $table) {
            $table->ulid('id')->primary();
            // Id assegnato dall'hub: assente finche il ticket non e registrato
            $table->unsignedBigInteger('hub_id')->nullable()->unique();
            $table->uuid('request_uuid')->unique();
            $table->string('subject', 200);
            $table->foreignId('topic_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained()->nullOnDelete();
            $table->string('customer_name', 150);
            $table->string('customer_email', 190);
            // Priorita centrale 0-4 (regole dell'hub) e priorita interna del portale (libera)
            $table->unsignedTinyInteger('priority')->default(2);
            $table->string('internal_priority', 40)->nullable();
            // open | pending | on_hold | solved | closed
            $table->string('status', 20)->default('open');
            $table->unsignedBigInteger('related_hub_id')->nullable();
            // Accettazione esplicita dell'informativa privacy e del trattamento dei dati (prova: data e versione del testo)
            $table->timestamp('privacy_accepted_at')->nullable();
            $table->string('privacy_version', 40)->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->json('portal_context')->nullable();
            $table->unsignedSmallInteger('registration_attempts')->default(0);
            $table->text('registration_error')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'priority']);
            $table->index('customer_email');
        });

        Schema::create('ticket_messages', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('ticket_id')->constrained('support_tickets')->cascadeOnDelete();
            // customer | staff | note | system
            $table->string('author_type', 20);
            $table->string('author_name', 150)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->longText('body');
            $table->timestamps();
            $table->index(['ticket_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_messages');
        Schema::dropIfExists('support_tickets');
        Schema::dropIfExists('plans');
        Schema::dropIfExists('articles');
        Schema::dropIfExists('sections');
        Schema::dropIfExists('topics');
    }
};
