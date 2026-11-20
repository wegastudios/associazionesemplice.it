<?php

declare(strict_types=1);

use App\Modules\AreaSoci\Http\Controllers\StaffLinkController;
use App\Modules\AreaSoci\Mail\DonationReceiptMail;
use App\Modules\AreaSoci\Mail\PaymentRefunded;
use App\Modules\AreaSoci\Payments\OnlinePayments;
use App\Modules\AreaSoci\Payments\StripeWebhook;
use App\Modules\Attivita\Contracts\ActivityError;
use App\Modules\Attivita\Services\ActivityService;
use App\Modules\Contabilita\Contracts\PaymentMethod;
use App\Modules\Contabilita\Ledger\FiscalYearService;
use App\Modules\Contabilita\Models\Account;
use App\Modules\Contabilita\Models\Category;
use App\Modules\Contabilita\Models\Transaction;
use App\Modules\Core\Contracts\DonationInvites;
use App\Modules\Core\Contracts\OrgContext;
use App\Modules\Core\Contracts\OrgDonations;
use App\Modules\Core\Contracts\OrgRole;
use App\Modules\Core\Contracts\OrgSettings;
use App\Modules\Portale\Contracts\Entitlements;
use App\Modules\Portale\Contracts\OrgEntitlements;
use App\Modules\Risorse\Contracts\MemberResources;
use App\Modules\Risorse\Contracts\ResourceError;
use App\Modules\Risorse\Payments\ReservationDues;
use App\Modules\Risorse\Services\ReservationService;
use App\Modules\Risorse\Services\ResourceService;
use App\Modules\Soci\Models\Person;
use App\Modules\Soci\Services\MembershipService;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
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
        app(OrgSettings::class)->set('ente.email', 'segreteria@beta.example');
        app(OrgSettings::class)->set('ente.phone', '06 1234567');
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

    return (string) $test->post($test->host.$path)->getCookie('as_socio', false)?->getValue();
}

