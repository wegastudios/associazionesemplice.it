<?php

declare(strict_types=1);

use App\Modules\Risorse\Contracts\ResourceError;
use App\Modules\Risorse\Rules\ResourceRules as R;

// Casi portati da ResourceRulesTest del plugin, più tipologie, non soci e servizi aggiuntivi.

const RES_NOW = '2026-10-19 10:00:00';

/** @return array<string, mixed> */
function court(array $extra = []): array
{
    return $extra + ['typology' => 'hourly', 'open_time' => '08:00', 'close_time' => '22:00', 'slot_minutes' => 60, 'min_slots' => 1, 'max_slots' => 2,
        'advance_days' => 14, 'fee_cents' => 1000, 'guest_fee_cents' => 200];
}

/** @return array<string, mixed> */
function rated(): array
{
    return court(['rates' => json_encode(R::parseRates("feriali 18:00-22:00 15,00\nweekend 08:00-22:00 20"))]);
}

function refused(callable $fn): string
{
    try {
        $fn();
    } catch (ResourceError $e) {
        return $e->getMessage();
    }
    throw new RuntimeException('Doveva essere rifiutato.');
}

it('riconosce le sovrapposizioni, anche senza fine', function (): void {
    expect(R::overlaps('2026-10-20 18:00:00', '2026-10-20 19:00:00', '2026-10-20 18:30:00', '2026-10-20 19:30:00'))->toBeTrue()
        ->and(R::overlaps('2026-10-20 18:00:00', '2026-10-20 19:00:00', '2026-10-20 19:00:00', '2026-10-20 20:00:00'))->toBeFalse()
        ->and(R::overlaps('2026-10-20 18:00:00', '2026-10-20 19:00:00', '2026-10-20 17:00:00', '2026-10-20 23:00:00'))->toBeTrue()
        ->and(R::overlaps('2026-01-01 00:00:00', null, '2030-05-05 00:00:00', '2030-05-06 00:00:00'))->toBeTrue()
        ->and(R::overlaps('2026-01-01 00:00:00', null, '2025-12-01 00:00:00', '2026-01-01 00:00:00'))->toBeFalse()
        ->and(R::overlaps('2026-01-01 00:00:00', null, '2026-06-01 00:00:00', null))->toBeTrue();
});

it('calcola le fasce e controlla griglia, chiusura e numero', function (): void {
    expect(R::slotRange(court(), '2026-10-20', '18:00', 2, RES_NOW))->toBe(['2026-10-20 18:00:00', '2026-10-20 20:00:00'])
        ->and(R::slotRange(court(), '2026-10-20', '21:00', 1, RES_NOW))->toBe(['2026-10-20 21:00:00', '2026-10-20 22:00:00'])
        ->and(refused(fn () => R::slotRange(court(), '2026-10-20', '18:30', 1, RES_NOW)))->toContain('fasce')
        ->and(refused(fn () => R::slotRange(court(), '2026-10-20', '21:00', 2, RES_NOW)))->toContain('chiusura')
        ->and(refused(fn () => R::slotRange(court(), '2026-10-20', '10:00', 3, RES_NOW)))->toContain('da 1 a 2');
    foreach ([['2026-13-40', '18:00'], ['ieri', '18:00'], ['2026-10-20', '25:00'], ['2026-10-20', '']] as [$d, $t]) {
        expect(refused(fn () => R::slotRange(court(), $d, $t, 1, RES_NOW)))->not->toBe('');
    }
    expect(refused(fn () => R::slotRange(court(['close_time' => '07:00']), '2026-10-20', '08:00', 1, RES_NOW)))->toContain('orari');
});

it('rifiuta il passato e l\'anticipo, ma non alla segreteria', function (): void {
    expect(refused(fn () => R::slotRange(court(), '2026-10-19', '09:00', 1, RES_NOW)))->toContain('iniziata')
        ->and(R::slotRange(court(), '2026-10-19', '09:00', 1, RES_NOW, true))->toBe(['2026-10-19 09:00:00', '2026-10-19 10:00:00'])
        ->and(R::slotRange(court(), '2026-11-02', '18:00', 1, RES_NOW)[0])->toBe('2026-11-02 18:00:00')
        ->and(refused(fn () => R::slotRange(court(), '2026-11-03', '18:00', 1, RES_NOW)))->toContain('14 giorni')
        ->and(R::slotRange(court(), '2027-01-10', '18:00', 1, RES_NOW, true)[0])->toBe('2027-01-10 18:00:00');
});

it('calcola giornate e stagioni', function (): void {
    expect(R::dayRange(court(), '2026-10-31', 2, RES_NOW))->toBe(['2026-10-31 00:00:00', '2026-11-02 00:00:00'])
        ->and(R::dayRange(court(), '2026-10-19', 1, RES_NOW))->toBe(['2026-10-19 00:00:00', '2026-10-20 00:00:00'])
        ->and(refused(fn () => R::dayRange(court(), '2026-10-18', 1, RES_NOW)))->toContain('passato')
        ->and(refused(fn () => R::dayRange(court(), '2026-10-20', 0, RES_NOW)))->toContain('31')
        ->and(R::seasonRange('2027-04-01', '2027-09-30'))->toBe(['2027-04-01 00:00:00', '2027-10-01 00:00:00'])
        ->and(R::seasonRange('2027-04-01', null))->toBe(['2027-04-01 00:00:00', null])
        ->and(refused(fn () => R::seasonRange('2027-04-01', '2027-03-01')))->toContain('prima');
});

