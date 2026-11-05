<?php

declare(strict_types=1);

use App\Modules\AreaSoci\Http\Controllers\StaffLinkController;
use App\Modules\AreaSoci\Payments\OnlinePayments;
use App\Modules\AreaSoci\Payments\StripeWebhook;
use App\Modules\Attivita\Services\ActivityService;
use App\Modules\Contabilita\Models\Account;
use App\Modules\Core\Contracts\OrgRole;
use App\Modules\Core\Contracts\OrgSettings;
use App\Modules\Portale\Contracts\Entitlements;
use App\Modules\Portale\Contracts\OrgEntitlements;
use App\Modules\Risorse\Services\ResourceService;
use App\Modules\Soci\Models\Person;
use App\Modules\Soci\Services\MembershipService;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

// Dati di prova inventati; le chiavi sono finte e Stripe e PayPal sono simulati.

beforeEach(function (): void {
    app()->instance(Entitlements::class, new class implements Entitlements
    {
        public function for(string $orgId): OrgEntitlements
        {
            return new OrgEntitlements(1, 'active', null, null, 150, 3, 5, ['member_area' => [], 'resources' => ['hourly' => ['tier' => 2, 'web' => true]]], null);
        }
    });
    $this->org = $this->createOrg('Circolo Beta');
    $this->host = 'http://'.$this->org->slug.'.associazionesemplice.it';
    $this->owner = $this->createUser('own@example.com', twoFactor: true);
    $this->addOperator($this->org, $this->owner, OrgRole::Owner);
    [$this->anna, $this->cash, $this->yoga] = $this->orgContext()->run($this->org->id, function (): array {
        app(OrgSettings::class)->set(MembershipService::DEFAULT_FEE, 2500);
        $anna = Person::create(['first_name' => 'Anna', 'last_name' => 'Verdi', 'email' => 'anna@example.com']);
        $yoga = app(ActivityService::class)->save(null, ['name' => 'Yoga', 'kind' => 'course', 'social_year' => app(MembershipService::class)->socialYearLabel(),
            'billing' => 'monthly', 'fee_cents' => 3000, 'weekdays' => [1, 2, 3, 4, 5, 6, 7]]);
        app(ActivityService::class)->enroll($yoga->id, $anna->id, now()->format('Y-m'));

        return [$anna, Account::query()->sole()->id, $yoga];
    });
});

function memberCookie(object $test, string $personId): string
{
    $link = (string) $test->actingAs($test->owner)->withSession(['auth.password_confirmed_at' => time()])->postJson("/gestionale/soci/{$personId}/area-soci/link")->json('link');
    $path = substr($link, strlen(StaffLinkController::baseUrl($test->org->slug)));

    return (string) $test->get($test->host.$path)->getCookie('as_socio', false)?->getValue();
}

function saveStripe(object $test): void
{
    $test->actingAs($test->owner)->withSession(['auth.password_confirmed_at' => time()])->put('/gestionale/pagamenti-online', [
        'stripe_enabled' => true, 'stripe_secret' => 'sk_test_finta123', 'stripe_webhook' => 'whsec_finto456', 'account_id' => $test->cash,
        'paypal_enabled' => true, 'paypal_client_id' => 'client-finto', 'paypal_secret' => 'segreto-finto', 'paypal_live' => false,
    ])->assertSessionHas('status');
}

it('salva le chiavi cifrate e non le restituisce', function (): void {
    saveStripe($this);
    $this->orgContext()->run($this->org->id, function (): void {
        $raw = (string) json_encode(DB::table('app.payment_settings')->first());
        expect($raw)->not->toContain('sk_test_finta123')->not->toContain('whsec_finto456')
            ->and(app(OnlinePayments::class)->providers())->toBe(['stripe', 'paypal']);
    });
    $this->get('/gestionale/pagamenti-online')->assertOk()->assertInertia(fn (Assert $page) => $page->component('AreaSoci/PagamentiOnline')
        ->where('settings.stripe_secret_set', true)->missing('settings.stripe_secret')->where('settings.ready', ['stripe', 'paypal']));
    $this->put('/gestionale/pagamenti-online', ['stripe_secret' => 'non-una-chiave'])->assertSessionHasErrors('stripe_secret');

    $op = $this->createUser('seg@example.com', twoFactor: true);
    $this->addOperator($this->org, $op, OrgRole::Operator);
    $this->actingAs($op)->withSession(['auth.password_confirmed_at' => time()])->put('/gestionale/pagamenti-online', ['stripe_enabled' => false])->assertForbidden();
});

