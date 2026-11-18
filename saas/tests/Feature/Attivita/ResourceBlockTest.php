<?php

declare(strict_types=1);

use App\Modules\Attivita\Contracts\ActivityError;
use App\Modules\Attivita\Contracts\ActivityOccupancy;
use App\Modules\Attivita\Models\Activity;
use App\Modules\Attivita\Rules\SessionRules;
use App\Modules\Attivita\Services\ActivityService;
use App\Modules\Attivita\Services\SessionService;
use App\Modules\Portale\Contracts\Entitlements;
use App\Modules\Portale\Contracts\OrgEntitlements;
use App\Modules\Risorse\Services\ResourceService;

// Dati di prova inventati.
beforeEach(function (): void {
    $this->org = $this->createOrg('Circolo Nautico');
    app()->instance(Entitlements::class, new class implements Entitlements
    {
        public function for(string $orgId): OrgEntitlements
        {
            return new OrgEntitlements(1, 'active', null, null, 150, 3, 5, ['resources' => ['hourly' => ['tier' => 10, 'web' => false]]], null);
        }
    });
    $this->day = now('Europe/Rome')->addDays(3)->format('Y-m-d');
});

function blockPlus(string $day, int $n): string
{
    return date('Y-m-d', (int) strtotime($day.' +'.$n.' days'));
}

function blockRoom(string $name): string
{
    return app(ResourceService::class)->save(null, ['name' => $name, 'category' => 'room', 'typology' => 'hourly', 'open_time' => '08:00', 'close_time' => '22:00',
        'slot_minutes' => 60, 'min_slots' => 1, 'max_slots' => 4, 'fee_cents' => 1000])->id;
}

/** @param  array<string, mixed>  $over */
function blockEvent(string $name, string $resourceId, array $over = []): Activity
{
    return app(ActivityService::class)->save(null, $over + [
        'name' => $name, 'kind' => 'recurring', 'social_year' => now()->format('Y'), 'fee_cents' => 1000, 'resource_id' => $resourceId, 'no_resource' => false,
    ]);
}

it('tiene occupata la risorsa dal primo all\'ultimo giorno di eventi collegati, anche tra un evento e l\'altro', function (): void {
    $this->orgContext()->run($this->org->id, function (): void {
        $lake = blockRoom('Banchina');
        $s = app(SessionService::class);
        $a = blockEvent('Regata sabato', $lake, ['block_on' => true, 'block_name' => 'Regata di giugno']);
        $key = (string) $a->resource_block;
        $b = blockEvent('Regata mercoledì', $lake, ['block_on' => true, 'block_with' => $key]);
        $s->addDates($a->id, $this->day, null, false, '09:00', '12:00', null, null);
        $s->addDates($b->id, blockPlus($this->day, 3), null, false, '09:00', '12:00', null, null);

        expect($b->resource_block)->toBe($key)->and($b->resource_block_label)->toBe('Regata di giugno')
            ->and(app(ActivityOccupancy::class)->blockSpan($lake, $key))->toBe(['from' => $this->day.' 09:00:00', 'to' => blockPlus($this->day, 3).' 12:00:00']);

        // Nel mezzo (tra un evento e l'altro) e a cavallo del periodo non si può.
        $other = blockEvent('Corso di vela', $lake);
        expect(fn () => $s->addDates($other->id, blockPlus($this->day, 1), null, false, '15:00', '16:00', null, null))->toThrow(ActivityError::class, 'è occupata')
            ->and(fn () => $s->addDates($other->id, blockPlus($this->day, 3), null, false, '11:00', '13:00', null, null))->toThrow(ActivityError::class, 'Regata')
            ->and($s->addDates($other->id, blockPlus($this->day, 3), null, false, '12:00', '13:00', null, null))->toBe(1)
            ->and($s->addDates($other->id, blockPlus($this->day, -1), null, false, '09:00', '10:00', null, null))->toBe(1);
    });
});

it('si ricalcola da solo: annullare, spostare o togliere gli eventi cambia il periodo', function (): void {
    $this->orgContext()->run($this->org->id, function (): void {
        $lake = blockRoom('Banchina');
        $s = app(SessionService::class);
        $occupancy = app(ActivityOccupancy::class);
        $a = blockEvent('Prima gara', $lake, ['block_on' => true, 'block_name' => 'Regata']);
        $b = blockEvent('Seconda gara', $lake, ['block_on' => true, 'block_with' => (string) $a->resource_block]);
        $s->addDates($a->id, $this->day, null, false, null, null, null, null);
        $s->addDates($b->id, blockPlus($this->day, 4), null, false, null, null, null, null);
        $key = (string) $a->resource_block;
        $span = static fn () => $occupancy->blockSpan($lake, $key);
        expect($span()['to'])->toBe(blockPlus($this->day, 5).' 00:00:00');

        // Annullata la seconda gara, il periodo torna al primo giorno e il buco si libera.
        $s->cancelSession($s->sessions($b)[0]['id']);
        expect($span()['to'])->toBe(blockPlus($this->day, 1).' 00:00:00');
        $other = blockEvent('Corso', $lake);
        expect($s->addDates($other->id, blockPlus($this->day, 2), null, false, '10:00', '11:00', null, null))->toBe(1);

        // Archiviando l'ultimo evento collegato il blocco sparisce.
        app(ActivityService::class)->archive($a->id, true);
        app(ActivityService::class)->archive($b->id, true);
        expect($occupancy->busy($lake, $this->day.' 00:00:00', blockPlus($this->day, 6).' 00:00:00'))->toHaveCount(1)
            ->and(app(ActivityService::class)->blocksByResource())->toBe([]);
    });
});