function saveStripe(object $test): void
{
    Http::preventStrayRequests(); // il salvataggio prova le chiavi: senza risposte finte la prova fallisce, e va bene
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
    // Senza pagamenti online attivi si prenota lo stesso e il messaggio rimanda solo alla segreteria.
    expect($this->orgContext()->run($this->org->id, fn () => app(OnlinePayments::class)->providers()))->toBe([]);
    $member()->post($this->host.'/area/risorse/'.$court->id.'/prenota', ['date' => $day, 'start' => '18:00', 'units' => 1])
        ->assertSessionHas('status', 'Prenotazione registrata: si paga in segreteria.');
    $member()->get($this->host.'/area/risorse?giorno='.$day)->assertInertia(fn (Assert $page) => $page->where('online', false));
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

function reviewPayment(object $test, string $provider = 'stripe'): string
{
    saveStripe($test);
    $id = (string) Illuminate\Support\Str::uuid7();
    (fn () => $this->orgContext())->call($test)->run($test->org->id, function () use ($test, $id, $provider): void {
        DB::table('app.online_payments')->insert([
            'org_id' => $test->org->id, 'id' => $id, 'person_id' => $test->anna->id, 'provider' => $provider, 'status' => 'review', 'amount_cents' => 2500, 'paid_cents' => 2500,
            'items' => '[]', 'provider_ref' => $provider === 'stripe' ? 'cs_test_9' : 'ORD-9', 'error' => 'Quota associativa: Il socio ha già la tessera.',
        ]);
    });

    return $id;
}

/** @param list<array<string, mixed>> $items */
function pendingPayment(object $test, array $items, string $ref): string
{
    $id = (string) Illuminate\Support\Str::uuid7();
    DB::table('app.online_payments')->insert([
        'org_id' => $test->org->id, 'id' => $id, 'person_id' => $test->anna->id, 'provider' => 'stripe', 'status' => 'pending',
        'amount_cents' => array_sum(array_column($items, 'amount_cents')), 'items' => json_encode($items), 'provider_ref' => $ref,
    ]);

    return $id;
}

it('una voce già pagata si registra comunque in prima nota: pagamento da controllare, testo esatto, riga nel registro, mai doppia', function (): void {
    saveStripe($this);
    $this->orgContext()->run($this->org->id, function (): void {
        $payments = app(OnlinePayments::class);
        $q = collect($payments->dues([$this->anna->id]))->first(fn (array $d): bool => str_starts_with($d['key'], 'q:'));
        $id = pendingPayment($this, [$q], 'cs_x');
        app(MembershipService::class)->renewWithPayment($this->anna->id, $this->cash, 2500);

        $payments->settle($id, $q['amount_cents']);
        $payments->settle($id, $q['amount_cents']); // webhook doppio

        $p = $payments->find($id);
        expect($p['status'])->toBe('review')
            ->and($p['error'])->toBe('Già pagata: '.$q['label'].'. L\'incasso è stato registrato comunque: controlla se va restituito.')
            ->and($p['receipts'])->toHaveCount(1)
            ->and($p['paid_cents'])->toBe(2500)
            ->and(DB::table('app.transactions')->where('method', 'stripe')->whereNull('voided_at')->count())->toBe(1)
            ->and(DB::table('audit.log')->where('action', 'payment.item_already_paid')->count())->toBe(1);
    });
});

it('più voci già pagate: testo al plurale; mix di pagate e non pagate: tutte in prima nota, il testo cita solo le pagate', function (): void {
    saveStripe($this);
    $this->orgContext()->run($this->org->id, function (): void {
        $payments = app(OnlinePayments::class);
        $dues = $payments->dues([$this->anna->id]);
        $q = collect($dues)->first(fn (array $d): bool => str_starts_with($d['key'], 'q:'));
        $m = collect($dues)->first(fn (array $d): bool => str_starts_with($d['key'], 'm:'));
        $mix = pendingPayment($this, [$q, $m], 'cs_mix');
        $both = pendingPayment($this, [$q, $m], 'cs_both');

        // Solo la quota è già pagata.
        app(MembershipService::class)->renewWithPayment($this->anna->id, $this->cash, 2500);
        $payments->settle($mix, $q['amount_cents'] + $m['amount_cents']);
        expect($payments->find($mix)['status'])->toBe('review')
            ->and($payments->find($mix)['error'])->toBe('Già pagata: '.$q['label'].'. L\'incasso è stato registrato comunque: controlla se va restituito.')
            ->and($payments->find($mix)['receipts'])->toHaveCount(2)
            ->and(DB::table('app.transactions')->where('method', 'stripe')->count())->toBe(2);

        // Ora anche il mese è pagato: due voci già pagate.
        $payments->settle($both, $q['amount_cents'] + $m['amount_cents']);
        expect($payments->find($both)['error'])->toBe('Già pagate: '.$q['label'].', '.$m['label'].'. L\'incasso è stato registrato comunque: controlla se va restituito.')
            ->and($payments->find($both)['receipts'])->toHaveCount(2)
            ->and(DB::table('app.transactions')->where('method', 'stripe')->count())->toBe(4);
    });
});

it('l\'incasso manuale oltre il residuo resta rifiutato', function (): void {
    $this->orgContext()->run($this->org->id, function (): void {
        $payments = app(OnlinePayments::class);
        $m = collect($payments->dues([$this->anna->id]))->first(fn (array $d): bool => str_starts_with($d['key'], 'm:'));
        [, $act, $person, $month] = explode(':', $m['key']);
        app(ActivityService::class)->collect($act, $person, $this->cash, now()->toDateString(), [$month], $m['amount_cents']);
        expect(fn () => app(ActivityService::class)->collect($act, $person, $this->cash, now()->toDateString(), [$month], $m['amount_cents']))
            ->toThrow(ActivityError::class);
    });
});

it('un secondo pagamento sulla stessa voce già pagata si registra comunque ma va da controllare', function (): void {
    saveStripe($this);
    $this->orgContext()->run($this->org->id, function (): void {
        $payments = app(OnlinePayments::class);
        $due = collect($payments->dues([$this->anna->id]))->first(fn (array $d): bool => str_starts_with($d['key'], 'm:'));
        $ids = [];
        foreach (['cs_a', 'cs_b'] as $ref) {
            $ids[] = $id = (string) Illuminate\Support\Str::uuid7();
            DB::table('app.online_payments')->insert([
                'org_id' => $this->org->id, 'id' => $id, 'person_id' => $this->anna->id, 'provider' => 'stripe', 'status' => 'pending',
                'amount_cents' => $due['amount_cents'], 'items' => json_encode([$due]), 'provider_ref' => $ref,
            ]);
        }

        $payments->settle($ids[0], $due['amount_cents']);
        $payments->settle($ids[1], $due['amount_cents']);

        expect($payments->find($ids[0])['status'])->toBe('settled')
            ->and($payments->find($ids[1])['status'])->toBe('review')
            ->and($payments->find($ids[1])['error'])->toStartWith('Già pagata: ')
            ->and(DB::table('app.transactions')->where('method', 'stripe')->count())->toBe(2);
    });
});

it('rimborsa un pagamento da controllare su Stripe, una parte alla volta, senza superare l\'incassato', function (): void {
    Mail::fake();
    $id = reviewPayment($this);
    Http::fake([
        'api.stripe.com/v1/checkout/sessions/*' => Http::response(['id' => 'cs_test_9', 'payment_intent' => 'pi_9']),
        'api.stripe.com/v1/refunds' => Http::response(['id' => 're_9']),
    ]);
    $this->actingAs($this->owner)->withSession(['auth.password_confirmed_at' => time()]);

    $this->post("/gestionale/pagamenti-online/{$id}/rimborso", ['amount' => '10,00', 'reason' => 'Quota già pagata'])->assertSessionHas('status');
    Http::assertSent(fn (HttpRequest $r): bool => str_ends_with($r->url(), '/v1/refunds') && $r['payment_intent'] === 'pi_9' && (int) $r['amount'] === 1000);

    $this->post("/gestionale/pagamenti-online/{$id}/rimborso", ['amount' => '20,00', 'reason' => 'Quota già pagata'])->assertSessionHasErrors('ledger');
    $this->post("/gestionale/pagamenti-online/{$id}/rimborso", ['amount' => '15,00', 'reason' => 'Quota già pagata'])->assertSessionHas('status');

    $this->orgContext()->run($this->org->id, function () use ($id): void {
        $row = DB::table('app.online_payments')->where('id', $id)->first();
        expect((int) $row->refunded_cents)->toBe(2500)
            ->and((string) $row->error)->toContain('Restituiti')
            ->and(DB::table('audit.log')->where('action', 'payment.refunded')->count())->toBe(2);
    });
    $this->post("/gestionale/pagamenti-online/{$id}/rimborso", ['amount' => '1,00', 'reason' => 'Quota già pagata'])->assertSessionHasErrors('ledger');
    Mail::assertSent(PaymentRefunded::class, 2);
});

it('non rimborsa pagamenti già registrati e rimborsa su PayPal l\'incasso giusto', function (): void {
    $id = reviewPayment($this, 'paypal');
    $this->orgContext()->run($this->org->id, fn () => DB::table('app.online_payments')->where('id', $id)->update(['status' => 'settled']));
    $this->actingAs($this->owner)->withSession(['auth.password_confirmed_at' => time()]);
    $this->post("/gestionale/pagamenti-online/{$id}/rimborso", ['amount' => '5,00', 'reason' => 'Quota già pagata'])->assertSessionHasErrors('ledger');

    $this->orgContext()->run($this->org->id, fn () => DB::table('app.online_payments')->where('id', $id)->update(['status' => 'review']));
    Http::fake([
        'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'tok']),
        'api-m.sandbox.paypal.com/v2/checkout/orders/ORD-9' => Http::response(['purchase_units' => [['payments' => ['captures' => [['id' => 'CAP-9', 'status' => 'COMPLETED']]]]]]),
        'api-m.sandbox.paypal.com/v2/payments/captures/CAP-9/refund' => Http::response(['id' => 'RF-9'], 201),
    ]);
    $this->post("/gestionale/pagamenti-online/{$id}/rimborso", ['amount' => '5,00', 'reason' => 'Quota già pagata'])->assertSessionHas('status');
    Http::assertSent(fn (HttpRequest $r): bool => str_ends_with($r->url(), '/captures/CAP-9/refund') && $r['amount']['value'] === '5.00');
});

it('rimborsa e storna in un gesto solo un pagamento già registrato, e se il rimborso fallisce non storna nulla', function (): void {
    Mail::fake();
    saveStripe($this);
    [$id, $tessera] = $this->orgContext()->run($this->org->id, function (): array {
        $r = app(MembershipService::class)->renewWithPayment($this->anna->id, $this->cash, 2500);
        $id = (string) Illuminate\Support\Str::uuid7();
        DB::table('app.online_payments')->insert([
            'org_id' => $this->org->id, 'id' => $id, 'person_id' => $this->anna->id, 'provider' => 'stripe', 'status' => 'settled', 'amount_cents' => 2500, 'paid_cents' => 2500,
            'items' => '[]', 'receipts' => json_encode([$r['receipt']]), 'provider_ref' => 'cs_test_9',
        ]);

        return [$id, $r['membership_id']];
    });
    $this->actingAs($this->owner)->withSession(['auth.password_confirmed_at' => time()]);

    Http::fake(['api.stripe.com/v1/checkout/sessions/*' => Http::response(['payment_intent' => 'pi_9']), 'api.stripe.com/v1/refunds' => Http::sequence()->push(['error' => ['message' => 'no']], 422)->push(['id' => 're_9'])]);
    $this->post("/gestionale/pagamenti-online/{$id}/rimborso", ['reason' => 'Quota già pagata'])->assertSessionHasErrors('ledger');
    expect(session('errors')->first('ledger'))->toContain('Nulla è stato annullato');
    $this->orgContext()->run($this->org->id, function () use ($tessera): void {
        expect(app(MembershipService::class)->isActive($this->anna->id))->toBeTrue()
            ->and(DB::table('app.transactions')->count())->toBe(1)
            ->and(DB::table('app.transactions')->whereNull('voided_at')->count())->toBe(1)
            ->and(DB::table('app.receipts')->count())->toBe(1)
            ->and(DB::table('app.receipts')->whereNull('voided_at')->count())->toBe(1)
            ->and($tessera)->not->toBe('');
    });
    Mail::assertNothingSent();

    $this->post("/gestionale/pagamenti-online/{$id}/rimborso", ['reason' => 'Quota già pagata'])->assertSessionHas('status');
    Http::assertSent(fn (HttpRequest $r): bool => str_ends_with($r->url(), '/v1/refunds') && (int) $r['amount'] === 2500);
    $this->orgContext()->run($this->org->id, function (): void {
        expect(app(MembershipService::class)->isActive($this->anna->id))->toBeFalse()
            ->and(DB::table('app.transactions')->whereNull('voided_at')->count())->toBe(0)
            ->and((int) DB::table('app.online_payments')->value('refunded_cents'))->toBe(2500);
    });
    Mail::assertSent(PaymentRefunded::class, fn (PaymentRefunded $m): bool => $m->amount === '25,00 €' && $m->reason === 'Quota già pagata');
});

it('rimborsa e avvisa il socio anche senza i contatti della segreteria, senza indirizzo di risposta', function (): void {
    Mail::fake();
    $this->orgContext()->run($this->org->id, fn () => app(OrgSettings::class)->set('ente.email', null));
    $id = reviewPayment($this);
    Http::fake([
        'api.stripe.com/v1/checkout/sessions/*' => Http::response(['id' => 'cs_test_9', 'payment_intent' => 'pi_9']),
        'api.stripe.com/v1/refunds' => Http::response(['id' => 're_9']),
    ]);
    $this->actingAs($this->owner)->withSession(['auth.password_confirmed_at' => time()]);

    $this->post("/gestionale/pagamenti-online/{$id}/rimborso", ['amount' => '10,00', 'reason' => 'Quota già pagata'])->assertSessionHas('status');
    Mail::assertSent(PaymentRefunded::class, fn (PaymentRefunded $m): bool => $m->contactEmail === null && ! $m->hasReplyTo('segreteria@beta.example'));
});

it('dopo «rimborsa e storna» una prenotazione resta valida, occupa ancora la fascia e torna da pagare, e lo dicono schermo ed email', function (): void {
    Mail::fake();
    saveStripe($this);
    $day = now('Europe/Rome')->addDays(3)->format('Y-m-d');
    [$id, $reservation] = $this->orgContext()->run($this->org->id, function () use ($day): array {
        $court = app(ResourceService::class)->save(null, [
            'name' => 'Campo 1', 'category' => 'court', 'typology' => 'hourly', 'open_time' => '08:00', 'close_time' => '22:00', 'slot_minutes' => 60,
            'min_slots' => 1, 'max_slots' => 2, 'fee_cents' => 1000,
        ]);
        $reservations = app(ReservationService::class);
        $r = $reservations->book($court->id, ['person_id' => $this->anna->id, 'date' => $day, 'start' => '10:00', 'units' => 1], null)[0];
        $label = (string) $reservations->collect($r, $this->cash, now()->toDateString(), null, PaymentMethod::Cash);
        $id = (string) Illuminate\Support\Str::uuid7();
        DB::table('app.online_payments')->insert([
            'org_id' => $this->org->id, 'id' => $id, 'person_id' => $this->anna->id, 'provider' => 'stripe', 'status' => 'settled', 'amount_cents' => 1000, 'paid_cents' => 1000,
            'items' => json_encode([['key' => 'r:'.$r, 'person_id' => $this->anna->id, 'group' => 'Risorse', 'label' => 'Campo 1', 'amount_cents' => 1000]]),
            'receipts' => json_encode([$label]), 'provider_ref' => 'cs_test_9',
        ]);

        return [$id, ['id' => $r, 'court' => $court->id]];
    });
    $this->actingAs($this->owner)->withSession(['auth.password_confirmed_at' => time()]);
    Http::fake(['api.stripe.com/v1/checkout/sessions/*' => Http::response(['payment_intent' => 'pi_9']), 'api.stripe.com/v1/refunds' => Http::response(['id' => 're_9'])]);

    $this->get('/gestionale/pagamenti-online')->assertInertia(fn (Assert $page) => $page->where('payments.0.stays_note', OnlinePayments::STAYS_RESERVATION));
    $this->post("/gestionale/pagamenti-online/{$id}/rimborso", ['reason' => 'Prenotazione pagata due volte'])->assertSessionHas('status');
    expect(session('status'))->toContain(OnlinePayments::STAYS_RESERVATION);

    $this->orgContext()->run($this->org->id, function () use ($reservation, $day): void {
        expect(DB::table('app.reservations')->where('id', $reservation['id'])->value('status'))->toBe('booked')
            ->and(array_column(app(ReservationDues::class)->dues([$this->anna->id]), 'key'))->toContain('r:'.$reservation['id'])
            ->and(fn () => app(ReservationService::class)->book($reservation['court'], ['holder_name' => 'Altri', 'date' => $day, 'start' => '10:00', 'units' => 1], null))
            ->toThrow(ResourceError::class);
    });
    Mail::assertSent(PaymentRefunded::class, fn (PaymentRefunded $m): bool => $m->stays === [OnlinePayments::MAIL_RESERVATION]);
});

it('se il gestore non risponde non annulla in silenzio il rimborso: non storna nulla, tiene il promemoria e ripetuto non lo fa due volte', function (): void {
    Mail::fake();
    saveStripe($this);
    $id = $this->orgContext()->run($this->org->id, function (): string {
        $r = app(MembershipService::class)->renewWithPayment($this->anna->id, $this->cash, 2500);
        $id = (string) Illuminate\Support\Str::uuid7();
        DB::table('app.online_payments')->insert([
            'org_id' => $this->org->id, 'id' => $id, 'person_id' => $this->anna->id, 'provider' => 'stripe', 'status' => 'settled', 'amount_cents' => 2500, 'paid_cents' => 2500,
            'items' => '[]', 'receipts' => json_encode([$r['receipt']]), 'provider_ref' => 'cs_test_9',
        ]);

        return $id;
    });
    $this->actingAs($this->owner)->withSession(['auth.password_confirmed_at' => time()]);

    $calls = 0;
    $keys = [];
    Http::fake([
        'api.stripe.com/v1/checkout/sessions/*' => Http::response(['payment_intent' => 'pi_9']),
        'api.stripe.com/v1/refunds' => function (HttpRequest $r) use (&$calls, &$keys) {
            $keys[] = $r->header('Idempotency-Key')[0] ?? '';
            if (++$calls === 1) {
                throw new Illuminate\Http\Client\ConnectionException('timeout');
            }

            return Http::response(['id' => 're_9', 'status' => 'succeeded']);
        },
    ]);

    $this->post("/gestionale/pagamenti-online/{$id}/rimborso", ['reason' => 'Quota già pagata'])->assertSessionHasErrors('ledger');
    expect(session('errors')->first('ledger'))->toContain('non si sa se il rimborso')->toContain('nulla è stato annullato');
    $this->orgContext()->run($this->org->id, function () use ($id): void {
        $row = DB::table('app.online_payments')->where('id', $id)->first();
        expect((int) $row->refund_pending_cents)->toBe(2500)
            ->and((int) $row->refunded_cents)->toBe(0)
            ->and(DB::table('app.transactions')->whereNull('voided_at')->count())->toBe(1)
            ->and(DB::table('audit.log')->where('action', 'payment.refund_unknown')->exists())->toBeTrue();
    });
    Mail::assertNothingSent();

    $this->post("/gestionale/pagamenti-online/{$id}/rimborso", ['reason' => 'Quota già pagata'])->assertSessionHas('status');
    expect($keys)->toHaveCount(2)->and($keys[0])->not->toBe('')->and($keys[1])->toBe($keys[0]);
    $this->orgContext()->run($this->org->id, function () use ($id): void {
        $row = DB::table('app.online_payments')->where('id', $id)->first();
        expect((int) $row->refunded_cents)->toBe(2500)
            ->and($row->refund_pending_cents)->toBeNull()
            ->and(DB::table('app.transactions')->whereNull('voided_at')->count())->toBe(0);
    });
    Mail::assertSent(PaymentRefunded::class, 1);
});

it('un rimborso che il gestore segna come fallito non viene registrato come fatto', function (): void {
    Mail::fake();
    saveStripe($this);
    $id = reviewPayment($this);
    Http::fake([
        'api.stripe.com/v1/checkout/sessions/*' => Http::response(['id' => 'cs_test_9', 'payment_intent' => 'pi_9']),
        'api.stripe.com/v1/refunds' => Http::response(['id' => 're_9', 'status' => 'failed']),
    ]);
    $this->actingAs($this->owner)->withSession(['auth.password_confirmed_at' => time()]);

    $this->post("/gestionale/pagamenti-online/{$id}/rimborso", ['amount' => '10,00', 'reason' => 'Quota già pagata'])->assertSessionHasErrors('ledger');
    $this->orgContext()->run($this->org->id, fn () => expect((int) DB::table('app.online_payments')->where('id', $id)->value('refunded_cents'))->toBe(0));
    Mail::assertNothingSent();
});

it('un errore 5xx del gestore vale come mancata risposta: esito da verificare, promemoria che resta, nulla stornato', function (): void {
    Mail::fake();
    saveStripe($this);
    $id = $this->orgContext()->run($this->org->id, function (): string {
        $r = app(MembershipService::class)->renewWithPayment($this->anna->id, $this->cash, 2500);
        $id = (string) Illuminate\Support\Str::uuid7();
        DB::table('app.online_payments')->insert([
            'org_id' => $this->org->id, 'id' => $id, 'person_id' => $this->anna->id, 'provider' => 'stripe', 'status' => 'settled', 'amount_cents' => 2500, 'paid_cents' => 2500,
            'items' => '[]', 'receipts' => json_encode([$r['receipt']]), 'provider_ref' => 'cs_test_9',
        ]);

        return $id;
    });
    $this->actingAs($this->owner)->withSession(['auth.password_confirmed_at' => time()]);
    Http::fake(['api.stripe.com/v1/checkout/sessions/*' => Http::response(['payment_intent' => 'pi_9']), 'api.stripe.com/v1/refunds' => Http::sequence()->push(['error' => ['message' => 'boom']], 502)->push(['error' => ['message' => 'slow']], 429)->push('<html>', 200)]);

    // 502, 429 e risposta «bene» ma illeggibile: tre esiti incerti, mai un rifiuto.
    foreach ([1, 2, 3] as $_) {
        $this->post("/gestionale/pagamenti-online/{$id}/rimborso", ['reason' => 'Quota già pagata'])->assertSessionHasErrors('ledger');
        expect(session('errors')->first('ledger'))->toContain('non si sa se il rimborso')->not->toContain('Rimborso non riuscito');
    }
    $this->orgContext()->run($this->org->id, function () use ($id): void {
        expect((int) DB::table('app.online_payments')->where('id', $id)->value('refund_pending_cents'))->toBe(2500)
            ->and(DB::table('app.transactions')->whereNull('voided_at')->count())->toBe(1);
    });
});

it('se lo storno non è possibile (anno fiscale chiuso) rifiuta prima di chiamare il gestore', function (): void {
    Mail::fake();
    saveStripe($this);
    $id = $this->orgContext()->run($this->org->id, function (): string {
        $r = app(MembershipService::class)->renewWithPayment($this->anna->id, $this->cash, 2500);
        $id = (string) Illuminate\Support\Str::uuid7();
        DB::table('app.online_payments')->insert([
            'org_id' => $this->org->id, 'id' => $id, 'person_id' => $this->anna->id, 'provider' => 'stripe', 'status' => 'settled', 'amount_cents' => 2500, 'paid_cents' => 2500,
            'items' => '[]', 'receipts' => json_encode([$r['receipt']]), 'provider_ref' => 'cs_test_9',
        ]);
        app(FiscalYearService::class)->close((int) DB::table('app.transactions')->value('fiscal_year'));

        return $id;
    });
    $this->actingAs($this->owner)->withSession(['auth.password_confirmed_at' => time()]);
    Http::fake();

    $this->post("/gestionale/pagamenti-online/{$id}/rimborso", ['reason' => 'Quota già pagata'])->assertSessionHasErrors('ledger');
    expect(session('errors')->first('ledger'))->toContain('è chiuso')->toContain('Il rimborso non è stato fatto');
    Http::assertNothingSent();
    $this->orgContext()->run($this->org->id, fn () => expect(DB::table('app.online_payments')->where('id', $id)->value('refund_pending_cents'))->toBeNull());
});

it('prova le chiavi appena inserite e crea da solo il webhook di Stripe', function (): void {
    Http::fake([
        'api.stripe.com/v1/balance' => Http::response(['object' => 'balance']),
        'api.stripe.com/v1/webhook_endpoints?limit=100' => Http::response(['data' => []]),
        'api.stripe.com/v1/webhook_endpoints' => Http::response(['id' => 'we_1', 'secret' => 'whsec_creato789']),
    ]);

    $this->actingAs($this->owner)->withSession(['auth.password_confirmed_at' => time()])->put('/gestionale/pagamenti-online', [
        'stripe_enabled' => true, 'stripe_secret' => 'sk_test_finta123', 'account_id' => $this->cash,
    ])->assertSessionHas('status', fn (string $m) => str_contains($m, 'Stripe funziona') && str_contains($m, 'webhook di Stripe è stato creato'))->assertSessionHasNoErrors();

    $this->orgContext()->run($this->org->id, fn () => expect(app(OnlinePayments::class)->settings()?->stripe_webhook)->toBe('whsec_creato789'));
    Http::assertSent(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/v1/webhook_endpoints') && $request['enabled_events'] === ['checkout.session.completed'] && str_ends_with((string) $request['url'], '/area/webhook/stripe'));
});

it('salva comunque le chiavi se la prova non riesce e lo dice', function (): void {
    Http::fake(['api.stripe.com/*' => Http::response(['error' => ['message' => 'chiave non valida']], 401)]);

    $this->actingAs($this->owner)->withSession(['auth.password_confirmed_at' => time()])->put('/gestionale/pagamenti-online', [
        'stripe_enabled' => true, 'stripe_secret' => 'sk_test_finta123', 'account_id' => $this->cash,
    ])->assertSessionHasErrors(['ledger' => 'La prova della connessione con Stripe non è riuscita: Stripe: chiave non valida']);

    $this->orgContext()->run($this->org->id, fn () => expect(app(OnlinePayments::class)->settings()?->stripe_secret)->toBe('sk_test_finta123'));
});

it('se su Stripe c\'è già il webhook non lo duplica e chiede il segreto a mano', function (): void {
    Http::fake([
        'api.stripe.com/v1/balance' => Http::response(['object' => 'balance']),
        'api.stripe.com/v1/webhook_endpoints?limit=100' => fn ($request) => Http::response(['data' => [['url' => StaffLinkController::baseUrl($this->org->slug).'/area/webhook/stripe']]]),
    ]);

    $this->actingAs($this->owner)->withSession(['auth.password_confirmed_at' => time()])->put('/gestionale/pagamenti-online', [
        'stripe_enabled' => true, 'stripe_secret' => 'sk_test_finta123', 'account_id' => $this->cash,
    ])->assertSessionHasErrors(['ledger' => "Su Stripe c'è già un webhook per questo indirizzo: Stripe non mostra più il suo segreto, copialo a mano dal pannello di Stripe."]);
});

it('aggiunge una donazione al pagamento: voce a parte, erogazione liberale con la sua ricevuta', function (): void {
    saveStripe($this);
    $cookie = memberCookie($this, $this->anna->id);
    $member = fn () => $this->withUnencryptedCookie('as_socio', $cookie);
    Http::fake(['api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_test_d', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_d'])]);

    // spenta di default: la donazione non si accetta
    $member()->post($this->host.'/area/paga', ['keys' => [], 'donation_cents' => 500, 'provider' => 'stripe'])->assertSessionHasErrors('ledger');
    $this->orgContext()->run($this->org->id, fn () => app(OrgSettings::class)->set(OrgDonations::CHECKOUT, true));
    $member()->get($this->host.'/area/paga')->assertInertia(fn (Assert $page) => $page->where('donation.enabled', true)->where('donation.amount_cents', 500));
    $member()->post($this->host.'/area/paga', ['keys' => [], 'donation_cents' => 50, 'provider' => 'stripe'])->assertSessionHasErrors('ledger');
    $member()->post($this->host.'/area/paga', ['keys' => [], 'donation_cents' => 500, 'provider' => 'stripe'])->assertRedirect('https://checkout.stripe.com/c/pay/cs_test_d');

    $payload = (string) json_encode(['type' => 'checkout.session.completed', 'data' => ['object' => ['id' => 'cs_test_d', 'payment_status' => 'paid', 'amount_total' => 500, 'currency' => 'eur']]]);
    $sign = ['HTTP_STRIPE_SIGNATURE' => StripeWebhook::header($payload, 'whsec_finto456', time()), 'CONTENT_TYPE' => 'application/json'];
    $this->call('POST', $this->host.'/area/webhook/stripe', [], [], [], $sign, $payload)->assertOk();

    $this->orgContext()->run($this->org->id, function (): void {
        $p = app(OnlinePayments::class)->list(null)[0];
        $tx = Transaction::query()->where('method', 'stripe')->sole();
        expect($p['status'])->toBe('settled')->and($p['receipts'])->toHaveCount(1)->and($tx->amount_cents)->toBe(500)->and($tx->vat_rate)->toBeNull()
            ->and(Category::query()->findOrFail($tx->category_id)->kind->value)->toBe('donation');
    });
});

it('la spunta della donazione si imposta nei pagamenti online, con l\'importo proposto', function (): void {
    $this->actingAs($this->owner)->put('/gestionale/pagamenti-online/donazioni', ['checkout' => true, 'deductible' => true, 'amount' => '15,50'])->assertSessionHasNoErrors();
    $this->actingAs($this->owner)->put('/gestionale/pagamenti-online/donazioni', ['checkout' => true, 'amount' => '0,10'])->assertSessionHasErrors('amount');
    $this->orgContext()->run($this->org->id, function (): void {
        expect(app(OrgDonations::class)->checkout())->toBeTrue()->and(app(OrgDonations::class)->deductible())->toBeTrue()->and(app(OrgDonations::class)->amountCents())->toBe(1550);
    });
});

function publicDonationOn(object $test): void
{
    saveStripe($test);
    app(OrgContext::class)->run($test->org->id, fn () => app(OrgSettings::class)->set(OrgDonations::CHECKOUT, true));
}

function publicDonate(object $test, array $over = []): object
{
    return $test->post('/dona/'.$test->org->slug, $over + ['first_name' => 'Luisa', 'last_name' => 'Neri', 'email' => 'luisa@example.com', 'tax_code' => '',
        'amount' => '12,50', 'provider' => 'stripe', 'privacy_ok' => true, 'website' => '']);
}

it('la pagina pubblica di donazione esiste solo con la spunta accesa e un metodo attivo', function (): void {
    $this->get('/dona/'.$this->org->slug)->assertNotFound();
    publicDonationOn($this);
    $this->get('/dona/'.$this->org->slug)->assertOk()->assertInertia(fn (Assert $page) => $page->component('AreaSoci/Dona')->where('amount', '5,00')->where('providers', ['stripe', 'paypal']));
    $this->get('/dona/slug-che-non-esiste')->assertNotFound();
});

it('chi non è socio dona senza login: nasce un sostenitore, l\'incasso è un\'erogazione liberale e la ricevuta arriva per email', function (): void {
    publicDonationOn($this);
    Mail::fake();
    Http::fake(['api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_pub_1', 'url' => 'https://checkout.stripe.com/c/pay/cs_pub_1'])]);

    publicDonate($this)->assertRedirect('https://checkout.stripe.com/c/pay/cs_pub_1');
    Http::assertSent(fn (HttpRequest $r) => str_contains(urldecode($r->body()), '/dona/'.$this->org->slug.'/') && str_contains($r->body(), 'unit_amount') && str_contains($r->body(), '1250'));

    $payload = (string) json_encode(['type' => 'checkout.session.completed', 'data' => ['object' => ['id' => 'cs_pub_1', 'payment_status' => 'paid', 'amount_total' => 1250, 'currency' => 'eur']]]);
    $sign = ['HTTP_STRIPE_SIGNATURE' => StripeWebhook::header($payload, 'whsec_finto456', time()), 'CONTENT_TYPE' => 'application/json'];
    $this->call('POST', $this->host.'/area/webhook/stripe', [], [], [], $sign, $payload)->assertOk();

    $this->orgContext()->run($this->org->id, function (): void {
        $donor = Person::query()->where('email', 'luisa@example.com')->sole();
        $tx = Transaction::query()->where('method', 'stripe')->sole();
        expect($donor->type->value)->toBe('supporter')->and($tx->person_id)->toBe($donor->id)->and($tx->amount_cents)->toBe(1250)
            ->and(Category::query()->findOrFail($tx->category_id)->kind->value)->toBe('donation')
            ->and(app(OnlinePayments::class)->list(null)[0]['status'])->toBe('settled');
    });
    Mail::assertSent(DonationReceiptMail::class, fn (DonationReceiptMail $m) => $m->hasTo('luisa@example.com'));
    $id = $this->orgContext()->run($this->org->id, fn () => app(OnlinePayments::class)->list(null)[0]['id']);
    $this->get('/dona/'.$this->org->slug.'/'.$id.'/esito')->assertOk()->assertInertia(fn (Assert $page) => $page->component('AreaSoci/DonaEsito')->where('payment.status', 'settled'));
});

it('se l\'email è di un socio la donazione va sulla sua scheda e non nasce nessun sostenitore', function (): void {
    publicDonationOn($this);
    Http::fake(['api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_pub_2', 'url' => 'https://checkout.stripe.com/c/pay/cs_pub_2'])]);

    publicDonate($this, ['email' => 'Anna@Example.com', 'first_name' => 'Altro', 'last_name' => 'Nome'])->assertRedirect('https://checkout.stripe.com/c/pay/cs_pub_2');

    $this->orgContext()->run($this->org->id, function (): void {
        expect(Person::query()->where('type', 'supporter')->count())->toBe(0)
            ->and(app(OnlinePayments::class)->list(null)[0]['person_id'])->toBe($this->anna->id);
    });
});

