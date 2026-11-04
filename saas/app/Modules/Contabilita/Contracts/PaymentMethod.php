<?php

declare(strict_types=1);

namespace App\Modules\Contabilita\Contracts;

enum PaymentMethod: string
{
    case Cash = 'cash';
    case BankTransfer = 'bank_transfer';
    case Pos = 'pos';
    case Check = 'check';
    case Stripe = 'stripe';
    case PayPal = 'paypal';
    case SumUp = 'sumup';
    case Satispay = 'satispay';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Contanti',
            self::BankTransfer => 'Bonifico',
            self::Pos => 'POS / carta',
            self::Check => 'Assegno',
            self::Stripe => 'Carta online (Stripe)',
            self::PayPal => 'PayPal',
            self::SumUp => 'Carta online (SumUp)',
            self::Satispay => 'Satispay',
            self::Other => 'Altro',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $m): string => $m->value, self::cases());
    }
}