it('cambiando la fine o gli orari di una data non conta più la sua posizione vecchia', function (): void {
    $this->orgContext()->run($this->org->id, function (): void {
        $lake = blockRoom('Banchina');
        $s = app(SessionService::class);
        $a = blockEvent('Gara', $lake, ['block_on' => true]);
        $s->addDates($a->id, $this->day, null, false, '09:00', '12:00', null, null);
        $other = blockEvent('Corso', $lake);
        $s->addDates($other->id, blockPlus($this->day, 5), null, false, '09:00', '12:00', null, null);
        $id = $s->sessions($a)[0]['id'];

        expect(fn () => $s->updateSession($id, '09:00', '12:00', null, null, blockPlus($this->day, 5)))->toThrow(ActivityError::class, 'Corso')
            ->and($s->updateSession($id, '10:00', '13:00', null, null))->toBe([])
            ->and(app(ActivityOccupancy::class)->blockSpan($lake, (string) $a->resource_block))->toBe(['from' => $this->day.' 10:00:00', 'to' => $this->day.' 13:00:00']);
    });
});

it('rifiuta un blocco di oltre 31 giorni e uno in conflitto, dicendo con chi', function (): void {
    $this->orgContext()->run($this->org->id, function (): void {
        $lake = blockRoom('Banchina');
        $s = app(SessionService::class);
        $a = blockEvent('Gara uno', $lake, ['block_on' => true]);
        $s->addDates($a->id, $this->day, null, false, '09:00', '12:00', null, null);
        $key = (string) $a->resource_block;

        $far = blockEvent('Gara lontana', $lake);
        $s->addDates($far->id, blockPlus($this->day, 40), null, false, '09:00', '12:00', null, null);
        // Con la spunta su «Gara lontana» il blocco durerebbe 41 giorni.
        expect(fn () => app(ActivityService::class)->save($far->id, ['name' => 'Gara lontana', 'kind' => 'recurring', 'social_year' => now()->format('Y'), 'fee_cents' => 1000,
            'resource_id' => $lake, 'no_resource' => false, 'block_on' => true, 'block_with' => $key]))->toThrow(ActivityError::class, 'Il blocco durerebbe 41 giorni: al massimo 31');

        // Un altro corso occupa un giorno in mezzo: collegare un evento che allarga il periodo viene rifiutato con il suo nome.
        $mid = blockEvent('Corso di vela', $lake);
        $s->addDates($mid->id, blockPlus($this->day, 2), null, false, '10:00', '11:00', null, null);
        $late = blockEvent('Gara tre', $lake);
        $s->addDates($late->id, blockPlus($this->day, 4), null, false, '09:00', '12:00', null, null);
        expect(fn () => app(ActivityService::class)->save($late->id, ['name' => 'Gara tre', 'kind' => 'recurring', 'social_year' => now()->format('Y'), 'fee_cents' => 1000,
            'resource_id' => $lake, 'no_resource' => false, 'block_on' => true, 'block_with' => $key]))->toThrow(ActivityError::class, 'Corso di vela');
        expect(Activity::query()->find($late->id)?->resource_block)->toBeNull();
    });
});

it('due blocchi con lo stesso nome restano separati e il nome è uno per blocco', function (): void {
    $this->orgContext()->run($this->org->id, function (): void {
        $lake = blockRoom('Banchina');
        $a = blockEvent('Gara A', $lake, ['block_on' => true, 'block_name' => 'Regata']);
        $b = blockEvent('Gara B', $lake, ['block_on' => true, 'block_name' => 'Regata']);
        expect($a->resource_block)->not->toBe($b->resource_block)
            ->and(app(ActivityService::class)->blocksByResource()[$lake])->toHaveCount(2);

        $c = blockEvent('Gara C', $lake, ['block_on' => true, 'block_with' => (string) $a->resource_block, 'block_name' => 'Regata di giugno']);
        expect(Activity::query()->find($a->id)?->resource_block_label)->toBe('Regata di giugno')
            ->and(Activity::query()->find($b->id)?->resource_block_label)->toBe('Regata')
            ->and($c->resource_block)->toBe($a->resource_block);
    });
});

it('il blocco vale solo per gli eventi con prenotazioni, con una risorsa e senza «occupa solo se prenotata»', function (): void {
    $this->orgContext()->run($this->org->id, function (): void {
        $lake = blockRoom('Banchina');
        expect(fn () => blockEvent('Gara', $lake, ['occupy_on_booking' => true, 'block_on' => true]))->toThrow(ActivityError::class, 'Il blocco della risorsa vale');

        // Togliendo la spunta, o cambiando risorsa, il blocco cade.
        $a = blockEvent('Gara', $lake, ['block_on' => true]);
        $off = app(ActivityService::class)->save($a->id, ['name' => 'Gara', 'kind' => 'recurring', 'social_year' => now()->format('Y'), 'fee_cents' => 1000, 'resource_id' => $lake, 'no_resource' => false, 'block_on' => false]);
        expect($off->resource_block)->toBeNull();
        $b = blockEvent('Gara due', $lake, ['block_on' => true]);
        $moved = app(ActivityService::class)->save($b->id, ['name' => 'Gara due', 'kind' => 'recurring', 'social_year' => now()->format('Y'), 'fee_cents' => 1000, 'resource_id' => blockRoom('Pontile'), 'no_resource' => false]);
        expect($moved->resource_block)->toBeNull()->and(SessionRules::blockLabel('2026-06-12 09:00:00', '2026-06-14 00:00:00'))->toBe('dal 12/06 al 13/06');
    });
});
