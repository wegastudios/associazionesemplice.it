<?php

declare(strict_types=1);

namespace App\Modules\Soci\Contracts;

use App\Modules\Soci\Rules\TaxCode;

/** Codice fiscale di una persona fisica, per gli altri moduli (per esempio l'ente con il codice di una persona). */
final class PersonTaxCode
{
    public static function normalize(string $value): string
    {
        return TaxCode::normalize($value);
    }

    public static function isValid(string $value): bool
    {
        return TaxCode::isValid($value);
    }
}
