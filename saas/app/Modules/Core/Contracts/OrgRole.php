<?php

declare(strict_types=1);

namespace App\Modules\Core\Contracts;

/**
 * Ruolo di un operatore in un'associazione (core.org_users.role).
 *
 * Oltre ai ruoli fissi, ogni associazione può creare ruoli personalizzati (segretari, consiglieri…) con i permessi
 * che sceglie: l'operatore ha allora il ruolo Custom e il nome e i permessi stanno in core.org_roles.
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

    /** Ruolo personalizzato dell'associazione: i permessi sono quelli del ruolo. */
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Titolare',
            self::Admin => 'Amministratore',
            self::Operator => 'Segreteria',
            self::Accountant => 'Commercialista',
            self::Custom => 'Ruolo personalizzato',
        };
    }

    /** Può assegnare o togliere questo ruolo a qualcun altro? */
    public function canAssign(self $role): bool
    {
        return match ($this) {
            self::Owner => true,
            self::Admin => $role !== self::Owner,
            self::Operator, self::Accountant, self::Custom => false,
        };
    }
}
