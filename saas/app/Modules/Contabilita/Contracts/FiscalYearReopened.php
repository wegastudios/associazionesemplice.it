<?php

declare(strict_types=1);

namespace App\Modules\Contabilita\Contracts;

/** Evento: un esercizio chiuso è stato riaperto (solo l'ultimo chiuso). Le fotografie vigenti diventano versioni precedenti. */
final readonly class FiscalYearReopened
{
    public function __construct(public int $year) {}
}
