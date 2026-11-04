<?php

declare(strict_types=1);

use App\Modules\Attivita\Rules\ActivityRules;
use App\Modules\Attivita\Rules\PaymentCalc;
use App\Modules\Attivita\Rules\Pricing;
use App\Modules\Soci\Contracts\MemberType;

// Casi portati da CoreTest (PaymentCalcTest), PaymentQuarterlyTest e ActivityTest del plugin.

/** @return list<string> i mesi dell'anno sociale che comincia a $month del $year */
function socialMonths(int $year, int $month): array
{
    $out = [];
    for ($i = 0; $i < 12; $i++) {
        $out[] = (new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->modify("+{$i} month")->format('Y-m');
    }

    return $out;
}

it('calcola il dovuto dall\'inizio fino a oggi', function (): void {
    $s = PaymentCalc::compute(1000, '2025-10', null, '2025-12', socialMonths(2025, 9), ['2025-10' => 1000, '2025-11' => 500]);
    expect($s['total_due'])->toBe(3000)->and($s['total_paid'])->toBe(1500)->and($s['balance'])->toBe(-1500)->and($s['regular'])->toBeFalse()
        ->and(array_column($s['unpaid_months'], 'month'))->toBe(['2025-11', '2025-12'])
        ->and($s['months'][1]['state'])->toBe(PaymentCalc::PARTIAL);
});

it('trova la prima lezione del mese', function (): void {
    expect(PaymentCalc::firstLesson('2025-10', [2]))->toBe('2025-10-07')
        ->and(PaymentCalc::firstLesson('2025-10', [3]))->toBe('2025-10-01')
        ->and(PaymentCalc::firstLesson('2025-10', [7]))->toBe('2025-10-05')
        ->and(PaymentCalc::firstLesson('2025-10', []))->toBe('2025-10-01')
        ->and(PaymentCalc::firstLesson('2025-10', [1, 4]))->toBe('2025-10-02');
    expect(PaymentCalc::compute(1000, '2025-10', null, '2025-10', socialMonths(2025, 9), [], [1, 4], '2025-10-02')['total_due'])->toBe(1000)
        ->and(PaymentCalc::compute(1000, '2025-10', null, '2025-10', socialMonths(2025, 9), [], [1, 4], '2025-10-01')['total_due'])->toBe(0);
});

it('rende dovuto il mese dalla prima lezione', function (): void {
    $year = socialMonths(2025, 9);
    $s = PaymentCalc::compute(1000, '2025-09', null, '2025-10', $year, ['2025-09' => 1000], [2], '2025-10-03');
    expect($s['total_due'])->toBe(1000)->and($s['regular'])->toBeTrue()->and($s['upcoming'])->toBe(['month' => '2025-10', 'date' => '2025-10-07', 'fee' => 1000]);
    $s = PaymentCalc::compute(1000, '2025-09', null, '2025-10', $year, ['2025-09' => 1000], [2], '2025-10-07');
    expect($s['total_due'])->toBe(2000)->and($s['regular'])->toBeFalse()->and($s['upcoming'])->toBeNull();
    expect(PaymentCalc::compute(1000, '2025-09', null, '2025-10', $year, ['2025-09' => 1000, '2025-10' => 1000], [2], '2025-10-03')['months'][1]['state'])->toBe(PaymentCalc::ADVANCE)
        ->and(PaymentCalc::compute(1000, '2025-09', null, '2025-10', $year, ['2025-09' => 1000])['total_due'])->toBe(2000);
});

it('smette di chiedere dopo la cancellazione, conta l\'anticipo e niente prima dell\'inizio', function (): void {
    expect(PaymentCalc::compute(1000, '2025-10', '2025-11', '2026-03', socialMonths(2025, 9), [])['total_due'])->toBe(2000);
    $s = PaymentCalc::compute(1000, '2025-10', null, '2025-10', socialMonths(2025, 9), ['2025-10' => 1000, '2025-11' => 1000]);
    expect($s['balance'])->toBe(1000)->and($s['regular'])->toBeTrue()->and($s['months'][1]['state'])->toBe(PaymentCalc::ADVANCE);
    $s = PaymentCalc::compute(1000, '2026-01', null, '2025-11', socialMonths(2025, 9), []);
    expect($s['total_due'])->toBe(0)->and($s['months'])->toBe([]);
});

it('chiede la quota trimestrale solo all\'inizio di ogni periodo', function (): void {
    $s = PaymentCalc::compute(9000, '2025-10', null, '2026-01', socialMonths(2025, 9), [], [], null, 3);
    expect(array_column($s['months'], 'due', 'month'))->toBe(['2025-10' => 9000, '2026-01' => 9000])->and($s['total_due'])->toBe(18000);
    $s = PaymentCalc::compute(9000, '2025-10', null, '2025-12', socialMonths(2025, 9), ['2025-11' => 9000], [], null, 3);
    expect($s['regular'])->toBeTrue()->and($s['balance'])->toBe(0)->and($s['unpaid_months'])->toBe([])
        ->and(array_column(PaymentCalc::compute(1000, '2025-10', null, '2025-12', socialMonths(2025, 9), [])['months'], 'due', 'month'))->toBe(['2025-10' => 1000, '2025-11' => 1000, '2025-12' => 1000])
        ->and(PaymentCalc::monthsBetween('2025-10', '2026-03'))->toBe(5)->and(PaymentCalc::monthsBetween('2025-10', '2025-09'))->toBe(-1);
});

it('calcola il pagamento unico e la cancellazione senza versamenti', function (): void {
    $s = PaymentCalc::computeOnce(12000, '2025-10', null, ['2025-10' => 5000]);
    expect($s['total_due'])->toBe(12000)->and($s['months'][0]['state'])->toBe(PaymentCalc::PARTIAL)
        ->and(PaymentCalc::computeOnce(12000, '2025-10', '2025-10', [])['total_due'])->toBe(0)
        ->and(PaymentCalc::computeOnce(12000, '2025-10', '2025-10', ['2025-10' => 3000])['total_due'])->toBe(12000);
});

it('fa pagare agli ospiti il loro contributo', function (): void {
    expect(Pricing::feeFor(500, 800, false))->toBe(500)
        ->and(Pricing::feeFor(500, 800, true))->toBe(800)
        ->and(Pricing::feeFor(500, null, true))->toBe(500)
        ->and(Pricing::feeFor(500, 0, true))->toBe(0)
        ->and(Pricing::feeFor(0, 300, true))->toBe(300)
        ->and(Pricing::state(0, 0))->toBe(Pricing::FREE)->and(Pricing::state(500, 0))->toBe(Pricing::UNPAID)
        ->and(Pricing::state(500, 200))->toBe(Pricing::PARTIAL)->and(Pricing::state(500, 900))->toBe(Pricing::PAID)
        ->and(Pricing::remaining(500, 200))->toBe(300)->and(Pricing::remaining(500, 900))->toBe(0);
});

it('controlla i dati dell\'attività', function (): void {
    $ok = ['name' => 'Serata', 'kind' => 'event', 'fee_cents' => 500, 'guest_fee_cents' => 800, 'starts_on' => '2026-10-12'];
    expect(ActivityRules::validate($ok))->toBe([])
        ->and(ActivityRules::validate(['kind' => 'boh'] + $ok))->not->toBe([])
        ->and(ActivityRules::validate(['fee_cents' => -1] + $ok))->not->toBe([])
        ->and(ActivityRules::validate(['guest_fee_cents' => -5] + $ok))->not->toBe([])
        ->and(ActivityRules::validate(['guest_fee_cents' => null, 'fee_cents' => 0] + $ok))->toBe([])
        ->and(ActivityRules::validate(['starts_on' => null] + $ok))->toContain('Indica la data dell\'evento.')
        ->and(ActivityRules::validate(['kind' => 'course', 'starts_on' => '2026-10-01', 'ends_on' => '2026-09-01'] + $ok))->not->toBe([])
        ->and(ActivityRules::validate(['kind' => 'course', 'weekdays' => [8]] + $ok))->not->toBe([])
        ->and(ActivityRules::validate(['instructor_person_id' => 'x'] + $ok, MemberType::Ordinary))->not->toBe([])
        ->and(ActivityRules::validate(['instructor_person_id' => 'x'] + $ok, MemberType::Volunteer))->toBe([]);
});
