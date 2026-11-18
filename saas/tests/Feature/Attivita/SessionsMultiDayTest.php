<?php

declare(strict_types=1);

use App\Modules\Attivita\Contracts\ActivityError;
use App\Modules\Attivita\Contracts\MemberActivities;
use App\Modules\Attivita\Export\AttivitaDataExport;
use App\Modules\Attivita\Http\Controllers\SessionsController;
use App\Modules\Attivita\Models\Activity;
use App\Modules\Attivita\Rules\SessionRules;
use App\Modules\Attivita\Services\ActivityService;
use App\Modules\Attivita\Services\AttendanceService;
use App\Modules\Attivita\Services\SessionService;
use App\Modules\Core\Contracts\Operator;
use App\Modules\Core\Contracts\OrgRole;
use App\Modules\Soci\Models\Person;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

// Dati di prova inventati.
beforeEach(function (): void {
    $this->org = $this->createOrg('Polisportiva Alfa');
    $this->year = now()->format('Y');
});

function trip(): Activity
{
    return app(ActivityService::class)->save(null, [
        'name' => 'Uscita lunga', 'kind' => 'recurring', 'social_year' => now()->format('Y'), 'fee_cents' => 1500, 'guest_fee_cents' => 2000,
    ]);
}

it('calcola giorni, fine e sovrapposizioni delle date su più giorni', function (): void {
    expect(SessionRules::endDate('2026-06-12', null))->toBeNull()
        ->and(SessionRules::endDate('2026-06-12', '2026-06-12'))->toBeNull()
        ->and(SessionRules::endDate('2026-06-12', '2026-06-14'))->toBe('2026-06-14')
        ->and(SessionRules::days('2026-06-12', '2026-06-14'))->toBe(3)
        ->and(SessionRules::days('2026-06-12', null))->toBe(1)
        ->and(SessionRules::shifted('2026-06-19', 3))->toBe('2026-06-21')
        ->and(SessionRules::shifted('2026-06-19', 1))->toBeNull()
        ->and(fn () => SessionRules::endDate('2026-06-12', '2026-06-11'))->toThrow(ActivityError::class, 'non può essere prima')
        ->and(fn () => SessionRules::endDate('2026-06-01', '2026-07-02'))->toThrow(ActivityError::class, 'più di 31 giorni')
        ->and(SessionRules::endDate('2026-06-01', '2026-07-01'))->toBe('2026-07-01')
        ->and(SessionRules::overlap('2026-06-12', '2026-06-14', '2026-06-14', null))->toBeTrue()
        ->and(SessionRules::overlap('2026-06-12', '2026-06-14', '2026-06-15', null))->toBeFalse()
        ->and(SessionRules::slot('2026-06-12', '21:30', '01:00', '2026-06-13'))->toBe(['2026-06-12 21:30:00', '2026-06-13 01:00:00'])
        ->and(SessionRules::slot('2026-06-12', null, null, '2026-06-14')[1])->toBe('2026-06-15 00:00:00')
        ->and(SessionsController::rangeLabel('2026-06-12', '2026-06-14'))->toBe('dal 12 al 14 giugno')
        ->and(SessionsController::rangeLabel('2026-05-30', '2026-06-02'))->toBe('dal 30 maggio al 2 giugno');
});

it('salva una data su più giorni, con ora di fine libera, e la rilegge', function (): void {
    $this->orgContext()->run($this->org->id, function (): void {
        $s = app(SessionService::class);
        $a = trip();
        $from = $this->year.'-03-10';
        expect($s->addDates($a->id, $from, null, false, '21:30', '01:00', 'Rifugio', 5, $this->year.'-03-12'))->toBe(1);
        $row = $s->sessions($a)[0];
        expect($row)->toMatchArray(['date' => $from, 'end_date' => $this->year.'-03-12', 'start' => '21:30', 'end' => '01:00']);
        // Sullo stesso giorno l'ora di fine deve restare dopo quella di inizio.
        expect(fn () => $s->addDates($a->id, $this->year.'-04-10', null, false, '21:30', '01:00', null, null))->toThrow(ActivityError::class, 'ora di fine');
        // Il vecchio controllo del database vale solo per le date di un giorno.
        expect(fn () => DB::statement("UPDATE app.activity_sessions SET end_time = '00:30', end_date = NULL, start_time = '09:00' WHERE id = ?", [$row['id']]))->toThrow(QueryException::class, 'activity_sessions_time_check');
    });
});