it('la donazione pubblica rifiuta importi fuori limite, la casella dell\'informativa mancante e la trappola dei programmi automatici', function (): void {
    publicDonationOn($this);
    Http::fake();

    publicDonate($this, ['amount' => '0,50'])->assertSessionHasErrors('amount');
    publicDonate($this, ['amount' => '1000,01'])->assertSessionHasErrors('amount');
    publicDonate($this, ['privacy_ok' => false])->assertSessionHasErrors('privacy_ok');
    publicDonate($this, ['email' => 'non-una-email'])->assertSessionHasErrors('email');
    publicDonate($this, ['website' => 'http://spam.example'])->assertRedirect('/dona/'.$this->org->slug);

    Http::assertNothingSent();
    $this->orgContext()->run($this->org->id, fn () => expect(app(OnlinePayments::class)->list(null))->toBe([])->and(Person::query()->where('type', 'supporter')->count())->toBe(0));
});

it('la stessa email può provare solo poche volte l\'ora', function (): void {
    publicDonationOn($this);
    Http::fake(['api.stripe.com/v1/checkout/sessions' => fn () => Http::response(['id' => 'cs_pub_'.bin2hex(random_bytes(4)), 'url' => 'https://checkout.stripe.com/c/pay/x'])]);

    foreach (range(1, 5) as $i) {
        publicDonate($this)->assertRedirect();
    }

    publicDonate($this)->assertSessionHasErrors('email');
});

