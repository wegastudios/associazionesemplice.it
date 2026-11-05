<?php

declare(strict_types=1);

namespace App\Modules\Risorse\Contracts;

/**
 * Come si vende una risorsa (PERIMETRO.md §4): a ore (campi), a giornata (sale, postazioni) o a stagione (posti barca,
 * ombrelloni stagionali, armadietti). Il pacchetto ha un limite per ciascuna.
 */
enum Typology: string
{
    case Hourly = 'hourly';
    case Daily = 'daily';
    case Seasonal = 'seasonal';

    public function label(): string
    {
        return match ($this) {
            self::Hourly => 'A ore',
            self::Daily => 'A giornata',
            self::Seasonal => 'A stagione',
        };
    }
}
