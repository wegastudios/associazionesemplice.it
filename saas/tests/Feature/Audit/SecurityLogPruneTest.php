<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * I registri di sicurezza (accessi degli operatori e dello staff) si tengono 18 mesi. Il registro degli eventi è «solo
 * in aggiunta»: cancella il comando, per questa sola operazione. Il proprietario non vede la transazione del test: i
 * dati si confermano davvero e si cancellano alla fine.
 */

beforeEach(function (): void {
    DB::commit();
    $this->owner = DB::connection('pgsql_owner');
    $this->owner->statement('TRUNCATE core.security_events, core.staff_audit');
});

afterEach(function (): void {
    $this->owner->statement('TRUNCATE core.security_events, core.staff_audit');
    DB::beginTransaction();
});

function seedSecurityRow(string $table, string $column, string $name, string $at): void
{
    $row = ['id' => (string) Str::uuid7(), $column => $at, ($table === 'core.security_events' ? 'event' : 'action') => $name];
    DB::connection('pgsql_owner')->table($table)->insert($row);
}

it('toglie i registri di sicurezza oltre i 18 mesi e lascia il resto, e il registro resta solo in aggiunta', function (): void {
    seedSecurityRow('core.security_events', 'occurred_at', 'auth.failed', now()->subMonths(19)->toDateTimeString());
    seedSecurityRow('core.security_events', 'occurred_at', 'auth.login', now()->subMonths(17)->toDateTimeString());
    seedSecurityRow('core.staff_audit', 'created_at', 'staff.login_failed', now()->subMonths(19)->toDateTimeString());
    seedSecurityRow('core.staff_audit', 'created_at', 'staff.login', now()->subDay()->toDateTimeString());

    $this->artisan('saas:audit:prune-security', ['--dry-run' => true])->expectsOutputToContain('1 eventi di accesso, 1 accessi dello staff')->assertSuccessful();
    expect($this->owner->table('core.security_events')->count())->toBe(2);

    $this->artisan('saas:audit:prune-security')->assertSuccessful();

    expect($this->owner->table('core.security_events')->pluck('event')->all())->toBe(['auth.login'])
        ->and($this->owner->table('core.staff_audit')->pluck('action')->all())->toBe(['staff.login'])
        ->and(fn () => $this->owner->table('core.security_events')->delete())->toThrow(QueryException::class, 'solo in aggiunta');
});

it('non tiene meno di 12 mesi', function (): void {
    seedSecurityRow('core.security_events', 'occurred_at', 'auth.login', now()->subMonths(6)->toDateTimeString());

    $this->artisan('saas:audit:prune-security', ['--months' => 3])->assertFailed();

    expect($this->owner->table('core.security_events')->count())->toBe(1);
});

it('è pianificato ogni settimana', function (): void {
    $events = array_values(array_filter(
        app(Schedule::class)->events(),
        static fn ($event): bool => str_contains((string) $event->command, 'saas:audit:prune-security'),
    ));

    expect($events)->toHaveCount(1);
});
