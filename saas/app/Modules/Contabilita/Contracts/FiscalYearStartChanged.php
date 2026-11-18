<?php

declare(strict_types=1);

namespace App\Modules\Contabilita\Contracts;

/** Evento: il mese di inizio dell'anno fiscale è cambiato (solo finché nessun anno è chiuso). Le tessere lo seguono. */
final readonly class FiscalYearStartChanged
{
    public function __construct(public int $month) {}
}
