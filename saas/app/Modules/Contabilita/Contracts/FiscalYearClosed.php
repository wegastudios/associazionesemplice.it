<?php

declare(strict_types=1);

namespace App\Modules\Contabilita\Contracts;

/**
 * Evento: un esercizio è stato chiuso. Si emette nella transazione della chiusura (dentro l'associazione): chi lo
 * ascolta fotografa i suoi dati dell'anno; se solleva un'eccezione la chiusura non avviene.
 */
final readonly class FiscalYearClosed
{
    public function __construct(public int $year, public string $startsOn, public string $endsOn) {}
}
