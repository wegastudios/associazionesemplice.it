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

    /** Regola pura: il ruolo fisso ha questo permesso? Un ruolo personalizzato ha i permessi che gli sono stati dati. */
    public function allows(OrgRole $role): bool
    {
        return $role !== OrgRole::Custom && $this->allowsFixed($role);
    }

    public function label(): string
    {
        return match ($this) {
            self::ManageSubscription => 'Abbonamento',
            self::ManageSettings => 'Impostazioni dell\'associazione',
            self::ManageOperators => 'Operatori e ruoli',
            self::ViewPeople => 'Vedere l\'anagrafica',
            self::EditPeople => 'Modificare l\'anagrafica, tesseramento, attività',
            self::ExportPeople => 'Esportare l\'anagrafica',
            self::ViewHealth => 'Vedere i certificati medici',
            self::ViewAccounting => 'Vedere la contabilità',
            self::EditAccounting => 'Registrare in prima nota',
            self::CloseFiscalYear => 'Chiudere e riaprire gli esercizi',
            self::ViewAudit => 'Vedere il registro delle azioni',
        };
    }

    /**
     * Permessi che si possono dare a un ruolo personalizzato: tutti tranne abbonamento, impostazioni e operatori, che
     * restano a titolare e amministratore.
     *
     * @return list<self>
     */
    public static function assignable(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $a): bool => ! in_array($a, [self::ManageSubscription, self::ManageSettings, self::ManageOperators], true)));
    }

    /**
     * Permessi che questo presuppone: chi modifica deve anche vedere.
     *
     * @return list<self>
     */
    public function requires(): array
    {
        return match ($this) {
            self::EditPeople, self::ExportPeople, self::ViewHealth => [self::ViewPeople],
            self::EditAccounting => [self::ViewAccounting],
            self::CloseFiscalYear => [self::ViewAccounting, self::EditAccounting],
            default => [],
        };
    }

    /**
     * Permessi di un ruolo personalizzato da quelli scelti: solo gli assegnabili, con quelli presupposti, in ordine.
     *
     * @param  list<string>  $values
     * @return list<self>
     */
    public static function normalize(array $values): array
    {
        $chosen = [];
        foreach ($values as $v) {
            $a = self::tryFrom($v);
            if ($a !== null && in_array($a, self::assignable(), true)) {
                $chosen[$a->value] = true;
                foreach ($a->requires() as $r) {
                    $chosen[$r->value] = true;
                }
            }
        }

        return array_values(array_filter(self::assignable(), static fn (self $a): bool => isset($chosen[$a->value])));
    }

    private function allowsFixed(OrgRole $role): bool
    {
        return match ($this) {
            self::ManageSubscription => $role === OrgRole::Owner,
            self::ManageSettings, self::ManageOperators, self::CloseFiscalYear, self::ViewAudit => in_array($role, [OrgRole::Owner, OrgRole::Admin], true),
            self::EditPeople, self::ExportPeople, self::EditAccounting, self::ViewHealth => in_array($role, [OrgRole::Owner, OrgRole::Admin, OrgRole::Operator], true),
            self::ViewPeople, self::ViewAccounting => true,
        };
    }
}
