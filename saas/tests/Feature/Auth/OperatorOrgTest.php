<?php

declare(strict_types=1);

use App\Modules\Core\Contracts\OrgRole;
use App\Modules\Core\Http\Middleware\IdentifyOperatorOrg;
use App\Modules\Soci\Models\Person;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Gestionale: l'operatore lavora solo nelle associazioni di cui è operatore, e il controllo si ripete a ogni
 * richiesta.
 */

beforeEach(function (): void {
    Route::middleware(['web', 'auth', 'two-factor', 'operator', 'can:people.edit'])
        ->get('/_test/modifica-soci', fn () => 'ok');
    Route::middleware(['web', 'auth', 'two-factor', 'operator', 'can:org.subscription'])
        ->get('/_test/abbonamento', fn () => 'ok');
});

it('apre il gestionale dell\'unica associazione e mostra solo i suoi dati', function (): void {
    $user = $this->createUser('anna@example.com', twoFactor: true);
    $alfa = $this->createOrg('Polisportiva Alfa');
    $beta = $this->createOrg('Circolo Beta');
    $this->addOperator($alfa, $user);
    $this->orgContext()->run($alfa->id, fn () => Person::create(['first_name' => 'Anna', 'last_name' => 'Alfa']));
    $this->orgContext()->run($beta->id, function (): void {
        Person::create(['first_name' => 'Bruno', 'last_name' => 'Beta']);
        Person::create(['first_name' => 'Bice', 'last_name' => 'Beta']);
    });

    $this->actingAs($user)->get('/gestionale')
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertInertia(fn (Assert $page) => $page->component('Dashboard')
            ->where('org.name', 'Polisportiva Alfa')
            ->where('org.role', 'Titolare')
            ->where('peopleCount', 1));

    // A fine richiesta l'associazione è azzerata.
    expect($this->orgSetting())->toBe('');
});

it('con più associazioni chiede quale aprire e apre solo le proprie', function (): void {
    $user = $this->createUser('commercialista@example.com', twoFactor: true);
    $alfa = $this->createOrg('Polisportiva Alfa');
    $beta = $this->createOrg('Circolo Beta');
    $gamma = $this->createOrg('Gruppo Gamma');
    $this->addOperator($alfa, $user, OrgRole::Accountant);
    $this->addOperator($beta, $user, OrgRole::Accountant);

    $this->actingAs($user)->get('/gestionale')->assertRedirect('/associazioni');

    $this->get('/associazioni')->assertInertia(fn (Assert $page) => $page->component('Orgs/Choose')
        ->has('orgs', 2)
        ->where('orgs.0.name', 'Circolo Beta')
        ->where('orgs.1.name', 'Polisportiva Alfa'));

    // Un'associazione non propria non si apre, e la risposta non dice se esiste.
    $this->post("/associazioni/{$gamma->id}")->assertNotFound();
    $this->post('/associazioni/'.Str::uuid7())->assertNotFound();
    $this->get('/gestionale')->assertRedirect('/associazioni');

    $this->post("/associazioni/{$beta->id}")->assertRedirect('/gestionale');
    $this->get('/gestionale')->assertInertia(fn (Assert $page) => $page->where('org.name', 'Circolo Beta')->where('org.role', 'Commercialista'));
});

it('ritira subito l\'accesso quando il ruolo viene tolto', function (): void {
    $user = $this->createUser('anna@example.com', twoFactor: true);
    $alfa = $this->createOrg('Polisportiva Alfa');
    $this->addOperator($alfa, $user);

    $this->actingAs($user)->get('/gestionale')->assertOk();

    DB::table('core.org_users')->where('user_id', $user->id)->delete();

    $this->get('/gestionale')->assertRedirect('/associazioni');
    $this->get('/associazioni')->assertInertia(fn (Assert $page) => $page->has('orgs', 0));
});

it('non apre un\'associazione sospesa o cancellata', function (): void {
    $user = $this->createUser('anna@example.com', twoFactor: true);
    $alfa = $this->createOrg('Polisportiva Alfa');
    $beta = $this->createOrg('Circolo Beta');
    $this->addOperator($alfa, $user);
    $this->addOperator($beta, $user);

    $alfa->status = 'suspended';
    $alfa->save();
    $beta->delete();

    $this->actingAs($user)->withSession([IdentifyOperatorOrg::SESSION_KEY => $alfa->id])
        ->get('/gestionale')->assertRedirect('/associazioni');
    $this->post("/associazioni/{$alfa->id}")->assertNotFound();
    $this->post("/associazioni/{$beta->id}")->assertNotFound();
});

it('applica i permessi del ruolo nell\'associazione corrente', function (OrgRole $role, bool $editPeople, bool $subscription): void {
    $user = $this->createUser('anna@example.com', twoFactor: true);
    $this->addOperator($this->createOrg('Polisportiva Alfa'), $user, $role);

    $this->actingAs($user);
    $this->get('/_test/modifica-soci')->assertStatus($editPeople ? 200 : 403);
    $this->get('/_test/abbonamento')->assertStatus($subscription ? 200 : 403);
})->with([
    'titolare' => [OrgRole::Owner, true, true],
    'amministratore' => [OrgRole::Admin, true, false],
    'segreteria' => [OrgRole::Operator, true, false],
    'commercialista' => [OrgRole::Accountant, false, false],
]);

it('usa il ruolo dell\'associazione scelta, non quello delle altre', function (): void {
    $user = $this->createUser('anna@example.com', twoFactor: true);
    $alfa = $this->createOrg('Polisportiva Alfa');
    $beta = $this->createOrg('Circolo Beta');
    $this->addOperator($alfa, $user, OrgRole::Owner);
    $this->addOperator($beta, $user, OrgRole::Accountant);

    $this->actingAs($user)->post("/associazioni/{$beta->id}");
    $this->get('/_test/modifica-soci')->assertForbidden();

    $this->post("/associazioni/{$alfa->id}");
    $this->get('/_test/modifica-soci')->assertOk();
});

it('non apre il gestionale a chi non è operatore', function (): void {
    $user = $this->createUser('socio@example.com', twoFactor: true);

    $this->actingAs($user)->get('/gestionale')->assertRedirect('/associazioni');
    $this->get('/associazioni')->assertInertia(fn (Assert $page) => $page->has('orgs', 0));
});
