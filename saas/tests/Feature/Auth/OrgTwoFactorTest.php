<?php

declare(strict_types=1);

use App\Modules\Core\Access\OrgTwoFactor;
use App\Modules\Core\Auth\OrgTwoFactorRequiredNotification;
use App\Modules\Core\Contracts\OrgContext;
use App\Modules\Core\Contracts\OrgRole;
use App\Modules\Core\Models\Org;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

// Verifica in due passaggi per chi lavora nel gestionale (decisione del titolare del 26/11/2026): scelta personale,
// obbligatoria solo dove l'associazione la chiede. L'obbligo per tutti è spento, come in produzione.

beforeEach(function (): void {
    config(['saas.auth.require_two_factor' => false]);
});

function requireTwoFactor(Org $org, bool $on = true): void
{
    app(OrgContext::class)->run($org->id, fn (): mixed => app(OrgTwoFactor::class)->setRequiredHere($on));
}

function orgRequiresTwoFactor(Org $org): bool
{
    return app(OrgContext::class)->run($org->id, fn (): bool => app(OrgTwoFactor::class)->requiredHere());
}

it('un\'associazione nuova nasce senza obbligo: si entra anche senza verifica', function (): void {
    $user = $this->createUser('anna@example.com');
    $org = $this->createOrg('Polisportiva Alfa');
    $this->addOperator($org, $user);

    expect(orgRequiresTwoFactor($org))->toBeFalse();
    $this->actingAs($user)->get('/gestionale')->assertOk();
});

it('se l\'associazione la chiede, chi non l\'ha attiva la attiva subito, prima di vedere i dati', function (): void {
    $user = $this->createUser('anna@example.com');
    $org = $this->createOrg('Polisportiva Alfa');
    $this->addOperator($org, $user, OrgRole::Employee);
    requireTwoFactor($org);

    $this->actingAs($user)->get('/gestionale')->assertRedirect('/account/verifica-in-due-passaggi');
    $this->actingAs($user)->getJson('/gestionale')->assertForbidden();

    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])->get('/account/verifica-in-due-passaggi')
        ->assertInertia(fn (Assert $page) => $page->component('Account/TwoFactor')
            ->where('required', true)->where('requiredBy', ['Polisportiva Alfa']));
});

it('con la verifica attiva si entra anche dove l\'associazione la chiede', function (): void {
    $user = $this->createUser('anna@example.com', twoFactor: true);
    $org = $this->createOrg('Polisportiva Alfa');
    $this->addOperator($org, $user, OrgRole::Employee);
    requireTwoFactor($org);

    $this->actingAs($user)->get('/gestionale')->assertOk();
});

it('l\'obbligo di un\'associazione non chiude le altre della stessa persona', function (): void {
    $user = $this->createUser('anna@example.com');
    $alfa = $this->createOrg('Polisportiva Alfa');
    $beta = $this->createOrg('Circolo Beta');
    $this->addOperator($alfa, $user);
    $this->addOperator($beta, $user);
    requireTwoFactor($alfa);

    $this->actingAs($user)->post("/associazioni/{$beta->id}");
    $this->actingAs($user)->get('/gestionale')->assertOk();

    $this->actingAs($user)->post("/associazioni/{$alfa->id}");
    $this->actingAs($user)->get('/gestionale')->assertRedirect('/account/verifica-in-due-passaggi');
});

it('chi l\'ha accesa la può spegnere, se nessuna sua associazione la chiede', function (): void {
    $user = $this->createUser('anna@example.com', twoFactor: true);
    $this->addOperator($this->createOrg('Polisportiva Alfa'), $user, OrgRole::Operator);

    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])
        ->get('/account/verifica-in-due-passaggi')->assertInertia(fn (Assert $page) => $page->where('required', false)->where('requiredBy', []));
    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])
        ->delete('/user/two-factor-authentication')->assertSessionHasNoErrors();

    expect($user->fresh()?->two_factor_secret)->toBeNull();
});