it('paga con Stripe e registra ogni voce una volta sola con la ricevuta', function (): void {
    saveStripe($this);
    $cookie = memberCookie($this, $this->anna->id);
    $member = fn () => $this->withUnencryptedCookie('as_socio', $cookie);

    $keys = [];
    $member()->get($this->host.'/area/paga')->assertOk()->assertInertia(function (Assert $page) use (&$keys) {
        $page->component('AreaSoci/Paga')->where('providers', ['stripe', 'paypal']);
        $keys = array_column($page->toArray()['props']['dues'], 'key');
    });
    expect($keys)->toHaveCount(2)->and($keys[0])->toStartWith('q:')->and($keys[1])->toStartWith('m:');

    Http::fake(['api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_test_1', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_1'])]);
    $member()->post($this->host.'/area/paga', ['keys' => [...$keys, 'q:inventata'], 'provider' => 'stripe'])->assertRedirect('https://checkout.stripe.com/c/pay/cs_test_1');
    Http::assertSent(fn (HttpRequest $r) => str_contains($r->body(), 'line_items') && $r->hasHeader('Authorization', 'Bearer sk_test_finta123'));

    $payload = (string) json_encode(['type' => 'checkout.session.completed', 'data' => ['object' => ['id' => 'cs_test_1', 'payment_status' => 'paid', 'amount_total' => 5500, 'currency' => 'eur']]]);
    $this->call('POST', $this->host.'/area/webhook/stripe', [], [], [], ['HTTP_STRIPE_SIGNATURE' => 't='.time().',v1=sbagliata', 'CONTENT_TYPE' => 'application/json'], $payload)->assertStatus(400);
    $sign = ['HTTP_STRIPE_SIGNATURE' => StripeWebhook::header($payload, 'whsec_finto456', time()), 'CONTENT_TYPE' => 'application/json'];
    $this->call('POST', $this->host.'/area/webhook/stripe', [], [], [], $sign, $payload)->assertOk();
    $this->call('POST', $this->host.'/area/webhook/stripe', [], [], [], $sign, $payload)->assertOk();

    $this->orgContext()->run($this->org->id, function (): void {
        $p = app(OnlinePayments::class)->list(null)[0];
        expect($p['status'])->toBe('settled')->and($p['receipts'])->toHaveCount(2)
            ->and(app(MembershipService::class)->isActive($this->anna->id))->toBeTrue()
            ->and(DB::table('app.transactions')->where('method', 'stripe')->count())->toBe(2)
            ->and(app(OnlinePayments::class)->dues([$this->anna->id]))->toBe([])
            ->and(DB::table('audit.log')->where('action', 'payment.settled')->exists())->toBeTrue();
    });
    $id = $this->orgContext()->run($this->org->id, fn () => app(OnlinePayments::class)->list(null)[0]['id']);
    $member()->get($this->host.'/area/pagamenti/'.$id.'/esito')->assertInertia(fn (Assert $page) => $page->component('AreaSoci/Esito')->where('payment.status', 'settled'));
});

it('paga con PayPal al ritorno e mette da controllare un importo diverso', function (): void {
    saveStripe($this);
    $cookie = memberCookie($this, $this->anna->id);
    $member = fn () => $this->withUnencryptedCookie('as_socio', $cookie);
    $key = $this->orgContext()->run($this->org->id, fn () => app(OnlinePayments::class)->dues([$this->anna->id])[0]['key']);

    Http::fake([
        'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'tok']),
        'api-m.sandbox.paypal.com/v2/checkout/orders' => Http::response(['id' => 'ORD1', 'links' => [['rel' => 'payer-action', 'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=ORD1']]], 201),
        'api-m.sandbox.paypal.com/v2/checkout/orders/ORD1/capture' => Http::response(['id' => 'ORD1', 'status' => 'COMPLETED',
            'purchase_units' => [['payments' => ['captures' => [['id' => 'CAP1', 'status' => 'COMPLETED', 'amount' => ['currency_code' => 'EUR', 'value' => '20.00']]]]]]], 201),
    ]);
    $member()->post($this->host.'/area/paga', ['keys' => [$key], 'provider' => 'paypal'])->assertRedirect('https://www.sandbox.paypal.com/checkoutnow?token=ORD1');
    $id = $this->orgContext()->run($this->org->id, fn () => app(OnlinePayments::class)->list(null)[0]['id']);
    $member()->get($this->host.'/area/pagamenti/'.$id.'/esito')->assertInertia(fn (Assert $page) => $page->where('payment.status', 'review'));
    $this->orgContext()->run($this->org->id, fn () => expect(app(OnlinePayments::class)->find($id)['error'] ?? '')->toContain('20,00 €')
        ->and(app(MembershipService::class)->isActive($this->anna->id))->toBeFalse());

    $this->actingAs($this->owner)->post('/gestionale/pagamenti-online/'.$id.'/controllato')->assertSessionHas('status');
    $this->orgContext()->run($this->org->id, fn () => expect(app(OnlinePayments::class)->find($id)['status'] ?? '')->toBe('settled'));

    // Un altro socio non vede l'esito di questo pagamento.
    $eva = $this->orgContext()->run($this->org->id, fn () => Person::create(['first_name' => 'Eva', 'last_name' => 'Neri', 'email' => 'eva@example.com']));
    $this->withUnencryptedCookie('as_socio', memberCookie($this, $eva->id))->get($this->host.'/area/pagamenti/'.$id.'/esito')->assertNotFound();
});

it('senza metodi attivi o con troppi tentativi non si paga online', function (): void {
    $cookie = memberCookie($this, $this->anna->id);
    $key = $this->orgContext()->run($this->org->id, fn () => app(OnlinePayments::class)->dues([$this->anna->id])[0]['key']);
    $this->withUnencryptedCookie('as_socio', $cookie)->post($this->host.'/area/paga', ['keys' => [$key], 'provider' => 'stripe'])->assertSessionHasErrors('ledger');

    saveStripe($this);
    Http::fake(['api.stripe.com/*' => Http::response(['error' => ['message' => 'chiave non valida']], 401)]);
    $this->withUnencryptedCookie('as_socio', $cookie)->post($this->host.'/area/paga', ['keys' => [$key], 'provider' => 'stripe'])->assertSessionHasErrors('ledger');
    $this->orgContext()->run($this->org->id, fn () => expect(app(OnlinePayments::class)->list(null)[0]['status'])->toBe('failed'));
    $this->actingAs($this->owner)->post('/gestionale/pagamenti-online/prova/stripe')->assertSessionHasErrors('ledger');
});

it('fa prenotare le risorse Web e le mette tra le voci da pagare', function (): void {
    $court = $this->orgContext()->run($this->org->id, fn () => app(ResourceService::class)->save(null, ['name' => 'Campo 1', 'category' => 'court', 'typology' => 'hourly',
        'open_time' => '08:00', 'close_time' => '22:00', 'slot_minutes' => 60, 'min_slots' => 1, 'max_slots' => 2, 'fee_cents' => 1000, 'advance_days' => 7]));
    $cookie = memberCookie($this, $this->anna->id);
    $member = fn () => $this->withUnencryptedCookie('as_socio', $cookie);
    $day = now('Europe/Rome')->addDay()->toDateString();

    $member()->get($this->host.'/area/risorse?giorno='.$day)->assertOk()->assertInertia(fn (Assert $page) => $page->component('AreaSoci/Risorse')
        ->has('slots', 14)->where('member.resources', true));
    $member()->post($this->host.'/area/risorse/'.$court->id.'/prenota', ['date' => $day, 'start' => '18:00', 'units' => 1])->assertSessionHas('status');
    $member()->post($this->host.'/area/risorse/'.$court->id.'/prenota', ['date' => $day, 'start' => '18:00'])->assertSessionHasErrors('ledger');
    $member()->post($this->host.'/area/risorse/'.$court->id.'/prenota', ['date' => now('Europe/Rome')->addDays(30)->toDateString(), 'start' => '18:00'])->assertSessionHasErrors('ledger');
    $member()->post($this->host.'/area/risorse/'.$court->id.'/prenota', ['date' => now('Europe/Rome')->subDay()->toDateString(), 'start' => '18:00'])->assertSessionHasErrors('ledger');

    $this->orgContext()->run($this->org->id, function (): void {
        $dues = app(OnlinePayments::class)->dues([$this->anna->id]);
        expect(array_column($dues, 'group'))->toContain('Risorse')
            ->and(collect($dues)->firstWhere('group', 'Risorse')['amount_cents'] ?? 0)->toBe(1000);
    });
    $res = (string) $this->orgContext()->run($this->org->id, fn () => DB::table('app.reservations')->value('id'));
    $member()->post($this->host.'/area/risorse/prenotazioni/'.$res.'/annulla')->assertSessionHas('status', 'Prenotazione annullata.');
});
