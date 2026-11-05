<?php

declare(strict_types=1);

use App\Modules\Attivita\Contracts\ActivityError;
use App\Modules\Attivita\Rules\SessionRules as S;

it('trova i giorni della settimana in un periodo', function (): void {
    // 2026-11-02 è un lunedì.
    expect(S::weeklyDates('2026-11-02', '2026-11-30', 2))->toBe(['2026-11-03', '2026-11-10', '2026-11-17', '2026-11-24'])
        ->and(S::weeklyDates('2026-11-03', '2026-11-03', 2))->toBe(['2026-11-03'])
        ->and(S::weeklyDates('2026-11-04', '2026-11-09', 2))->toBe([])
        ->and(S::weeklyDates('2026-11-10', '2026-11-01', 2))->toBe([]);
});

it('calcola le lezioni di un corso dentro il suo periodo', function (): void {
    expect(S::lessonDates([2, 4], '2026-11-05', '2026-11-30', '2026-11-01', '2026-11-14'))->toBe(['2026-11-05', '2026-11-10', '2026-11-12'])
        ->and(S::lessonDates([], '2026-01-01', '2026-12-31', '2026-11-01', '2026-11-30'))->toBe([]);
});

it('prepara una data o una serie settimanale', function (): void {
    expect(S::newDates('2026-11-03', null, false))->toBe(['2026-11-03'])
        ->and(S::newDates('2026-11-03', '2026-11-24', true))->toBe(['2026-11-03', '2026-11-10', '2026-11-17', '2026-11-24'])
        ->and(fn () => S::newDates('2026-11-03', '2026-10-01', true))->toThrow(ActivityError::class, 'prima')
        ->and(fn () => S::newDates('2026-02-30', null, false))->toThrow(ActivityError::class, 'Data')
        ->and(fn () => S::newDates('2026-01-01', '2029-01-01', true))->toThrow(ActivityError::class, 'massimo 120')
        ->and(S::time(' 18:30 '))->toBe('18:30')->and(S::time(''))->toBeNull()
        ->and(fn () => S::time('25:00'))->toThrow(ActivityError::class);
});

it('legge i listini e i pacchetti dal modulo e li riscrive uguali', function (): void {
    $lists = S::parsePriceLists("Ingresso singolo 10,00\n\nPacchetto 5 ingressi 40 € x5\nRidotto 7.5");
    expect($lists)->toBe([
        ['key' => 'ingresso_singolo', 'label' => 'Ingresso singolo', 'cents' => 1000, 'entries' => 1],
        ['key' => 'pacchetto_5_ingressi', 'label' => 'Pacchetto 5 ingressi', 'cents' => 4000, 'entries' => 5],
        ['key' => 'ridotto', 'label' => 'Ridotto', 'cents' => 750, 'entries' => 1],
    ])
        ->and(S::priceListsText($lists))->toBe("Ingresso singolo 10,00\nPacchetto 5 ingressi 40,00 x5\nRidotto 7,50")
        ->and(S::parsePriceLists(S::priceListsText($lists)))->toBe($lists)
        ->and(S::findList($lists, 'ridotto')['cents'] ?? null)->toBe(750)
        ->and(S::findList($lists, 'boh'))->toBeNull()
        ->and(array_column(S::parsePriceLists("Città 5\nCittà 6"), 'key'))->toBe(['citta', 'cittax'])
        ->and(fn () => S::parsePriceLists('Gratis'))->toThrow(ActivityError::class, 'riga 1')
        ->and(fn () => S::parsePriceLists('Pacchetto 40 x99'))->toThrow(ActivityError::class, '50 ingressi')
        ->and(S::free(10, 4))->toBe(6)->and(S::free(3, 5))->toBe(0)->and(S::free(null, 5))->toBeNull();
});
