<?php

declare(strict_types=1);

use App\Modules\Core\Access\Memberships;
use App\Modules\Core\Access\OrgMembers;
use App\Modules\Core\Contracts\Ability;
use App\Modules\Core\Contracts\OrgRole;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

// Dati di prova inventati.
beforeEach(function (): void {
    Notification::fake();
    $this->org = $this->createOrg('Polisportiva Alfa');
    $this->owner = $this->createUser('titolare@example.com', twoFactor: true);
    $this->addOperator($this->org, $this->owner, OrgRole::Owner);
});

function ownerOperator(): App\Modules\Core\Contracts\Operator
{
    return app(Memberships::class)->find(test()->owner->id, test()->org->id) ?? throw new RuntimeException('manca');
}

it('crea un ruolo personalizzato e ne applica i permessi', function (): void {
    $m = app(OrgMembers::class);
    $roleId = $m->saveRole($this->org, null, ' Consigliere ', ['people.view', 'accounting.edit', 'org.settings'], ownerOperator());
    $user = $m->invite($this->org, 'consigliere@example.com', 'Carlo Consigliere', OrgRole::Custom, ownerOperator(), $roleId);
    User::query()->whereKey($user->id)->update(['two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);

    $op = app(Memberships::class)->find($user->id, $this->org->id);
    expect($op?->roleLabel())->toBe('Consigliere')
        ->and($op?->customAbilities)->toBe([Ability::ViewPeople, Ability::ViewAccounting, Ability::EditAccounting])
        ->and($op?->can(Ability::ManageSettings))->toBeFalse()
        ->and($m->roles($this->org)[0]['members'])->toBe(1)
        ->and($this->orgContext()->run($this->org->id, fn () => DB::table('audit.log')->whereIn('action', ['role.created', 'operator.invited'])->count()))->toBe(2);

    $this->actingAs($user->fresh() ?? $user);
    $this->get('/gestionale/soci')->assertOk();
    $this->get('/gestionale/prima-nota')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('org.role', 'Consigliere')->where('org.can', ['people.view', 'accounting.view', 'accounting.edit']));
    $this->get('/gestionale/operatori')->assertForbidden();
    $this->post('/gestionale/soci', ['type' => 'ordinary', 'first_name' => 'A', 'last_name' => 'B'])->assertForbidden();

    // Il ruolo cambia: vale dalla richiesta successiva.
    $m->saveRole($this->org, $roleId, 'Consigliere', ['people.view'], ownerOperator());
    $this->get('/gestionale/prima-nota')->assertForbidden();
});

it('controlla nomi, permessi ed eliminazione dei ruoli', function (): void {
    $m = app(OrgMembers::class);
    $id = $m->saveRole($this->org, null, 'Tesoriere', ['accounting.close'], ownerOperator());

    expect(fn () => $m->saveRole($this->org, null, 'tesoriere', ['people.view'], ownerOperator()))->toThrow(InvalidArgumentException::class, 'già un ruolo')
        ->and(fn () => $m->saveRole($this->org, null, 'Segreteria', ['people.view'], ownerOperator()))->toThrow(InvalidArgumentException::class, 'ruolo fisso')
        ->and(fn () => $m->saveRole($this->org, null, 'Vuoto', ['org.settings'], ownerOperator()))->toThrow(InvalidArgumentException::class, 'almeno un permesso')
        ->and(array_map(static fn (Ability $a): string => $a->value, $m->roles($this->org)[0]['abilities']))->toBe(['accounting.view', 'accounting.edit', 'accounting.close']);

    $m->invite($this->org, 'tes@example.com', 'Tea Tesoriera', OrgRole::Custom, ownerOperator(), $id);
    expect(fn () => $m->deleteRole($this->org, $id, ownerOperator()))->toThrow(InvalidArgumentException::class, 'ancora questo ruolo');

    $user = User::query()->where('email', 'tes@example.com')->sole();
    $m->changeRole($this->org, $user->id, OrgRole::Operator, ownerOperator());
    $m->deleteRole($this->org, $id, ownerOperator());
    expect($m->roles($this->org))->toBe([]);
});

it('non accetta ruoli di un\'altra associazione né operatori senza titolo', function (): void {
    $m = app(OrgMembers::class);
    $other = $this->createOrg('Circolo Beta');
    $otherOwner = $this->createUser('beta@example.com', twoFactor: true);
    $this->addOperator($other, $otherOwner, OrgRole::Owner);
    $betaRole = $m->saveRole($other, null, 'Consigliere', ['people.view'], app(Memberships::class)->find($otherOwner->id, $other->id) ?? throw new RuntimeException);

    expect(fn () => $m->invite($this->org, 'x@example.com', 'X', OrgRole::Custom, ownerOperator(), $betaRole))->toThrow(InvalidArgumentException::class, 'ruoli dell\'associazione')
        ->and(fn () => $m->invite($this->org, 'x@example.com', 'X', OrgRole::Custom, ownerOperator()))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $m->saveRole($this->org, $betaRole, 'Furto', ['people.view'], ownerOperator()))->toThrow(InvalidArgumentException::class, 'inesistente');

    $admin = $m->invite($this->org, 'admin@example.com', 'Ada Admin', OrgRole::Admin, ownerOperator());
    $adminOp = app(Memberships::class)->find($admin->id, $this->org->id) ?? throw new RuntimeException;
    expect(fn () => $m->changeRole($this->org, $this->owner->id, OrgRole::Operator, $adminOp))->toThrow(Illuminate\Auth\Access\AuthorizationException::class)
        ->and(fn () => $m->remove($this->org, $admin->id, $adminOp))->toThrow(InvalidArgumentException::class, 'da solo');
    $roleId = $m->saveRole($this->org, null, 'Segretario', ['people.edit'], $adminOp);
    $m->invite($this->org, 'seg@example.com', 'Sergio', OrgRole::Custom, $adminOp, $roleId);
    expect(app(Memberships::class)->find(User::query()->where('email', 'seg@example.com')->sole()->id, $this->org->id)?->roleLabel())->toBe('Segretario');
});