it('chiede conferma oltre sette giorni e rifiuta oltre trentuno', function (): void {
    $this->orgContext()->run($this->org->id, function (): void {
        $s = app(SessionService::class);
        $a = trip();
        $from = $this->year.'-03-01';
        expect(fn () => $s->addDates($a->id, $from, null, false, null, null, null, null, $this->year.'-03-09'))->toThrow(ActivityError::class, 'Questa data dura 9 giorni: è giusto?')
            ->and($s->addDates($a->id, $from, null, false, null, null, null, null, $this->year.'-03-07'))->toBe(1)
            ->and(fn () => $s->addDates($a->id, $this->year.'-05-01', null, false, null, null, null, null, $this->year.'-06-05', true))->toThrow(ActivityError::class, 'più di 31 giorni')
            ->and(fn () => $s->addDates($a->id, $this->year.'-05-10', null, false, null, null, null, null, $this->year.'-05-09'))->toThrow(ActivityError::class, 'non può essere prima')
            ->and($s->addDates($a->id, $this->year.'-05-01', null, false, null, null, null, null, $this->year.'-05-20', true))->toBe(1);
    });
});

it('non sovrappone due date della stessa attività', function (): void {
    $this->orgContext()->run($this->org->id, function (): void {
        $s = app(SessionService::class);
        $a = trip();
        $s->addDates($a->id, $this->year.'-03-10', null, false, null, null, null, null, $this->year.'-03-12');
        expect(fn () => $s->addDates($a->id, $this->year.'-03-12', null, false, '10:00', null, null, null))->toThrow(ActivityError::class, 'non possono sovrapporsi')
            ->and(fn () => $s->addDates($a->id, $this->year.'-03-09', null, false, null, null, null, null, $this->year.'-03-10'))->toThrow(ActivityError::class, 'non possono sovrapporsi')
            ->and($s->addDates($a->id, $this->year.'-03-13', null, false, null, null, null, null, $this->year.'-03-14'))->toBe(1);
        // Due date di un giorno con orari diversi restano come prima.
        $s->addDates($a->id, $this->year.'-04-01', null, false, '09:00', '10:00', null, null);
        expect($s->addDates($a->id, $this->year.'-04-01', null, false, '11:00', '12:00', null, null))->toBe(1);
    });
});

it('ripete una data su più giorni ogni settimana con lo stesso scarto', function (): void {
    $this->orgContext()->run($this->org->id, function (): void {
        $s = app(SessionService::class);
        $a = trip();
        $monday = date('Y-m-d', (int) strtotime('first monday of '.$this->year.'-03'));
        $until = date('Y-m-d', (int) strtotime($monday.' +14 days'));
        expect($s->addDates($a->id, $monday, $until, true, null, null, null, null, date('Y-m-d', (int) strtotime($monday.' +1 day'))))->toBe(3);
        $rows = $s->sessions($a);
        expect(array_column($rows, 'end_date'))->toBe([
            date('Y-m-d', (int) strtotime($monday.' +1 day')), date('Y-m-d', (int) strtotime($monday.' +8 days')), date('Y-m-d', (int) strtotime($monday.' +15 days')),
        ]);
        // Una durata che fa toccare due date della serie non è ammessa.
        $b = app(ActivityService::class)->save(null, ['name' => 'Altra', 'kind' => 'recurring', 'social_year' => $this->year, 'fee_cents' => 0, 'guest_fee_cents' => 0]);
        expect(fn () => $s->addDates($b->id, $monday, $until, true, null, null, null, null, date('Y-m-d', (int) strtotime($monday.' +8 days')), true))->toThrow(ActivityError::class, 'non possono sovrapporsi');
    });
});

it('cambia la fine di una data esistente', function (): void {
    $this->orgContext()->run($this->org->id, function (): void {
        $s = app(SessionService::class);
        $a = trip();
        $s->addDates($a->id, $this->year.'-03-10', null, false, '09:00', '18:00', null, null);
        $id = $s->sessions($a)[0]['id'];
        $s->updateSession($id, '09:00', '12:00', null, null, $this->year.'-03-11');
        expect($s->sessions($a)[0])->toMatchArray(['end_date' => $this->year.'-03-11', 'end' => '12:00']);
        $s->updateSession($id, '09:00', '12:00', null, null, null);
        expect($s->sessions($a)[0]['end_date'])->toBeNull()
            ->and(fn () => $s->updateSession($id, '09:00', '12:00', null, null, $this->year.'-03-20'))->toThrow(ActivityError::class, 'è giusto?');
    });
});

