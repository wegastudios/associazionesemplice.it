<?php

declare(strict_types=1);

namespace App\Modules\Contabilita\Contracts;

enum TxType: string
{
    case Income = 'income';
    case Expense = 'expense';
    case TransferIn = 'transfer_in';
    case TransferOut = 'transfer_out';

    public function label(): string
    {
        return match ($this) {
            self::Income => 'Entrata',
            self::Expense => 'Uscita',
            self::TransferIn => 'Giroconto in entrata',
            self::TransferOut => 'Giroconto in uscita',
        };
    }

    /** +1 se aumenta il saldo del conto, -1 se lo diminuisce. */
    public function sign(): int
    {
        return $this === self::Income || $this === self::TransferIn ? 1 : -1;
    }
}
