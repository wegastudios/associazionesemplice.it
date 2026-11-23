<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;

/*
 * I controlli notturni girano uno dopo l'altro dentro un solo comando (H36), non ognuno con il suo orario.
 */

function nightOutput(string $day): string
{
    test()->travelTo($day.' 02:23:00');
    Artisan::call('saas:night');

    return Artisan::output();
}

it('esegue i controlli in sequenza, senza gli archivi nei giorni feriali', function (): void {
    $out = nightOutput('2026-11-23');

    $positions = array_map(fn (string $c) => strpos($out, "== {$c}"), ['saas:receipts:verify', 'saas:audit:heads:copy', 'saas:audit:verify', 'saas:balances:verify']);
    $sorted = $positions;
    sort($sorted);

    expect(in_array(false, $positions, true))->toBeFalse()
        ->and($positions)->toBe($sorted)
        ->and($out)->not->toContain('== saas:archive:verify');
});

it('la domenica controlla anche gli archivi, per ultimi', function (): void {
    $out = nightOutput('2026-11-22');

    expect($out)->toContain('== saas:archive:verify')
        ->and(strpos($out, '== saas:archive:verify'))->toBeGreaterThan((int) strpos($out, '== saas:balances:verify'));
});

it('nello scheduler c\'è un solo lavoro notturno e non più i controlli separati', function (): void {
    $commands = array_map(static fn ($event): string => (string) $event->command, app(Schedule::class)->events());
    $has = static fn (string $needle): array => array_values(array_filter($commands, static fn (string $c): bool => str_contains($c, $needle)));

    expect($has('saas:night'))->toHaveCount(1)
        ->and($has('saas:receipts:verify'))->toBe([])
        ->and($has('saas:audit:verify'))->toBe([])
        ->and($has('saas:audit:heads:copy'))->toBe([])
        ->and($has('saas:balances:verify'))->toBe([])
        ->and($has('saas:archive:verify'))->toBe([]);

    $night = array_values(array_filter(app(Schedule::class)->events(), static fn ($e): bool => str_contains((string) $e->command, 'saas:night')));
    expect($night[0]->expression)->toBe('23 2 * * *');
});