it('l\'invito a donare compare solo nei posti accesi e solo se c\'è un modo per donare', function (): void {
    $cookie = memberCookie($this, $this->anna->id);
    $member = fn () => $this->withUnencryptedCookie('as_socio', $cookie);
    $invite = fn (): array => $this->orgContext()->run($this->org->id, fn () => array_map(app(DonationInvites::class)->enabled(...), DonationInvites::SPOTS));

    // tutto spento di default
    expect($invite())->toBe([false, false, false, false]);

    // posti accesi ma nessun metodo di pagamento e spunta spenta: l'invito non compare
    $this->actingAs($this->owner)->put('/gestionale/pagamenti-online/donazioni', ['checkout' => false, 'amount' => '5', 'invite_area' => true, 'invite_event' => true])->assertSessionHasNoErrors();
    expect($invite())->toBe([false, false, false, false]);

    // con la spunta e un metodo attivo compaiono solo i posti accesi
    saveStripe($this);
    $this->actingAs($this->owner)->put('/gestionale/pagamenti-online/donazioni', ['checkout' => true, 'amount' => '5', 'invite_area' => true, 'invite_event' => true])->assertSessionHasNoErrors();
    expect($invite())->toBe([true, false, true, false]);
    $member()->get($this->host.'/area')->assertInertia(fn (Assert $page) => $page->where('member.invite.area', true)->where('member.invite.event', true));
});