it('gestisce operatori e ruoli dalla pagina', function (): void {
    $this->actingAs($this->owner);

    $this->post('/gestionale/ruoli', ['name' => 'Consigliere', 'abilities' => ['people.view', 'health.view']])->assertSessionHas('status', 'Ruolo creato.');
    $roleId = (string) DB::table('core.org_roles')->value('id');
    $this->post('/gestionale/ruoli', ['name' => 'Altro', 'abilities' => ['org.subscription']])->assertSessionHasErrors('abilities.0');

    $this->post('/gestionale/operatori', ['email' => 'carlo@example.com', 'name' => 'Carlo', 'role' => 'custom'])->assertSessionHasErrors('custom_role_id');
    $this->post('/gestionale/operatori', ['email' => 'carlo@example.com', 'name' => 'Carlo', 'role' => 'custom', 'custom_role_id' => $roleId])
        ->assertSessionHas('status', 'Invito mandato a carlo@example.com.');
    $carlo = User::query()->where('email', 'carlo@example.com')->sole();

    $this->get('/gestionale/operatori')->assertInertia(fn (Assert $page) => $page->component('Operatori/Elenco')
        ->has('operators', 2)->where('operators.1.role_label', 'Consigliere')->where('operators.1.two_factor', false)
        ->where('operators.0.me', true)->where('operators.0.editable', false)
        ->where('roles.0.abilities', ['people.view', 'health.view'])->where('roles.0.members', 1));

    $this->put("/gestionale/operatori/{$carlo->id}", ['role' => 'accountant'])->assertSessionHas('status', 'Ruolo cambiato.');
    expect(DB::table('core.org_users')->where('user_id', $carlo->id)->value('custom_role_id'))->toBeNull();
    $this->put("/gestionale/ruoli/{$roleId}", ['name' => 'Consigliera', 'abilities' => ['people.view']])->assertSessionHas('status');
    $this->delete("/gestionale/ruoli/{$roleId}")->assertSessionHas('status', 'Ruolo eliminato.');
    $this->delete("/gestionale/operatori/{$carlo->id}")->assertSessionHas('status', 'Operatore tolto dall\'associazione.');
    $this->delete("/gestionale/operatori/{$this->owner->id}")->assertSessionHasErrors('ledger');

    $operator = $this->createUser('segreteria@example.com', twoFactor: true);
    $this->addOperator($this->org, $operator, OrgRole::Operator);
    $this->actingAs($operator)->get('/gestionale/operatori')->assertForbidden();
    $this->post('/gestionale/ruoli', ['name' => 'X', 'abilities' => ['people.view']])->assertForbidden();
});

