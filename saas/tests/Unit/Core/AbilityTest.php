<?php

declare(strict_types=1);

use App\Modules\Core\Contracts\Ability;
use App\Modules\Core\Contracts\OrgRole;

it('assegna i permessi per ruolo', function (Ability $ability, array $allowed): void {
    foreach (OrgRole::cases() as $role) {
        expect($ability->allows($role))->toBe(in_array($role, $allowed, true), "{$ability->value} / {$role->value}");
    }
})->with([
    [Ability::ManageSubscription, [OrgRole::Owner]],
    [Ability::ManageSettings, [OrgRole::Owner, OrgRole::Admin]],
    [Ability::ManageOperators, [OrgRole::Owner, OrgRole::Admin]],
    [Ability::CloseFiscalYear, [OrgRole::Owner, OrgRole::Admin]],
    [Ability::ViewAudit, [OrgRole::Owner, OrgRole::Admin]],
    [Ability::EditPeople, [OrgRole::Owner, OrgRole::Admin, OrgRole::Operator, OrgRole::Employee]],
    [Ability::ExportPeople, [OrgRole::Owner, OrgRole::Admin, OrgRole::Operator, OrgRole::Employee]],
    [Ability::EditAccounting, [OrgRole::Owner, OrgRole::Admin, OrgRole::Operator, OrgRole::Employee]],
    [Ability::ViewHealth, [OrgRole::Owner, OrgRole::Admin, OrgRole::Operator, OrgRole::Employee]],
    [Ability::ViewPeople, [OrgRole::Owner, OrgRole::Admin, OrgRole::Operator, OrgRole::Employee, OrgRole::Accountant]],
    [Ability::ViewAccounting, [OrgRole::Owner, OrgRole::Admin, OrgRole::Operator, OrgRole::Employee, OrgRole::Accountant]],
    [Ability::ManageCourses, [OrgRole::Owner, OrgRole::Admin, OrgRole::Operator, OrgRole::Employee]],
    [Ability::RecordIncome, [OrgRole::Owner, OrgRole::Admin, OrgRole::Operator, OrgRole::Employee]],
    [Ability::ViewFunds, [OrgRole::Owner, OrgRole::Admin, OrgRole::Operator, OrgRole::Employee, OrgRole::Accountant]],
]);

it('dà ai ruoli personalizzati solo i permessi scelti, con quelli che presuppongono', function (): void {
    expect(Ability::normalize(['accounting.close', 'org.settings', 'boh', 'people.export']))
        ->toBe([Ability::ViewPeople, Ability::ExportPeople, Ability::ViewAccounting, Ability::EditAccounting, Ability::CloseFiscalYear])
        ->and(Ability::assignable())->not->toContain(Ability::ManageSubscription, Ability::ManageSettings, Ability::ManageOperators)
        ->and(OrgRole::Admin->canAssign(OrgRole::Custom))->toBeTrue()
        ->and(OrgRole::Custom->canAssign(OrgRole::Custom))->toBeFalse();

    $op = new App\Modules\Core\Contracts\Operator('u', 'o', 'Org', OrgRole::Custom, 'r', 'Consigliere', [Ability::ViewPeople]);
    expect($op->can(Ability::ViewPeople))->toBeTrue()->and($op->can(Ability::ViewAccounting))->toBeFalse()->and($op->roleLabel())->toBe('Consigliere');
});

it('fa assegnare i ruoli solo a chi ne ha titolo', function (): void {
    expect(OrgRole::Owner->canAssign(OrgRole::Owner))->toBeTrue()
        ->and(OrgRole::Admin->canAssign(OrgRole::Owner))->toBeFalse()
        ->and(OrgRole::Admin->canAssign(OrgRole::Operator))->toBeTrue()
        ->and(OrgRole::Operator->canAssign(OrgRole::Operator))->toBeFalse()
        ->and(OrgRole::Accountant->canAssign(OrgRole::Accountant))->toBeFalse();
});
