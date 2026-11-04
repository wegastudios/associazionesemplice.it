<?php

declare(strict_types=1);

namespace App\Modules\Cifratura\Contracts;

/** Come si normalizza un valore prima dell'impronta di ricerca: «rss mra…» e «RSSMRA…» devono coincidere. */
enum Normalizer: string
{
    case TaxCode = 'taxcode';
    case Iban = 'iban';
    case Phone = 'phone';
    case Text = 'text';

    public function apply(string $value): string
    {
        return match ($this) {
            self::TaxCode, self::Iban => strtoupper((string) preg_replace('/\s+/', '', $value)),
            self::Phone => (string) preg_replace('/[^0-9+]/', '', $value),
            self::Text => mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $value))),
        };
    }
}