it('pulisce e limita gli ospiti', function (): void {
    expect(R::guests(2, ['  Mario   Rossi ', '', '   ']))->toBe(['Mario Rossi'])
        ->and(R::guests(0, ['', ' ']))->toBe([])
        ->and(refused(fn () => R::guests(0, ['Mario Rossi'])))->toContain('non ammette')
        ->and(refused(fn () => R::guests(1, ['A B', 'C D'])))->toContain('al massimo 1 ospite');
});

it('calcola il dovuto per fasce, giorni, stagione, ospiti, non soci e servizi', function (): void {
    expect(R::fee(court(), 1, 0, null, true))->toBe(1000)
        ->and(R::fee(court(), 2, 2, null, true))->toBe(2400)
        ->and(R::fee(['typology' => 'seasonal', 'fee_cents' => 12000], 5, 0, null, true))->toBe(12000)
        ->and(R::fee(['typology' => 'daily', 'fee_cents' => 5000], 3, 0, null, true))->toBe(15000)
        ->and(R::fee(court(['nonmember_fee_cents' => 1800]), 2, 0, '2026-10-20 18:00:00', false))->toBe(3600)
        ->and(R::fee(court(['nonmember_fee_cents' => 1800]), 2, 0, null, true))->toBe(2000)
        ->and(R::fee(court(), 1, 0, null, false))->toBe(1000)
        ->and(R::fee(['typology' => 'daily', 'fee_cents' => 5000, 'nonmember_fee_cents' => 8000], 1, 0, null, false, R::parseServices("Aperitivo 5,00\nAllestimento 30")))->toBe(11500);
});

it('legge le tariffe a fasce dal modulo e le riscrive uguali', function (): void {
    expect(R::parseRates("feriali 18:00-22:00 15,00\n\nweekend 08:00 - 22:00 20\ntutti 08:00-09:00 5.5 €"))->toBe([
        ['days' => 'weekdays', 'from' => '18:00', 'to' => '22:00', 'fee_cents' => 1500],
        ['days' => 'weekend', 'from' => '08:00', 'to' => '22:00', 'fee_cents' => 2000],
        ['days' => 'all', 'from' => '08:00', 'to' => '09:00', 'fee_cents' => 550],
    ]);
    $text = "feriali 18:00-22:00 15,00\nweekend 08:00-22:00 20,00";
    expect(R::ratesText(R::parseRates($text)))->toBe($text);
    foreach (['sempre 18:00-22:00 15', 'feriali 18:00 15', 'feriali 22:00-18:00 15', 'feriali 18:00-25:00 15', 'feriali 18:00-22:00 gratis'] as $bad) {
        expect(refused(fn () => R::parseRates($bad)))->toContain('riga 1');
    }
});

it('applica la tariffa del giorno e dell\'ora a ogni fascia', function (): void {
    $res = rated();
    expect(R::bandFee($res, '2026-10-20 18:00:00'))->toBe(1500)
        ->and(R::bandFee($res, '2026-10-20 10:00:00'))->toBe(1000)
        ->and(R::bandFee($res, '2026-10-20 22:00:00'))->toBe(1000)
        ->and(R::bandFee($res, '2026-10-24 10:00:00'))->toBe(2000)
        ->and(R::bandFee($res, '2026-10-25 21:00:00'))->toBe(2000)
        ->and(R::bandFee(court(), '2026-10-20 18:00:00'))->toBe(1000)
        ->and(R::bandFee(court(['rates' => 'non è json']), '2026-10-20 18:00:00'))->toBe(1000)
        ->and(R::slotPrice($res, '2026-10-20 17:00:00', 2))->toBe(2500)
        ->and(R::slotPrice($res, '2026-10-20 18:00:00', 2))->toBe(3000)
        ->and(R::fee($res, 1, 1, '2026-10-20 18:00:00', true))->toBe(1700)
        ->and(R::fee($res, 1, 0, null, true))->toBe(1000);
});

it('legge i servizi aggiuntivi', function (): void {
    expect(R::parseServices("Aperitivo 5,00\n\nAllestimento sala 30 €"))->toBe([['name' => 'Aperitivo', 'fee_cents' => 500], ['name' => 'Allestimento sala', 'fee_cents' => 3000]])
        ->and(R::servicesText(R::parseServices("Aperitivo 5\nTorta 12,50")))->toBe("Aperitivo 5,00\nTorta 12,50")
        ->and(refused(fn () => R::parseServices('Aperitivo')))->toContain('riga 1');
});

it('elenca le fasce del giorno, le date della serie e i minuti di occupazione', function (): void {
    expect(R::daySlots(court(['open_time' => '20:00', 'close_time' => '22:30'])))->toBe([['start' => '20:00', 'end' => '21:00'], ['start' => '21:00', 'end' => '22:00']])
        ->and(R::daySlots(['typology' => 'daily']))->toBe([])
        ->and(R::seriesDates('2026-10-20', 3))->toBe(['2026-10-20', '2026-10-27', '2026-11-03'])
        ->and(R::overlapMinutes('2026-10-20 18:00:00', '2026-10-20 20:00:00', '2026-10-20 00:00:00', '2026-10-21 00:00:00'))->toBe(120)
        ->and(R::overlapMinutes('2026-01-01 00:00:00', null, '2026-10-20 00:00:00', '2026-10-21 00:00:00'))->toBe(1440)
        ->and(R::overlapMinutes('2026-10-19 00:00:00', '2026-10-20 00:00:00', '2026-10-20 00:00:00', '2026-10-21 00:00:00'))->toBe(0);
});