it('un socio non tiene più di un certo numero di prenotazioni da usare', function (): void {
    config(['saas.resources.member_max_active' => 2]);
    $this->orgContext()->run($this->org->id, function (): void {
        $court = app(ResourceService::class)->save(null, ['name' => 'Campo 1', 'category' => 'court', 'typology' => 'hourly', 'open_time' => '08:00', 'close_time' => '22:00',
            'slot_minutes' => 60, 'min_slots' => 1, 'max_slots' => 2, 'fee_cents' => 1000, 'advance_days' => 14]);
        $resources = app(MemberResources::class);
        $day = now('Europe/Rome')->addDays(2)->toDateString();

        $first = $resources->book($court->id, $this->anna->id, ['date' => $day, 'start' => '10:00']);
        $resources->book($court->id, $this->anna->id, ['date' => $day, 'start' => '11:00']);
        expect(fn () => $resources->book($court->id, $this->anna->id, ['date' => $day, 'start' => '12:00']))->toThrow(ResourceError::class, 'Hai già 2 prenotazioni da usare');

        // Annullandone una si libera un posto.
        $resources->cancel($first, $this->anna->id);
        $resources->book($court->id, $this->anna->id, ['date' => $day, 'start' => '12:00']);
        expect($resources->mine([$this->anna->id]))->toHaveCount(2);
    });
});

