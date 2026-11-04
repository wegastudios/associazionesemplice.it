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
    [Ability::EditPeople, [OrgRole::Owner, OrgRole::Admin, OrgRole::Operator]],
    [Ability::EditAccounting, [OrgRole::Owner, OrgRole::Admin, OrgRole::Operator]],
    [Ability::ViewPeople, OrgRole::cases()],
    [Ability::ViewAccounting, OrgRole::cases()],
]);

it('fa assegnare i ruoli solo a chi ne ha titolo', function (): void {
    expect(OrgRole::Owner->canAssign(OrgRole::Owner))->toBeTrue()
        ->and(OrgRole::Admin->canAssign(OrgRole::Owner))->toBeFalse()
        ->and(OrgRole::Admin->canAssign(OrgRole::Operator))->toBeTrue()
        ->and(OrgRole::Operator->canAssign(OrgRole::Operator))->toBeFalse()
        ->and(OrgRole::Accountant->canAssign(OrgRole::Accountant))->toBeFalse();
});
