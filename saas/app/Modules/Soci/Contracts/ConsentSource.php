<?php

declare(strict_types=1);

namespace App\Modules\Soci\Contracts;

enum ConsentSource: string
{
    case Paper = 'paper';
    case Oral = 'oral';
    case Web = 'web';
    case Admin = 'admin';

    public function label(): string
    {
        return match ($this) {
            self::Paper => 'Modulo cartaceo',
            self::Oral => 'A voce',
            self::Web => 'Online',
            self::Admin => 'Registrato dalla segreteria',
        };
    }
}