it('con l\'anticipo a zero il socio ha comunque un anticipo massimo di partenza; la segreteria no', function (): void {
    config(['saas.resources.member_default_advance_days' => 10]);
    $this->orgContext()->run($this->org->id, function (): void {
        $court = app(ResourceService::class)->save(null, ['name' => 'Campo 1', 'category' => 'court', 'typology' => 'hourly', 'open_time' => '08:00', 'close_time' => '22:00',
            'slot_minutes' => 60, 'min_slots' => 1, 'max_slots' => 2, 'fee_cents' => 1000, 'advance_days' => 0]);
        $resources = app(MemberResources::class);
        $far = now('Europe/Rome')->addDays(11)->toDateString();

        expect(fn () => $resources->book($court->id, $this->anna->id, ['date' => $far, 'start' => '10:00']))->toThrow(ResourceError::class, 'al massimo 10 giorni')
            ->and($resources->book($court->id, $this->anna->id, ['date' => now('Europe/Rome')->addDays(10)->toDateString(), 'start' => '10:00']))->toBeString()
            // La segreteria prenota dove vuole.
            ->and(app(ReservationService::class)->book($court->id, ['person_id' => $this->anna->id, 'date' => $far, 'start' => '10:00', 'units' => 1], null))->toHaveCount(1);

        // Se la risorsa indica un anticipo, vale quello.
        $short = app(ResourceService::class)->save(null, ['name' => 'Campo 2', 'category' => 'court', 'typology' => 'hourly', 'open_time' => '08:00', 'close_time' => '22:00',
            'slot_minutes' => 60, 'min_slots' => 1, 'max_slots' => 2, 'fee_cents' => 1000, 'advance_days' => 3]);
        expect(fn () => $resources->book($short->id, $this->anna->id, ['date' => now('Europe/Rome')->addDays(5)->toDateString(), 'start' => '10:00']))->toThrow(ResourceError::class, 'al massimo 3 giorni');
    });
});

