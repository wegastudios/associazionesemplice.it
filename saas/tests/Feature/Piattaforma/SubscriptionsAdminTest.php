<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

require_once __DIR__.'/StaffHelpers.php';

/*
 * Elenco degli abbonamenti in /saas/admin: quello che il portale ha comunicato, in sola lettura.
 */

beforeEach(function (): void {
    loginStaff($this, makeStaff());
});

/** @param  array<string, mixed>  $subscription */
function subscribe(Tests\TestCase $test, string $name, string $ref, string $status, array $subscription, ?int $members = 120): void
{
    DB::table('core.orgs')->where('id', $org->id)->update(['portal_ref' => $ref]);
    DB::table('core.org_entitlements')->insert([
        'org_id' => $org->id,
        'rev' => 1,
        'status' => $status,
        'until' => '2027-03-31',
        'claims' => json_encode(['members' => $members, 'subscription' => $subscription]),
        'token' => 'token-di-prova',
    ]);
}

it('elenca gli abbonamenti con piano, stato, pacchetti e promozione', function (): void {
    subscribe($this->createOrg('Polisportiva Alfa'), 'sub_alfa', 'active', ['plan' => 'gestionale', 'plan_version' => 1, 'addons' => [['code' => 'site', 'qty' => 1], ['code' => 'operators_extra', 'qty' => 2]], 'promo' => 'AUTUNNO26', 'price_cents' => 2150]);
    subscribe($this->createOrg('Circolo Beta'), 'sub_beta', 'trial', ['plan' => 'gestionale', 'plan_version' => 1, 'addons' => [], 'promo' => null, 'price_cents' => null]);

    $this->get('/saas/admin/abbonamenti')->assertOk()
        ->assertSee('Polisportiva Alfa')->assertSee('sub_alfa')->assertSee('gestionale')->assertSee('v1')
        ->assertSee('site, operators_extra ×2')->assertSee('AUTUNNO26')->assertSee('21,50 €')
        ->assertSee('Circolo Beta')->assertSee('In prova: 1')->assertSee('Attivo: 1');
});

it('filtra per stato, per piano e per testo', function (): void {
    subscribe($this->createOrg('Polisportiva Alfa'), 'sub_alfa', 'active', ['plan' => 'gestionale', 'plan_version' => 1, 'addons' => []]);
    subscribe($this->createOrg('Circolo Beta'), 'sub_beta', 'suspended', ['plan' => 'altro', 'plan_version' => 1, 'addons' => []]);

    $this->get('/saas/admin/abbonamenti?stato=suspended')->assertOk()->assertSee('Circolo Beta')->assertDontSee('Polisportiva Alfa');
    $this->get('/saas/admin/abbonamenti?piano=gestionale')->assertOk()->assertSee('Polisportiva Alfa')->assertDontSee('Circolo Beta');
    $this->get('/saas/admin/abbonamenti?q=sub_be')->assertOk()->assertSee('Circolo Beta')->assertDontSee('Polisportiva Alfa');
    $this->get('/saas/admin/abbonamenti?q=100%25')->assertOk()->assertSee('Nessun abbonamento');
});

it('è vuoto senza abbonamenti e visibile anche in sola lettura', function (): void {
    $this->get('/saas/admin/abbonamenti')->assertOk()->assertSee('Nessun abbonamento');

    $this->post('/saas/admin/esci');
    loginStaff($this, makeStaff('lettura@example.test', 'readonly'));
    $this->get('/saas/admin/abbonamenti')->assertOk();
});
