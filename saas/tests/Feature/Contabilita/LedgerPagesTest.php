<?php

declare(strict_types=1);

use App\Modules\Contabilita\Models\Account;
use App\Modules\Contabilita\Models\Category;
use App\Modules\Contabilita\Models\Transaction;
use App\Modules\Core\Contracts\OrgRole;
use Inertia\Testing\AssertableInertia as Assert;

function operatorOf(OrgRole $role): array
{
    $org = test()->createOrg('Polisportiva Alfa');
    $user = test()->createUser($role->value.'@example.com', twoFactor: true);
    test()->addOperator($org, $user, $role);
    [$cash, $membership, $cost] = test()->orgContext()->run($org->id, fn () => [
        Account::query()->sole()->id,
        Category::query()->where('kind', 'membership')->sole()->id,
        Category::query()->where('kind', 'general_cost')->sole()->id,
    ]);

    return [$org, $user, $cash, $membership, $cost];
}

it('registra un incasso dal gestionale e lo mostra in prima nota con la ricevuta', function (): void {
    [$org, $user, $cash, $membership] = operatorOf(OrgRole::Operator);
    $year = now()->format('Y');

    $this->actingAs($user)->from('/gestionale/prima-nota')->post('/gestionale/prima-nota/incassi', [
        'date' => now()->toDateString(),
        'account_id' => $cash,
        'receipt' => true,
        'lines' => [['category_id' => $membership, 'amount' => '30,00', 'description' => 'Quota']],
    ])->assertRedirect('/gestionale/prima-nota')->assertSessionHas('status', "Incasso registrato: ricevuta n. 1/{$year}.");

    $this->get('/gestionale/prima-nota')->assertInertia(fn (Assert $page) => $page->component('Contabilita/PrimaNota')
        ->has('rows', 1)
        ->where('rows.0.amount', '30,00 €')
        ->where('rows.0.receipt', "1/{$year}")
        ->where("balances.{$cash}", '30,00 €')
        ->where('canEdit', true));
});

it('mostra gli errori della prima nota senza registrare nulla', function (): void {
    [$org, $user, $cash, $membership, $cost] = operatorOf(OrgRole::Operator);

    $this->actingAs($user)->post('/gestionale/prima-nota/incassi', [
        'date' => now()->toDateString(), 'account_id' => $cash,
        'lines' => [['category_id' => $cost, 'amount' => '10']],
    ])->assertSessionHasErrors(['ledger' => 'Voce 1: categoria non valida per un incasso.']);

    $this->post('/gestionale/prima-nota/spese', [
        'date' => now()->toDateString(), 'account_id' => $cash, 'category_id' => $cost, 'amount' => 'dieci',
    ])->assertSessionHasErrors('amount');

    expect($this->orgContext()->run($org->id, fn () => Transaction::query()->count()))->toBe(0);
});

it('chiede il motivo per stornare', function (): void {
    [$org, $user, $cash, , $cost] = operatorOf(OrgRole::Operator);
    $this->actingAs($user)->post('/gestionale/prima-nota/spese', [
        'date' => now()->toDateString(), 'account_id' => $cash, 'category_id' => $cost, 'amount' => '12,50',
    ])->assertSessionHasNoErrors();
    $id = $this->orgContext()->run($org->id, fn () => Transaction::query()->sole()->id);

    $this->post("/gestionale/prima-nota/{$id}/storno", ['reason' => ''])->assertSessionHasErrors('reason');
    $this->post("/gestionale/prima-nota/{$id}/storno", ['reason' => 'Doppia'])->assertSessionHas('status', 'Movimento stornato.');

    expect($this->orgContext()->run($org->id, fn () => Transaction::query()->sole()->void_reason))->toBe('Doppia');
});

it('lascia al commercialista solo la lettura', function (): void {
    [$org, $user, $cash, $membership] = operatorOf(OrgRole::Accountant);
    $year = now()->format('Y');

    $this->actingAs($user);
    $this->get('/gestionale/prima-nota')->assertOk()->assertInertia(fn (Assert $page) => $page->where('canEdit', false));
    $this->get('/gestionale/conti')->assertOk();
    $this->get("/gestionale/rendiconto/{$year}")->assertOk()->assertInertia(fn (Assert $page) => $page->component('Contabilita/Rendiconto')->where('canClose', false));

    $this->post('/gestionale/prima-nota/incassi', ['date' => now()->toDateString(), 'account_id' => $cash, 'lines' => [['category_id' => $membership, 'amount' => '1']]])->assertForbidden();
    $this->post('/gestionale/conti', ['name' => 'X', 'type' => 'bank'])->assertForbidden();
    $this->post("/gestionale/esercizi/{$year}/chiudi")->assertForbidden();
});

it('fa chiudere l\'esercizio solo a titolare e amministratore', function (): void {
    [$org, $user] = operatorOf(OrgRole::Operator);
    $year = now()->format('Y');
    $this->actingAs($user)->post("/gestionale/esercizi/{$year}/chiudi")->assertForbidden();

    $admin = $this->createUser('admin@example.com', twoFactor: true);
    $this->addOperator($org, $admin, OrgRole::Admin);
    $this->actingAs($admin)->post("/gestionale/esercizi/{$year}/chiudi")->assertSessionHas('status', "Esercizio {$year} chiuso.");
    $this->get("/gestionale/rendiconto/{$year}")->assertInertia(fn (Assert $page) => $page->where('statement.status', 'closed'));
    $this->post("/gestionale/esercizi/{$year}/riapri", ['reason' => 'Correzione'])->assertSessionHas('status', "Esercizio {$year} riaperto.");
});

it('crea e chiude i conti dal gestionale', function (): void {
    [$org, $user] = operatorOf(OrgRole::Admin);

    $this->actingAs($user)->post('/gestionale/conti', ['name' => 'Conto corrente', 'type' => 'bank', 'opening' => '1.500,00'])->assertSessionHas('status', 'Conto creato.');
    $bank = $this->orgContext()->run($org->id, fn () => Account::query()->where('type', 'bank')->sole());

    $this->post("/gestionale/conti/{$bank->id}/chiudi")->assertSessionHasErrors(['ledger']);
    $this->get('/gestionale/conti')->assertInertia(fn (Assert $page) => $page->component('Contabilita/Conti')->has('accounts', 2)->where('accounts.1.balance', '1.500,00 €'));
});
