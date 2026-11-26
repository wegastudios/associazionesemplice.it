<?php

declare(strict_types=1);

use App\Modules\Core\Demo\DemoSchedule;
use Illuminate\Console\Scheduling\Schedule;

/*
 * Nella demo girano solo i lavori interni (docs/DEMO.md): backup, servizio chiavi, promemoria, ciclo di vita, portale,
 * pagamenti restano spenti. L'elenco è dei permessi: un lavoro nuovo non classificato fa fallire questa prova.
 */

/** Tutti i lavori che la piattaforma pianifica, con ogni interruttore acceso, come in un ambiente completo. */
function fullSchedule(): Schedule
{
    config([
        'saas.audit_prune_enabled' => true, 'saas.portal.purge_enabled' => true, 'saas.portal.notices_enabled' => true,
        'saas.demo.enabled' => false,
    ]);

    return app(Schedule::class);
}

it('ogni lavoro pianificato è classificato: o gira nella demo o è spento con un motivo', function (): void {
    $unknown = [];
    foreach (fullSchedule()->events() as $event) {
        $command = (string) $event->command;
        $off = false;
        foreach (array_keys(DemoSchedule::OFF) as $name) {
            $off = $off || str_contains($command, $name);
        }
        if (! DemoSchedule::allowed($command) && ! $off) {
            $unknown[] = $command;
        }
    }

    expect($unknown)->toBe([]);
});

it('nessun lavoro permesso è anche nell\'elenco dei spenti', function (): void {
    foreach (array_keys(DemoSchedule::OFF) as $name) {
        expect(DemoSchedule::allowed($name))->toBeFalse($name);
    }
});

it('con la demo accesa i lavori verso l\'esterno sono saltati e gli interni restano', function (): void {
    $schedule = fullSchedule();
    demoOn();
    DemoSchedule::restrict($schedule);

    $off = [];
    $on = [];
    foreach ($schedule->events() as $event) {
        $command = (string) $event->command;
        if (DemoSchedule::allowed($command)) {
            $on[] = $command;
        } else {
            $off[] = $command;
            expect($event->filtersPass(app()))->toBeFalse($command);
        }
    }

    $joined = implode("\n", $off);
    // I backup si pianificano solo in produzione e staging (BackupServiceProvider): qui non ci sono, nella demo nemmeno.
    expect($joined)->not->toContain('saas:backup:dump')->toContain('saas:keys:monitor')->toContain('saas:reminders:send')
        ->toContain('saas:portal:lifecycle-notices')->toContain('saas:payments:requeue-webhooks')->toContain('saas:portal:verify-domains')
        ->and(implode("\n", $on))->toContain('saas:tmp:purge');
});

it('fuori dalla demo il guardiano dello schedule non spegne niente', function (): void {
    $schedule = fullSchedule();
    $before = count(array_filter($schedule->events(), static fn ($e): bool => $e->filtersPass(app())));
    config(['saas.demo.enabled' => false]);
    DemoSchedule::restrict($schedule);

    expect(count(array_filter($schedule->events(), static fn ($e): bool => $e->filtersPass(app()))))->toBe($before);
});
