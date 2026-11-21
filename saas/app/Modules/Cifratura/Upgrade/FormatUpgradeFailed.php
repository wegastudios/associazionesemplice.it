<?php

declare(strict_types=1);

namespace App\Modules\Cifratura\Upgrade;

use RuntimeException;

/** Una riga non si decifra: la transazione dell'associazione si annulla, niente è stato scritto. */
final class FormatUpgradeFailed extends RuntimeException
{
    public function __construct(public readonly FormatReport $report)
    {
        parent::__construct('Alcuni campi cifrati non si decifrano: nessuna modifica per questa associazione.');
    }
}
