<?php

declare(strict_types=1);

namespace App\Modules\Core\Contracts;

/**
 * Ruolo di un operatore in un'associazione (core.org_users.role).
 *
 * I ruoli configurabili per segretari e consiglieri arrivano nella fase 1: si appoggeranno a questi.
 */
enum OrgRole: string
{
    /** Titolare dell'abbonamento: tutto, compreso l'abbonamento sul portale. */
    case Owner = 'owner';

    /** Amministratore: tutto tranne l'abbonamento. */
    case Admin = 'admin';

    /** Operatore di segreteria: anagrafica e prima nota. */
    case Operator = 'operator';

    /** Commercialista o revisore: legge contabilità e anagrafica, non modifica. */
    case Accountant = 'accountant';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Titolare',
            self::Admin => 'Amministratore',
            self::Operator => 'Segreteria',
            self::Accountant => 'Commercialista',
        };
    }

    /** Può assegnare o togliere questo ruolo a qualcun altro? */
    public function canAssign(self $role): bool
    {
        return match ($this) {
            self::Owner => true,
            self::Admin => $role !== self::Owner,
            self::Operator, self::Accountant => false,
        };
    }
}
