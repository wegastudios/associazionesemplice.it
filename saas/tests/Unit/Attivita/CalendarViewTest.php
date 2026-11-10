<?php

declare(strict_types=1);

use App\Modules\Attivita\Rules\CalendarView;

it('parte dalla settimana e ignora le viste sconosciute', function (): void {
    expect(CalendarView::normalize('boh'))->toBe('settimana')
        ->and(CalendarView::range('settimana', '2026-11-12'))->toBe(['from' => '2026-11-12', 'to' => '2026-11-18', 'previous' => '2026-11-05', 'next' => '2026-11-19'])
        ->and(CalendarView::range('boh', '2026-11-12')['to'])->toBe('2026-11-18');
});

it('mostra un giorno solo e un mese intero', function (): void {
    expect(CalendarView::range('giorno', '2026-11-12'))->toBe(['from' => '2026-11-12', 'to' => '2026-11-12', 'previous' => '2026-11-11', 'next' => '2026-11-13'])
        ->and(CalendarView::range('mese', '2026-01-31'))->toBe(['from' => '2026-01-01', 'to' => '2026-01-31', 'previous' => '2025-12-01', 'next' => '2026-02-01'])
        ->and(CalendarView::range('mese', '2028-02-10')['to'])->toBe('2028-02-29');
});