it('fa partire il dipendente come la segreteria e gli toglie i permessi uno per uno', function (): void {
    $m = app(OrgMembers::class);
    $user = $m->invite($this->org, 'dipendente@example.com', 'Dina Dipendente', OrgRole::Employee, ownerOperator());
    User::query()->whereKey($user->id)->update(['two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);

    $op = fn () => app(Memberships::class)->find($user->id, $this->org->id);
    expect($op()?->can(Ability::RecordIncome))->toBeTrue()->and($op()?->can(Ability::EditPeople))->toBeTrue();

    $m->setDeniedAbilities($this->org, $user->id, ['accounting.income', 'courses.manage', 'org.settings', 'boh'], ownerOperator());
    expect($op()?->can(Ability::RecordIncome))->toBeFalse()
        ->and($op()?->can(Ability::ManageCourses))->toBeFalse()
        ->and($op()?->can(Ability::RecordExpense))->toBeTrue()
        ->and($op()?->can(Ability::ManageSettings))->toBeFalse()
        ->and($op()?->deniedAbilities)->toBe([Ability::ManageCourses, Ability::RecordIncome]);

    $this->actingAs($user->fresh() ?? $user);
    $cash = $this->orgContext()->run($this->org->id, fn () => App\Modules\Contabilita\Models\Account::query()->sole()->id);
    $this->post('/gestionale/prima-nota/incassi', ['date' => now()->toDateString(), 'account_id' => $cash, 'lines' => []])->assertForbidden();
    $this->get('/gestionale/attivita/nuova')->assertForbidden();
    $this->get('/gestionale/conti')->assertOk();

    // Chi non è titolare o amministratore non può cambiare i permessi; cambiando ruolo si azzerano.
    $other = $m->invite($this->org, 'segreteria@example.com', 'Sara Segreteria', OrgRole::Operator, ownerOperator());
    $secretary = app(Memberships::class)->find($other->id, $this->org->id) ?? throw new RuntimeException('manca');
    expect(fn () => $m->setDeniedAbilities($this->org, $user->id, [], $secretary))->toThrow(Illuminate\Auth\Access\AuthorizationException::class);
    expect(fn () => $m->setDeniedAbilities($this->org, $other->id, ['accounting.income'], ownerOperator()))->toThrow(InvalidArgumentException::class);
    $m->changeRole($this->org, $user->id, OrgRole::Operator, ownerOperator());
    expect($op()?->deniedAbilities)->toBe([])->and($op()?->can(Ability::RecordIncome))->toBeTrue();
});

it('lascia a un ruolo personalizzato i permessi fini del suo permesso largo', function (): void {
    $op = new App\Modules\Core\Contracts\Operator('u', 'o', 'Org', OrgRole::Custom, 'r', 'Cassiere', [Ability::ViewAccounting, Ability::EditAccounting]);
    expect($op->can(Ability::RecordIncome))->toBeTrue()->and($op->can(Ability::ViewFunds))->toBeTrue()->and($op->can(Ability::ManageCourses))->toBeFalse()
        ->and(Ability::assignable())->not->toContain(Ability::RecordIncome);
});
