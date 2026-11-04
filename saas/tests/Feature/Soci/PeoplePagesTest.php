<?php

declare(strict_types=1);

use App\Modules\Contabilita\Models\Account;
use App\Modules\Core\Contracts\OrgRole;
use App\Modules\Soci\Models\Person;
use Inertia\Testing\AssertableInertia as Assert;

function sociOperator(OrgRole $role): array
{
    $org = test()->createOrg('Polisportiva Alfa');
    $user = test()->createUser($role->value.'@example.com', twoFactor: true);
    test()->addOperator($org, $user, $role);

    return [$org, $user];
}

it('crea una scheda dal gestionale, la cerca e rinnova la tessera', function (): void {
    [$org, $user] = sociOperator(OrgRole::Operator);
    $cash = $this->orgContext()->run($org->id, fn () => Account::query()->sole()->id);

    $this->actingAs($user)->post('/gestionale/soci', ['type' => 'ordinary', 'first_name' => 'Anna', 'last_name' => 'Rossi', 'tax_code' => 'VRDGPP75B10F205B'])
        ->assertRedirect()->assertSessionHas('status', 'Scheda creata.');
    $id = $this->orgContext()->run($org->id, fn () => Person::query()->sole()->id);

    $this->get('/gestionale/soci?q=ross')->assertInertia(fn (Assert $page) => $page->component('Soci/Elenco')->has('rows', 1)->where('rows.0.active', false));
    $this->getJson('/gestionale/soci/cerca?q=anna')->assertOk()->assertJsonPath('0.id', $id);
    $this->get("/gestionale/soci/{$id}")->assertInertia(fn (Assert $page) => $page->component('Soci/Scheda')->where('person.tax_code', 'VRDGPP75B10F205B'));

    $year = now()->format('Y');
    $this->post("/gestionale/soci/{$id}/tessera", ['account_id' => $cash, 'amount' => '30,00', 'date' => now()->toDateString(), 'social_year' => $year])
        ->assertSessionHas('status', "Tessera {$year} registrata, ricevuta n. 1/{$year}.");
    $this->get('/gestionale/soci')->assertInertia(fn (Assert $page) => $page->where('rows.0.active', true)->where('activeCount', 1));
});

it('mostra gli errori della scheda', function (): void {
    [, $user] = sociOperator(OrgRole::Operator);

    $this->actingAs($user)->post('/gestionale/soci', ['type' => 'ordinary', 'first_name' => 'Anna', 'last_name' => 'Rossi', 'tax_code' => 'SBAGLIATO'])
        ->assertSessionHasErrors(['ledger' => 'Il codice fiscale non è valido.']);
});

it('lascia al commercialista la lettura, senza modifiche né tesseramento', function (): void {
    [$org, $user] = sociOperator(OrgRole::Accountant);
    $id = $this->orgContext()->run($org->id, fn () => Person::create(['first_name' => 'Anna', 'last_name' => 'Rossi'])->id);

    $this->actingAs($user);
    $this->get('/gestionale/soci')->assertOk()->assertInertia(fn (Assert $page) => $page->where('canEdit', false));
    $this->get("/gestionale/soci/{$id}")->assertOk();
    $this->post('/gestionale/soci', ['type' => 'ordinary', 'first_name' => 'X', 'last_name' => 'Y'])->assertForbidden();
    $this->put("/gestionale/soci/{$id}", ['type' => 'ordinary', 'first_name' => 'X', 'last_name' => 'Y'])->assertForbidden();
    $this->post("/gestionale/soci/{$id}/tessera", [])->assertForbidden();
});

it('fa cambiare le impostazioni del tesseramento solo a titolare e amministratore', function (): void {
    [$org, $user] = sociOperator(OrgRole::Operator);
    $this->actingAs($user)->post('/gestionale/soci/impostazioni', ['start_month' => 9, 'fee' => '25'])->assertForbidden();

    $admin = $this->createUser('admin@example.com', twoFactor: true);
    $this->addOperator($org, $admin, OrgRole::Admin);
    $this->actingAs($admin)->post('/gestionale/soci/impostazioni', ['start_month' => 9, 'fee' => '25,00'])->assertSessionHas('status');
    $this->get('/gestionale/soci/nuovo')->assertInertia(fn (Assert $page) => $page->where('settings.start_month', 9)->where('settings.fee', '25,00'));
});

it('non apre la scheda di un\'altra associazione', function (): void {
    [, $user] = sociOperator(OrgRole::Owner);
    $other = $this->createOrg('Circolo Beta');
    $id = $this->orgContext()->run($other->id, fn () => Person::create(['first_name' => 'Bruno', 'last_name' => 'Bianchi'])->id);

    $this->actingAs($user)->get("/gestionale/soci/{$id}")->assertNotFound();
});
