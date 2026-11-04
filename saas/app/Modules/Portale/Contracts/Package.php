<?php

declare(strict_types=1);

namespace App\Modules\Portale\Contracts;

/** Pacchetti vendibili (PERIMETRO.md §4 e §7.1). I codici sono quelli del contratto con il portale. */
enum Package: string
{
    case MemberArea = 'member_area';
    case Resources = 'resources';
    case Site = 'site';
    case CustomDomain = 'custom_domain';
    case DedicatedDatabase = 'dedicated_db';
    case EInvoice = 'einvoice';
    case Communications = 'communications';
    case AdvancedAccounting = 'advanced_accounting';
    case AssociationLife = 'association_life';
    case Sport = 'sport';
}
