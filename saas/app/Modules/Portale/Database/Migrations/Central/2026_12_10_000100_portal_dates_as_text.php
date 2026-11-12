<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Lo schema `portal` tiene date e booleani come nel portale: stringhe UTC `Y-m-d H:i:s` (varchar(19)) e interi 0/1, così il
 * portale legge e scrive senza conversioni. Converte chi era stato creato con timestamptz e boolean (la prima versione).
 */
return new class extends Migration
{
    /** @var array<string, list<string>> */
    private const DATES = [
        'subscriptions' => ['expires_at', 'created_at', 'updated_at', 'reminded_for', 'trial_used_at', 'last_seen_at', 'domain_changed_at'],
        'orders' => ['created_at', 'updated_at', 'paid_at'],
        'saas_orgs' => ['created_at'],
        'saas_outbox' => ['next_at', 'done_at', 'created_at'],
        'releases' => ['created_at'],
        'downloads' => ['at'],
        'email_templates' => ['updated_at'],
        'audit' => ['at'],
        'events' => ['processed_at'],
    ];

    /** @var array<string, list<string>> */
    private const FLAGS = [
        'subscriptions' => ['cancel_at_period_end'],
        'orders' => ['cancel_at_period_end'],
        'email_templates' => ['enabled'],
    ];

    public function up(): void
    {
        foreach (self::DATES as $table => $columns) {
            foreach ($columns as $column) {
                if ($this->type($table, $column) === 'timestamp with time zone') {
                    DB::statement("ALTER TABLE portal.{$table} ALTER COLUMN {$column} TYPE varchar(19) USING to_char({$column} AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS')");
                }
            }
        }
        foreach (self::FLAGS as $table => $columns) {
            foreach ($columns as $column) {
                if ($this->type($table, $column) === 'boolean') {
                    DB::statement("ALTER TABLE portal.{$table} ALTER COLUMN {$column} DROP DEFAULT");
                    DB::statement("ALTER TABLE portal.{$table} ALTER COLUMN {$column} TYPE integer USING {$column}::int");
                    DB::statement("ALTER TABLE portal.{$table} ALTER COLUMN {$column} SET DEFAULT ".($column === 'enabled' ? '1' : '0'));
                }
            }
        }
    }

    public function down(): void {}

    private function type(string $table, string $column): ?string
    {
        $row = DB::selectOne("SELECT data_type FROM information_schema.columns WHERE table_schema = 'portal' AND table_name = ? AND column_name = ?", [$table, $column]);

        $type = $row === null ? null : ((array) $row)['data_type'] ?? null;

        return is_string($type) ? $type : null;
    }
};
