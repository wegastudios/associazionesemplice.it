<?php

declare(strict_types=1);

use App\Modules\Attivita\Contracts\ActivityError;
use App\Modules\Attivita\Contracts\ResourceChangeConflict;
use App\Modules\Attivita\Models\Activity;
use App\Modules\Attivita\Services\ActivityService;
use App\Modules\Attivita\Services\SessionService;
use App\Modules\Portale\Contracts\Entitlements;
use App\Modules\Portale\Contracts\OrgEntitlements;
use App\Modules\Risorse\Contracts\ResourceError;
use App\Modules\Risorse\Services\ReservationService;
use App\Modules\Risorse\Services\ResourceService;
use App\Modules\Soci\Models\Person;

// Dati di prova inventati.
beforeEach(function (): void {
    $this->org = $this->createOrg('Polisportiva Alfa');
    app()->instance(Entitlements::class, new class implements Entitlements
    {
        public function for(string $orgId): OrgEntitlements
        {
            return new OrgEntitlements(1, 'active', null, null, 150, 3, 5, ['resources' => ['hourly' => ['tier' => 10, 'web' => false]]], null);
        }
    });
    $this->day = now('Europe/Rome')->addDays(3)->format('Y-m-d');
});

function room(string $name): string
{
    return app(ResourceService::class)->save(null, ['name' => $name, 'category' => 'room', 'typology' => 'hourly', 'open_time' => '08:00', 'close_time' => '22:00',
        'slot_minutes' => 60, 'min_slots' => 1, 'max_slots' => 4, 'fee_cents' => 1000])->id;
}

function activityOn(string $name, ?string $resourceId, array $over = []): Activity
{
    return app(ActivityService::class)->save(null, $over + [
        'name' => $name, 'kind' => 'recurring', 'social_year' => now()->format('Y'), 'fee_cents' => 1000,
        'resource_id' => $resourceId, 'no_resource' => $resourceId === null,
    ]);
}

it('due attività sulla stessa risorsa ne condividono il calendario', function (): void {
    $this->orgContext()->run($this->org->id, function (): void {
        $court = room('Campo 1');
        $tennis = activityOn('Tennis', $court);
        $volley = activityOn('Pallavolo', $court);
        $s = app(SessionService::class);

        expect($s->addDates($tennis->id, $this->day, null, false, '18:00', '19:00', null, 4))->toBe(1)
            ->and(fn () => $s->addDates($volley->id, $this->day, null, false, '18:30', '19:30', null, 4))->toThrow(ActivityError::class, 'è occupata')
            ->and($s->addDates($volley->id, $this->day, null, false, '19:00', '20:00', null, 4))->toBe(1);
    });
});

it('una prenotazione della risorsa e una data di attività non si sovrappongono, nei due sensi', function (): void {
    $this->orgContext()->run($this->org->id, function (): void {
        $court = room('Campo 1');
        $a = activityOn('Tennis', $court);
        $res = app(ReservationService::class);
        $res->block($court, $this->day.' 10:00', $this->day.' 11:00', 'Manutenzione', null);

        $s = app(SessionService::class);
        expect(fn () => $s->addDates($a->id, $this->day, null, false, '10:30', '11:30', null, 4))->toThrow(ActivityError::class, 'blocco')
            ->and($s->addDates($a->id, $this->day, null, false, '12:00', '13:00', null, 4))->toBe(1)
            ->and(fn () => $res->block($court, $this->day.' 12:30', $this->day.' 13:30', null, null))->toThrow(ResourceError::class, 'occupata dall\'attività «Tennis»');
    });
});

it('senza risorsa o fuori sede non si controlla niente', function (): void {
    $this->orgContext()->run($this->org->id, function (): void {
        $a = activityOn('Gita', null);
        $b = activityOn('Gita 2', null);
        $s = app(SessionService::class);

        expect($s->addDates($a->id, $this->day, null, false, '09:00', '10:00', null, 4))->toBe(1)
            ->and($s->addDates($b->id, $this->day, null, false, '09:00', '10:00', null, 4))->toBe(1);
    });
});