it('prenota, annulla, lista d\'attesa, recapiti e ricevute hanno un limite di frequenza per socio e per indirizzo', function (): void {
    foreach (['member-book' => [20, 60], 'member-cancel' => [20, 60], 'member-profile' => [10, 30], 'member-receipt' => [30, 100], 'member-pay-result' => [60, 200]] as $name => [$perMember, $perIp]) {
        $one = Illuminate\Support\Facades\RateLimiter::limiter($name)(Illuminate\Http\Request::create('/', 'GET', [], ['as_socio' => 'uno']));
        $two = Illuminate\Support\Facades\RateLimiter::limiter($name)(Illuminate\Http\Request::create('/', 'GET', [], ['as_socio' => 'due']));
        expect(array_map(static fn ($l) => $l->maxAttempts, $one))->toBe([$perMember, $perIp])
            ->and($one[0]->key)->not->toBe($two[0]->key)->and($one[1]->key)->toBe($two[1]->key)
            ->and($one[0]->key)->not->toContain('uno');
    }
});

it('i recapiti si cambiano al massimo dieci volte ogni dieci minuti', function (): void {
    $cookie = memberCookie($this, $this->anna->id);
    foreach (range(1, 10) as $i) {
        expect($this->withUnencryptedCookie('as_socio', $cookie)->put($this->host.'/area/profilo', ['phone' => '333 000000'.$i])->getStatusCode())->not->toBe(429);
    }
    $this->withUnencryptedCookie('as_socio', $cookie)->put($this->host.'/area/profilo', ['phone' => '333 0000099'])->assertStatus(429);
});

