<?php

declare(strict_types=1);

use App\Modules\Attivita\Contracts\ActivityOccupancy;
use App\Modules\Attivita\Services\ActivityService;
use App\Modules\Attivita\Services\SessionService;
use App\Modules\Portale\Contracts\Entitlements;
use App\Modules\Portale\Contracts\OrgEntitlements;
use App\Modules\Risorse\Services\ResourceService;

// Dati di prova inventati. I controlli di sovrapposizione leggono dal database dell'associazione, anche se dedicato.
beforeEach(function (): void {
    $this->useDedicatedDatabase();
    $this->travelTo('2026-03-10 10:00:00');
    app()->instance(Entitlements::class, new class implements Entitlements
    {
        public function for(string $orgId): OrgEntitlements
        {
            return new OrgEntitlements(1, 'active', null, null, 150, 3, 5, ['resources' => ['hourly' => ['tier' => 10, 'web' => false]]], null);
        }
    });
});

it('vede le date degli eventi che occupano una risorsa anche nel database dedicato', function (): void {
    $org = $this->createOrg('Fondazione Gamma', $this::DEDICATED);
    $day = now('Europe/Rome')->addDays(3)->format('Y-m-d');

    $this->orgContext()->run($org->id, function () use ($day): void {
        $room = app(ResourceService::class)->save(null, ['name' => 'Sala', 'category' => 'room', 'typology' => 'hourly', 'open_time' => '08:00', 'close_time' => '22:00',
            'slot_minutes' => 60, 'min_slots' => 1, 'max_slots' => 4, 'fee_cents' => 1000])->id;
        $event = app(ActivityService::class)->save(null, [
            'name' => 'Serata', 'kind' => 'recurring', 'social_year' => now()->format('Y'), 'fee_cents' => 1000, 'resource_id' => $room, 'no_resource' => false,
            'block_on' => true, 'block_name' => 'Serata di gala',
        ]);
        app(SessionService::class)->addDates($event->id, $day, null, false, '09:00', '12:00', null, null);

        expect(app(ActivityOccupancy::class)->busy($room, $day.' 00:00:00', $day.' 23:59:00'))->toHaveCount(1)
            ->and(app(ActivityOccupancy::class)->blockSpan($room, (string) $event->resource_block))->toBe(['from' => $day.' 09:00:00', 'to' => $day.' 12:00:00']);
    });
});
