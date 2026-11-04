<?php

declare(strict_types=1);

namespace App\Modules\Contabilita\Contracts;

enum AccountType: string
{
    case Cash = 'cash';
    case Bank = 'bank';
    case Pos = 'pos';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Cassa contanti',
            self::Bank => 'Conto corrente',
            self::Pos => 'Conto POS',
            self::Other => 'Altro',
        };
    }

    /** Modalità di pagamento proposta per il conto. */
    public function defaultMethod(): PaymentMethod
    {
        return match ($this) {
            self::Cash => PaymentMethod::Cash,
            self::Bank => PaymentMethod::BankTransfer,
            self::Pos => PaymentMethod::Pos,
            self::Other => PaymentMethod::Other,
        };
    }
}