it('l\'esito di un pagamento ancora in attesa non chiama il gestore a ogni apertura', function (): void {
    saveStripe($this);
    $cookie = memberCookie($this, $this->anna->id);
    $member = fn () => $this->withUnencryptedCookie('as_socio', $cookie);
    $key = $this->orgContext()->run($this->org->id, fn () => app(OnlinePayments::class)->dues([$this->anna->id])[0]['key']);
    Http::fake([
        'api.stripe.com/v1/checkout/sessions/cs_test_7' => Http::response(['id' => 'cs_test_7', 'payment_status' => 'unpaid']),
        'api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_test_7', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_7']),
    ]);
    $member()->post($this->host.'/area/paga', ['keys' => [$key], 'provider' => 'stripe'])->assertRedirect('https://checkout.stripe.com/c/pay/cs_test_7');
    $id = $this->orgContext()->run($this->org->id, fn () => app(OnlinePayments::class)->list(null)[0]['id']);

    foreach (range(1, 5) as $_) {
        $member()->get($this->host.'/area/pagamenti/'.$id.'/esito')->assertInertia(fn (Assert $page) => $page->where('payment.status', 'pending'));
    }
    expect(Http::recorded(static fn (HttpRequest $r): bool => $r->method() === 'GET' && str_contains($r->url(), 'cs_test_7'))->count())->toBe(1);
});
