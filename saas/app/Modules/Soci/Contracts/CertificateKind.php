<?php

declare(strict_types=1);

namespace App\Modules\Soci\Contracts;

enum CertificateKind: string
{
    case NonAgonistic = 'non_agonistic';
    case Agonistic = 'agonistic';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::NonAgonistic => 'Non agonistico',
            self::Agonistic => 'Agonistico',
            self::Other => 'Altro',
        };
    }
}