it('non la può spegnere se una sua associazione la chiede, e il messaggio dice quale', function (): void {
    $user = $this->createUser('anna@example.com', twoFactor: true);
    $org = $this->createOrg('Polisportiva Alfa');
    $this->addOperator($org, $user, OrgRole::Operator);
    requireTwoFactor($org);

    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])
        ->delete('/user/two-factor-authentication')
        ->assertSessionHasErrors(['two_factor' => "Non puoi disattivarla: l'associazione «Polisportiva Alfa» chiede la verifica in due passaggi a chi ci lavora."]);

    expect($user->fresh()?->hasConfirmedTwoFactor())->toBeTrue();
});

it('per chiederla a tutti bisogna averla attiva', function (): void {
    $owner = $this->createUser('titolare@example.com');
    $org = $this->createOrg('Polisportiva Alfa');
    $this->addOperator($org, $owner);

    $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => time()])
        ->put('/gestionale/operatori/verifica-obbligatoria', ['required' => true])
        ->assertSessionHasErrors('ledger');

    expect(orgRequiresTwoFactor($org))->toBeFalse();
});

it('accesa dal titolare: avvisa per email chi non l\'ha, lo scrive nel registro, e si rispegne', function (): void {
    Notification::fake();
    $owner = $this->createUser('titolare@example.com', twoFactor: true);
    $without = $this->createUser('dipendente@example.com');
    $with = $this->createUser('operatore@example.com', twoFactor: true);
    $org = $this->createOrg('Polisportiva Alfa');
    $this->addOperator($org, $owner);
    $this->addOperator($org, $without, OrgRole::Employee);
    $this->addOperator($org, $with, OrgRole::Operator);

    $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => time()])
        ->put('/gestionale/operatori/verifica-obbligatoria', ['required' => true])
        ->assertSessionHasNoErrors()->assertSessionHas('status');

    expect(orgRequiresTwoFactor($org))->toBeTrue();
    Notification::assertSentTo($without, OrgTwoFactorRequiredNotification::class, fn (OrgTwoFactorRequiredNotification $n): bool => $n->orgName === 'Polisportiva Alfa');
    Notification::assertNotSentTo($with, OrgTwoFactorRequiredNotification::class);
    Notification::assertNotSentTo($owner, OrgTwoFactorRequiredNotification::class);

    $this->actingAs($owner)->get('/gestionale/operatori')->assertInertia(fn (Assert $page) => $page->where('twoFactorRequired', true));

    $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => time()])
        ->put('/gestionale/operatori/verifica-obbligatoria', ['required' => false])->assertSessionHasNoErrors();
    expect(orgRequiresTwoFactor($org))->toBeFalse();

    $actions = app(OrgContext::class)->run($org->id, fn (): array => DB::table('audit.log')
        ->whereIn('action', ['org.two_factor.required', 'org.two_factor.optional'])->pluck('action')->sort()->values()->all());
    expect($actions)->toBe(['org.two_factor.optional', 'org.two_factor.required']);
});

it('solo titolare e amministratore la cambiano', function (): void {
    $user = $this->createUser('operatore@example.com', twoFactor: true);
    $org = $this->createOrg('Polisportiva Alfa');
    $this->addOperator($org, $user, OrgRole::Operator);

    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])
        ->put('/gestionale/operatori/verifica-obbligatoria', ['required' => true])->assertForbidden();

    expect(orgRequiresTwoFactor($org))->toBeFalse();
});

it('con l\'obbligo per tutti acceso resta come prima: obbligatoria e non si spegne', function (): void {
    config(['saas.auth.require_two_factor' => true]);
    $user = $this->createUser('anna@example.com', twoFactor: true);
    $this->addOperator($this->createOrg('Polisportiva Alfa'), $user, OrgRole::Accountant);

    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])
        ->delete('/user/two-factor-authentication')
        ->assertSessionHasErrors(['two_factor' => 'Per chi lavora nel gestionale la verifica in due passaggi è obbligatoria.']);
});