it('con «occupa solo se prenotata» la risorsa è occupata dalle date con prenotazioni valide', function (): void {
    $this->orgContext()->run($this->org->id, function (): void {
        $court = room('Campo 1');
        $a = activityOn('Tennis', $court, ['occupy_on_booking' => true]);
        $s = app(SessionService::class);
        $res = app(ReservationService::class);
        $s->addDates($a->id, $this->day, null, false, '18:00', '19:00', null, 4);

        // Nessuna prenotazione alla data: la risorsa è libera.
        expect($res->block($court, $this->day.' 18:00', $this->day.' 19:00', null, null))->toBeString();
    });
});

it('una prenotazione alla data su una fascia occupata altrove è rifiutata', function (): void {
    $this->orgContext()->run($this->org->id, function (): void {
        $court = room('Campo 1');
        $a = activityOn('Tennis', $court, ['occupy_on_booking' => true]);
        $s = app(SessionService::class);
        $s->addDates($a->id, $this->day, null, false, '18:00', '19:00', null, 4);
        $session = $s->sessions($a)[0]['id'];
        $anna = Person::create(['first_name' => 'Anna', 'last_name' => 'Rossi']);
        app(ReservationService::class)->block($court, $this->day.' 18:30', $this->day.' 19:30', null, null);

        expect(fn () => $s->book($session, $anna->id))->toThrow(ActivityError::class, 'è occupata');
    });
});

it('una data prenotata occupa la risorsa e la blocca a chi la vuole prenotare', function (): void {
    $this->orgContext()->run($this->org->id, function (): void {
        $court = room('Campo 1');
        $a = activityOn('Tennis', $court, ['occupy_on_booking' => true]);
        $s = app(SessionService::class);
        $s->addDates($a->id, $this->day, null, false, '18:00', '19:00', null, 4);
        $session = $s->sessions($a)[0]['id'];
        $anna = Person::create(['first_name' => 'Anna', 'last_name' => 'Rossi']);
        $s->book($session, $anna->id);

        expect(fn () => app(ReservationService::class)->block($court, $this->day.' 18:00', $this->day.' 19:00', null, null))
            ->toThrow(ResourceError::class, 'occupata dall\'attività');
    });
});

it('cambiando risorsa segnala le sovrapposizioni e si risolve lasciando tutto o spostando le prenotazioni', function (): void {
    $this->orgContext()->run($this->org->id, function (): void {
        $old = room('Sala A');
        $new = room('Sala B');
        $a = activityOn('Yoga', $old);
        app(SessionService::class)->addDates($a->id, $this->day, null, false, '18:00', '19:00', null, 4);
        $block = app(ReservationService::class)->block($new, $this->day.' 18:00', $this->day.' 19:00', 'Torneo', null);
        $svc = app(ActivityService::class);

        // Il cambio non si applica e si dice che cosa si sovrappone.
        try {
            $svc->save($a->id, change($a, $new));
            $thrown = null;
        } catch (ResourceChangeConflict $e) {
            $thrown = $e;
        }
        expect($thrown)->not->toBeNull()->and($thrown->movable)->toBeTrue()->and($thrown->conflicts)->toHaveCount(1)
            ->and(Activity::query()->find($a->id)->resource_id)->toBe($old);

        // Spostando, il blocco passa alla risorsa che si libera e la risorsa dell'attività cambia.
        $svc->save($a->id, change($a, $new), true);
        expect(Activity::query()->find($a->id)->resource_id)->toBe($new)
            ->and(app(ReservationService::class)->find($block)->resource_id)->toBe($old);
    });
});