it('dall\'area soci le date su più giorni si prenotano fino al giorno prima, quelle di un giorno anche lo stesso giorno', function (): void {
    $this->orgContext()->run($this->org->id, function (): void {
        $s = app(SessionService::class);
        $member = app(MemberActivities::class);
        $a = trip();
        $day = static fn (int $n): string => now('Europe/Rome')->addDays($n)->toDateString();
        $s->addDates($a->id, $day(-5), null, false, null, null, null, null, $day(-3));   // finita
        $s->addDates($a->id, $day(-1), null, false, null, null, null, null, $day(1));    // in corso
        $s->addDates($a->id, $day(2), null, false, null, null, null, null, $day(3));     // da domani in poi
        // Un giorno, oggi: in un'altra attività, perché nella stessa si sovrapporrebbe a quella in corso.
        $b = app(ActivityService::class)->save(null, ['name' => 'Serata', 'kind' => 'recurring', 'social_year' => $this->year, 'fee_cents' => 0, 'guest_fee_cents' => 0]);
        $s->addDates($b->id, $day(0), null, false, '21:00', '22:00', null, null);
        $ids = [];
        foreach ([...$s->sessions($a), ...$s->sessions($b)] as $row) {
            $ids[$row['date']] = $row['id'];
        }
        $who = Person::create(['first_name' => 'Ada', 'last_name' => 'Rossi'])->id;

        $rows = collect($member->upcoming($who))->keyBy('date');
        expect($rows->has($day(-5)))->toBeFalse()
            ->and($rows[$day(-1)])->toMatchArray(['ongoing' => true, 'closed' => true, 'end_date' => $day(1)])
            ->and($rows[$day(2)])->toMatchArray(['ongoing' => false, 'closed' => false])
            ->and($rows[$day(0)])->toMatchArray(['ongoing' => false, 'closed' => false, 'end_date' => null]);
        expect(collect($s->sessions($a))->firstWhere('date', $day(-1))['ongoing'])->toBeTrue();

        expect(fn () => $member->book($ids[$day(-1)], $who))->toThrow(ActivityError::class, 'si chiudono il giorno prima')
            ->and(fn () => $member->book($ids[$day(-5)], $who))->toThrow(ActivityError::class, 'non si prenota')
            ->and(fn () => $member->joinWaitlist($ids[$day(-1)], $who))->toThrow(ActivityError::class, 'si chiudono il giorno prima');
        $member->book($ids[$day(2)], $who);
        $member->book($ids[$day(0)], $who);
        // La segreteria prenota anche sulla data già iniziata.
        expect($s->book($ids[$day(-1)], $who)->status)->toBe('booked');
    });
});

it('registra le presenze in ognuno dei giorni di una data su più giorni', function (): void {
    $this->orgContext()->run($this->org->id, function (): void {
        $s = app(SessionService::class);
        $att = app(AttendanceService::class);
        $a = trip();
        $s->addDates($a->id, $this->year.'-01-05', null, false, '21:30', '01:00', null, null, $this->year.'-01-07');
        $who = Person::create(['first_name' => 'Ada', 'last_name' => 'Rossi']);
        $s->book($s->sessions($a)[0]['id'], $who->id);

        expect($att->lessonDates($a, $this->year.'-01-01', $this->year.'-01-31'))->toBe([$this->year.'-01-05', $this->year.'-01-06', $this->year.'-01-07'])
            ->and($att->lessonDates($a, $this->year.'-01-06', $this->year.'-01-06'))->toBe([$this->year.'-01-06'])
            ->and(array_column($att->roster($a, $this->year.'-01-06'), 'name'))->toBe(['Rossi Ada'])
            ->and($att->roster($a, $this->year.'-01-08'))->toBe([])
            ->and($att->save($a->id, $this->year.'-01-05', [$who->id], null))->toBe(1)
            ->and($att->save($a->id, $this->year.'-01-06', [], null))->toBe(1)
            ->and($att->marks($a->id, $this->year.'-01-05'))->toBe([$who->id => true])
            ->and($att->marks($a->id, $this->year.'-01-06'))->toBe([$who->id => false])
            ->and($att->span($a, $this->year.'-01-06'))->toBe('Giorno 2 di 3 · 06/01/'.$this->year)
            ->and($att->span($a, $this->year.'-01-08'))->toBeNull();

        $cal = collect($att->calendar($this->year.'-01-04', $this->year.'-01-08'))->where('title', 'Uscita lunga')->values();
        expect($cal->pluck('date')->all())->toBe([$this->year.'-01-05', $this->year.'-01-06', $this->year.'-01-07'])
            ->and($cal->pluck('start')->all())->toBe(['21:30', null, null])
            ->and($cal->pluck('end')->all())->toBe([null, null, '01:00'])
            ->and($cal->pluck('span')->all())->toBe(['Giorno 1 di 3', 'Giorno 2 di 3', 'Giorno 3 di 3']);
    });
});

it('esporta la fine delle date nelle prenotazioni', function (): void {
    $this->orgContext()->run($this->org->id, function (): void {
        $s = app(SessionService::class);
        $a = trip();
        $s->addDates($a->id, $this->year.'-01-05', null, false, '21:30', '01:00', null, null, $this->year.'-01-07');
        $s->book($s->sessions($a)[0]['id'], Person::create(['first_name' => 'Ada', 'last_name' => 'Rossi'])->id);
        $files = app(AttivitaDataExport::class)->files(new Operator('00000000-0000-7000-8000-000000000001', $this->org->id, $this->org->name, OrgRole::Admin));
        $csv = (string) ($files['attivita/prenotazioni.csv'] ?? '');
        expect($csv)->toContain('Data fine')->and($csv)->toContain($this->year.'-01-07')->and($csv)->toContain('01:00');
    });
});
