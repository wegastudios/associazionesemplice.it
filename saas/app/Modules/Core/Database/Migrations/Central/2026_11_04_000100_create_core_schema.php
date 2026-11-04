<?php

declare(strict_types=1);

use App\Modules\Core\Contracts\OrgSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Schema core: solo nel database centrale. Associazioni, utenti di accesso, ruoli, sessioni, code.
 * Niente Row-Level Security qui: l'accesso è ristretto dai permessi del ruolo dell'applicazione.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS citext');
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        DB::statement('CREATE SCHEMA core');
        DB::statement('REVOKE ALL ON SCHEMA core FROM PUBLIC');
        DB::statement('GRANT USAGE ON SCHEMA core TO '.OrgSchema::appRole());

        Schema::create('core.users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->text('name');
            $table->addColumn('citext', 'email')->unique();
            $table->timestampTz('email_verified_at')->nullable();
            $table->text('password');
            $table->text('remember_token')->nullable();
            $table->timestampsTz();
        });
        OrgSchema::grant('core.users', ['SELECT', 'INSERT', 'UPDATE']);

        Schema::create('core.orgs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->addColumn('citext', 'slug')->unique();
            $table->text('name');
            // null = database condiviso; altrimenti il nome del database dedicato (DatabaseRegistry).
            $table->text('database')->nullable();
            $table->text('status')->default('active');
            $table->timestampsTz();
            $table->timestampTz('deleted_at')->nullable();
        });
        DB::statement("ALTER TABLE core.orgs ADD CONSTRAINT orgs_status_check CHECK (status IN ('active', 'suspended', 'closed'))");
        DB::statement("ALTER TABLE core.orgs ADD CONSTRAINT orgs_slug_format CHECK (slug ~ '^[a-z0-9]([a-z0-9-]{1,38})[a-z0-9]$')");
        DB::statement("ALTER TABLE core.orgs ADD CONSTRAINT orgs_database_format CHECK (database IS NULL OR database ~ '^[a-z][a-z0-9_]{1,30}$')");
        OrgSchema::grant('core.orgs', ['SELECT', 'INSERT', 'UPDATE']);

        Schema::create('core.org_users', function (Blueprint $table): void {
            $table->uuid('org_id');
            $table->uuid('user_id');
            $table->text('role');
            $table->timestampsTz();
            $table->primary(['org_id', 'user_id']);
            $table->foreign('org_id')->references('id')->on('core.orgs');
            $table->foreign('user_id')->references('id')->on('core.users');
            $table->index(['user_id', 'org_id']);
        });
        DB::statement("ALTER TABLE core.org_users ADD CONSTRAINT org_users_role_check CHECK (role IN ('owner', 'admin', 'operator', 'accountant'))");
        OrgSchema::grant('core.org_users', ['SELECT', 'INSERT', 'UPDATE', 'DELETE']);

        Schema::create('core.password_reset_tokens', function (Blueprint $table): void {
            $table->addColumn('citext', 'email')->primary();
            $table->text('token');
            $table->timestampTz('created_at')->nullable();
        });
        OrgSchema::grant('core.password_reset_tokens', ['SELECT', 'INSERT', 'UPDATE', 'DELETE']);

        Schema::create('core.sessions', function (Blueprint $table): void {
            $table->text('id')->primary();
            $table->uuid('user_id')->nullable()->index();
            $table->text('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->text('payload');
            $table->integer('last_activity')->index();
        });
        OrgSchema::grant('core.sessions', ['SELECT', 'INSERT', 'UPDATE', 'DELETE']);

        // Cache su database solo in sviluppo; in produzione Redis, sempre con scadenza.
        Schema::create('core.cache', function (Blueprint $table): void {
            $table->text('key')->primary();
            $table->text('value');
            $table->bigInteger('expiration')->index();
        });
        Schema::create('core.cache_locks', function (Blueprint $table): void {
            $table->text('key')->primary();
            $table->text('owner');
            $table->bigInteger('expiration')->index();
        });
        OrgSchema::grant('core.cache', ['SELECT', 'INSERT', 'UPDATE', 'DELETE']);
        OrgSchema::grant('core.cache_locks', ['SELECT', 'INSERT', 'UPDATE', 'DELETE']);

        // Code dei lavori su Postgres. Il payload contiene solo identificativi, mai dati personali.
        Schema::create('core.jobs', function (Blueprint $table): void {
            $table->id();
            $table->text('queue')->index();
            $table->text('payload');
            $table->unsignedSmallInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
        Schema::create('core.job_batches', function (Blueprint $table): void {
            $table->text('id')->primary();
            $table->text('name');
            $table->integer('total_jobs');
            $table->integer('pending_jobs');
            $table->integer('failed_jobs');
            $table->text('failed_job_ids');
            $table->text('options')->nullable();
            $table->integer('cancelled_at')->nullable();
            $table->integer('created_at');
            $table->integer('finished_at')->nullable();
        });
        Schema::create('core.failed_jobs', function (Blueprint $table): void {
            $table->id();
            $table->text('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->text('payload');
            $table->text('exception');
            $table->timestampTz('failed_at')->useCurrent();
        });
        foreach (['core.jobs', 'core.job_batches', 'core.failed_jobs'] as $table) {
            OrgSchema::grant($table, ['SELECT', 'INSERT', 'UPDATE', 'DELETE']);
        }
        DB::statement('GRANT USAGE ON SEQUENCE core.jobs_id_seq, core.failed_jobs_id_seq TO '.OrgSchema::appRole());
    }

    public function down(): void
    {
        DB::statement('DROP SCHEMA core CASCADE');
    }
};