it('non sposta se la sovrapposizione è con un\'altra attività', function (): void {
    $this->orgContext()->run($this->org->id, function (): void {
        $old = room('Sala A');
        $new = room('Sala B');
        $s = app(SessionService::class);
        $a = activityOn('Yoga', $old);
        $other = activityOn('Danza', $new);
        $s->addDates($a->id, $this->day, null, false, '18:00', '19:00', null, 4);
        $s->addDates($other->id, $this->day, null, false, '18:00', '19:00', null, 4);

        try {
            app(ActivityService::class)->save($a->id, change($a, $new));
            $thrown = null;
        } catch (ResourceChangeConflict $e) {
            $thrown = $e;
        }
        expect($thrown?->movable)->toBeFalse()
            ->and(fn () => app(ActivityService::class)->save($a->id, change($a, $new), true))->toThrow(ActivityError::class, 'Non si possono spostare');
    });
});

it('chiede la risorsa dal modulo: una risorsa o «Nessuna»', function (): void {
    $op = $this->createUser('anna@example.com', twoFactor: true);
    $this->addOperator($this->org, $op, App\Modules\Core\Contracts\OrgRole::Owner);
    $this->actingAs($op);
    $base = ['name' => 'Yoga', 'kind' => 'course', 'social_year' => now()->format('Y'), 'billing' => 'monthly', 'fee' => '30,00'];

    $this->post('/gestionale/attivita', $base)->assertSessionHasErrors('resource');
    $this->post('/gestionale/attivita', $base + ['resource' => 'boh'])->assertSessionHasErrors('resource');
    $this->post('/gestionale/attivita', $base + ['resource' => 'none'])->assertSessionHasNoErrors();
});

/** I dati completi di un'attività con un'altra risorsa (il salvataggio valida tutti i campi). */
function change(Activity $a, string $resourceId): array
{
    return ['name' => $a->name, 'kind' => $a->kind->value, 'social_year' => $a->social_year, 'fee_cents' => $a->fee_cents, 'resource_id' => $resourceId, 'no_resource' => false];
}

it('una data senza orari occupa il giorno intero', function (): void {
    $this->orgContext()->run($this->org->id, function (): void {
        $court = room('Campo 1');
        $tennis = activityOn('Torneo', $court);
        $volley = activityOn('Pallavolo', $court);
        $s = app(SessionService::class);

        expect($s->addDates($tennis->id, $this->day, null, false, null, null, null, null))->toBe(1)
            ->and(fn () => $s->addDates($volley->id, $this->day, null, false, '21:00', '22:00', null, 4))->toThrow(ActivityError::class, 'già l'attività «Torneo»')
            ->and(fn () => app(ReservationService::class)->block($court, $this->day.' 09:00', $this->day.' 10:00', null, null))->toThrow(ResourceError::class, 'occupata dall\'attività «Torneo»')
            ->and(app(ReservationService::class)->block($court, date('Y-m-d', strtotime($this->day.' +1 day')).' 09:00', date('Y-m-d', strtotime($this->day.' +1 day')).' 10:00', null, null))->toBeString();
    });
});

it('un corso occupa la risorsa per intero nei giorni delle sue lezioni', function (): void {
    $this->orgContext()->run($this->org->id, function (): void {
        $room = room('Sala A');
        $weekday = (int) date('N', (int) strtotime($this->day));
        $course = fn (string $name) => activityOn($name, $room, ['kind' => 'course', 'billing' => 'monthly', 'weekdays' => [$weekday], 'starts_on' => $this->day, 'ends_on' => date('Y-m-d', strtotime($this->day.' +14 days'))]);

        $course('Yoga');
        expect(fn () => $course('Pilates'))->toThrow(ActivityError::class, 'giorni delle lezioni')
            ->and(fn () => app(ReservationService::class)->block($room, $this->day.' 18:00', $this->day.' 19:00', null, null))->toThrow(ResourceError::class, 'occupata dall\'attività «Yoga»');

        // Un altro giorno della settimana è libero.
        $other = date('Y-m-d', strtotime($this->day.' +1 day'));
        expect(app(ReservationService::class)->block($room, $other.' 18:00', $other.' 19:00', null, null))->toBeString();
    });
});
