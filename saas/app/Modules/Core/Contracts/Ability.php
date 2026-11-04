<?php

declare(strict_types=1);

namespace App\Modules\Core\Contracts;

/**
 * Cosa può fare un operatore nell'associazione corrente. Si controlla con Gate o con `can:` nelle rotte:
 *
 *   Gate::allows(Ability::EditPeople->value)
 *
 * I permessi dei soci sui propri dati (area soci) non passano da qui: derivano dai dati, come Access nel plugin,
 * e arrivano con il modulo Soci.
 */
enum Ability: string
{
    case ManageSubscription = 'org.subscription';
    case ManageSettings = 'org.settings';
    case ManageOperators = 'org.operators';
    case ViewPeople = 'people.view';
    case EditPeople = 'people.edit';
    /** Export dell'anagrafica con i dati sensibili: non per il commercialista. */
    case ExportPeople = 'people.export';
    /** Dati sanitari (certificati medici): non per il commercialista. */
    case ViewHealth = 'health.view';
    case ViewAccounting = 'accounting.view';
    case EditAccounting = 'accounting.edit';
    case CloseFiscalYear = 'accounting.close';
    case ViewAudit = 'audit.view';

    /** Regola pura: il ruolo ha questo permesso? */
    public function allows(OrgRole $role): bool
    {
        return match ($this) {
            self::ManageSubscription => $role === OrgRole::Owner,
            self::ManageSettings, self::ManageOperators, self::CloseFiscalYear, self::ViewAudit => in_array($role, [OrgRole::Owner, OrgRole::Admin], true),
            self::EditPeople, self::ExportPeople, self::EditAccounting, self::ViewHealth => in_array($role, [OrgRole::Owner, OrgRole::Admin, OrgRole::Operator], true),
            self::ViewPeople, self::ViewAccounting => true,
        };
    }
}
