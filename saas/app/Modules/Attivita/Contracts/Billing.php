<?php

declare(strict_types=1);

namespace App\Modules\Attivita\Contracts;

/** Come si paga un corso. Per gli eventi vale sempre «una tantum». */
enum Billing: string
{
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case Once = 'once';

    public function label(): string
    {
        return match ($this) {
            self::Monthly => 'al mese',
            self::Quarterly => 'a trimestre',
            self::Once => 'una tantum',
        };
    }

    public function step(): int
    {
        return $this === self::Quarterly ? 3 : 1;
    }
}
