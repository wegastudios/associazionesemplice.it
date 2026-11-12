<?php

declare(strict_types=1);

use App\Modules\Piattaforma\Contracts\Catalog;
use App\Modules\Piattaforma\Contracts\PublishedCatalog;
use App\Modules\Portale\Entitlements\DomainDns;
use App\Modules\Soci\Contracts\Members;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

require_once __DIR__.'/PortalHelpers.php';

/*
 * Promozioni, cambio di piano, dominio e stato (CONTRATTO_PORTALE.md §3.3-§3.6).
 */

/** @param  array<string, mixed>  $extra */
function servicesCatalog(array $extra = []): Catalog
{
    /** @var array<string, mixed> $doc */
    $doc = config('catalogo');
    $doc['plans'][0]['limits']['packages'] = ['site' => []];
    $small = $doc['plans'][0];
    $small['code'] = 'piccolo';
    $small['name'] = 'Piccolo';
    $small['limits']['members'] = 100;
    $small['limits']['operators'] = 0;
    $small['limits']['packages'] = [];
    $doc['plans'][] = $small;
    $doc['promos'] = [
        ['code' => 'AUTUNNO', 'name' => 'Autunno', 'discount' => ['type' => 'percent', 'value' => 20], 'plans' => ['gestionale'], 'valid_from' => '2020-01-01', 'valid_to' => '2099-12-31', 'max_uses' => 2, 'stackable' => false],
        ['code' => 'SCADUTA', 'name' => 'Vecchia', 'discount' => ['type' => 'percent', 'value' => 10], 'plans' => null, 'valid_from' => '2020-01-01', 'valid_to' => '2020-12-31', 'max_uses' => null, 'stackable' => false],
        ['code' => 'LIBERA', 'name' => 'Libera', 'discount' => ['type' => 'amount_cents', 'value' => 500], 'plans' => null, 'valid_from' => null, 'valid_to' => null, 'max_uses' => null, 'stackable' => false],
    ];

    return new readonly class(new PublishedCatalog(2, $doc)) implements Catalog
    {
        public function __construct(private PublishedCatalog $catalog) {}

        public function published(): PublishedCatalog
        {
            return $this->catalog;
        }

        public function plan(string $code, int $version): ?array
        {
            return $this->catalog->plan($code, $version);
        }
    };
}

/** @return array<string, mixed> */
function servicesSubscription(string $orgId, int $rev, array $addons): array
{
    return [
        'sub' => $orgId, 'ref' => 'sub_123', 'rev' => $rev, 'status' => 'active', 'until' => now()->addMonth()->toDateString(), 'trial_ends' => null,
        'plan' => 'gestionale', 'plan_version' => 1, 'members' => 150, 'addons' => $addons, 'promo' => null, 'price_cents' => null, 'partner' => null,
    ];
}

beforeEach(function (): void {
    Notification::fake();
    $this->app->instance(Catalog::class, servicesCatalog());
    $this->portal = fakePortal();
    config(['saas.domains.nameservers' => ['ns1.example.test', 'ns2.example.test']]);
    $this->orgId = (string) $this->postJson('/api/portale/v1/orgs', ['token' => portalToken($this->portal, 'provision', [
        'ref' => 'sub_123',
        'org' => ['name' => 'Polisportiva Alfa', 'slug' => 'polisportiva-alfa'],
        'owner' => ['email' => 'titolare@example.com', 'name' => 'Anna Rossi'],
    ])])->json('org_id');
    $this->call = fn (string $path, string $type, array $claims) => $this->postJson('/api/portale/v2/'.$path, [
        'token' => portalToken($this->portal, $type, $claims),
    ]);
});

it('controlla una promozione senza consumarla', function (): void {
    $check = fn (string $code, string $plan = 'gestionale') => ($this->call)('promo/check', 'promo_check', ['code' => $code, 'plan' => $plan, 'plan_version' => 1, 'period' => 'month']);

    $check('autunno')->assertOk()->assertExactJson([
        'valid' => true, 'discount' => ['type' => 'percent', 'value' => 20], 'valid_to' => '2099-12-31', 'applies_to' => ['gestionale'], 'stackable' => false,
    ]);
    $check('AUTUNNO', 'piccolo')->assertOk()->assertExactJson(['valid' => false, 'reason' => 'wrong_plan']);
    $check('SCADUTA')->assertOk()->assertExactJson(['valid' => false, 'reason' => 'expired']);
    $check('INVENTATA')->assertOk()->assertExactJson(['valid' => false, 'reason' => 'unknown']);
    expect(DB::table('core.promo_redemptions')->count())->toBe(0);
});

it('riscatta una promozione una volta sola per ordine e si esaurisce', function (): void {
    $redeem = fn (string $order) => ($this->call)('promo/redeem', 'promo_redeem', ['code' => 'AUTUNNO', 'order' => $order, 'ref' => 'sub_123']);

    $redeem('ord_1')->assertOk()->assertExactJson(['redeemed' => true, 'uses_left' => 1]);
    $redeem('ord_1')->assertOk()->assertExactJson(['redeemed' => true, 'uses_left' => 1]);   // stesso ordine: stesso esito
    $redeem('ord_2')->assertOk()->assertExactJson(['redeemed' => true, 'uses_left' => 0]);
    $redeem('ord_3')->assertStatus(409)->assertExactJson(['error' => 'exhausted']);

    expect(DB::table('core.promo_redemptions')->count())->toBe(2);
    ($this->call)('promo/check', 'promo_check', ['code' => 'AUTUNNO', 'plan' => 'gestionale', 'plan_version' => 1, 'period' => 'year'])
        ->assertOk()->assertExactJson(['valid' => false, 'reason' => 'exhausted']);

    // Una promozione illimitata si riscatta senza fine; un codice sconosciuto non si riscatta.
    ($this->call)('promo/redeem', 'promo_redeem', ['code' => 'LIBERA', 'order' => 'ord_9', 'ref' => 'sub_123'])->assertOk()->assertExactJson(['redeemed' => true, 'uses_left' => null]);
    ($this->call)('promo/redeem', 'promo_redeem', ['code' => 'NONESISTE', 'order' => 'ord_10', 'ref' => 'sub_123'])->assertStatus(409);
});

it('rifiuta il riuso dello stesso token di riscatto e i campi non previsti', function (): void {
    $token = portalToken($this->portal, 'promo_redeem', ['code' => 'LIBERA', 'order' => 'ord_5', 'ref' => 'sub_123']);

    $this->postJson('/api/portale/v2/promo/redeem', ['token' => $token])->assertOk();
    $this->postJson('/api/portale/v2/promo/redeem', ['token' => $token])->assertStatus(409)->assertExactJson(['error' => 'replayed']);
    ($this->call)('promo/check', 'promo_check', ['code' => 'LIBERA', 'plan' => 'gestionale', 'period' => 'month', 'extra' => 1])->assertStatus(422);
    ($this->call)('promo/check', 'promo_check', ['code' => 'LIBERA', 'plan' => 'gestionale', 'period' => 'decade'])->assertStatus(422);
    $this->postJson('/api/portale/v2/promo/check', ['token' => portalToken($this->portal, 'promo_redeem', ['code' => 'LIBERA', 'plan' => 'gestionale', 'period' => 'month'])])->assertUnauthorized();
    $this->postJson('/api/portale/v2/promo/check', [])->assertStatus(400);
});

it('dice se un cambio di piano è possibile e che cosa cambia', function (): void {
    $this->mock(Members::class, fn ($mock) => $mock->shouldReceive('activeCount')->andReturn(120));
    $put = $this->putJson("/api/portale/v2/orgs/{$this->orgId}/subscription", ['token' => portalToken($this->portal, 'subscription', servicesSubscription($this->orgId, 1, [['code' => 'member_area', 'qty' => 1]]))]);
    $put->assertOk();

    $check = fn (string $plan) => ($this->call)("orgs/{$this->orgId}/plan-change/check", 'plan_change_check', ['ref' => 'sub_123', 'to_plan' => $plan, 'to_plan_version' => 1]);

    $check('gestionale')->assertOk()->assertExactJson(['allowed' => true, 'blockers' => [], 'effects' => []]);
    $response = $check('piccolo')->assertOk();
    expect($response->json('allowed'))->toBeFalse()
        ->and($response->json('blockers'))->toBe(['Hai 120 soci e il piano ne ammette 100', 'Hai 1 operatori attivi e il piano ne ammette 0'])
        ->and($response->json('effects'))->toBe(['Si perde il pacchetto Sito']);

    ($this->call)("orgs/{$this->orgId}/plan-change/check", 'plan_change_check', ['ref' => 'sub_123', 'to_plan' => 'ignoto', 'to_plan_version' => 1])->assertStatus(409)->assertExactJson(['error' => 'unknown_plan']);
    ($this->call)("orgs/{$this->orgId}/plan-change/check", 'plan_change_check', ['ref' => 'sub_999', 'to_plan' => 'gestionale', 'to_plan_version' => 1])->assertNotFound();
});

it('controlla e imposta il dominio personalizzato, con le istruzioni per il DNS', function (): void {
    $set = fn (?string $domain) => ($this->call)("orgs/{$this->orgId}/domain/set", 'domain_set', ['ref' => 'sub_123', 'domain' => $domain]);
    $check = fn (string $domain) => ($this->call)("orgs/{$this->orgId}/domain/check", 'domain_check', ['ref' => 'sub_123', 'domain' => $domain]);

    // Senza il pacchetto non si può.
    $check('www.polisportivaalfa.it')->assertOk()->assertExactJson(['ok' => false, 'reason' => 'no_package']);

    $this->putJson("/api/portale/v2/orgs/{$this->orgId}/subscription", ['token' => portalToken($this->portal, 'subscription', servicesSubscription($this->orgId, 1, [['code' => 'custom_domain', 'qty' => 1]]))])->assertOk();

    $check('www.polisportivaalfa.it')->assertOk()->assertExactJson(['ok' => true]);
    $check('non un dominio')->assertOk()->assertExactJson(['ok' => false, 'reason' => 'invalid']);
    $check('www.associazionesemplice.it')->assertOk();   // il dominio base dipende dall'indirizzo del SaaS di prova
    $check('app.example.test')->assertOk()->assertExactJson(['ok' => false, 'reason' => 'reserved']);

    $set('WWW.PolisportivaAlfa.it')->assertOk()->assertExactJson([
        'domain' => 'www.polisportivaalfa.it', 'state' => 'pending',
        'dns' => [
            ['type' => 'NS', 'name' => 'www.polisportivaalfa.it', 'value' => 'ns1.example.test'],
            ['type' => 'NS', 'name' => 'www.polisportivaalfa.it', 'value' => 'ns2.example.test'],
        ],
        'message' => 'In attesa che il dominio sia delegato ai nostri nameserver.',
    ]);
    $set('non un dominio')->assertStatus(409)->assertExactJson(['ok' => false, 'reason' => 'invalid']);

    // Il dominio di un'altra associazione è «taken».
    $other = (string) $this->postJson('/api/portale/v1/orgs', ['token' => portalToken($this->portal, 'provision', [
        'ref' => 'sub_456', 'org' => ['name' => 'Circolo Beta', 'slug' => 'circolo-beta'], 'owner' => ['email' => 'b@example.com', 'name' => 'Bea Bianchi'],
    ])])->json('org_id');
    $this->putJson("/api/portale/v2/orgs/{$other}/subscription", ['token' => portalToken($this->portal, 'subscription', ['ref' => 'sub_456'] + servicesSubscription($other, 1, [['code' => 'custom_domain', 'qty' => 1]]))])->assertOk();
    ($this->call)("orgs/{$other}/domain/check", 'domain_check', ['ref' => 'sub_456', 'domain' => 'www.polisportivaalfa.it'])->assertOk()->assertExactJson(['ok' => false, 'reason' => 'taken']);

    // Si toglie con null.
    $set(null)->assertOk()->assertJson(['domain' => null, 'state' => 'removed']);
    expect(DB::table('core.org_domains')->count())->toBe(0);
});

it('attiva il dominio quando il DNS è corretto e dice che cosa manca altrimenti', function (): void {
    $this->putJson("/api/portale/v2/orgs/{$this->orgId}/subscription", ['token' => portalToken($this->portal, 'subscription', servicesSubscription($this->orgId, 1, [['code' => 'custom_domain', 'qty' => 1]]))])->assertOk();
    ($this->call)("orgs/{$this->orgId}/domain/set", 'domain_set', ['ref' => 'sub_123', 'domain' => 'www.polisportivaalfa.it'])->assertOk();

    $dns = function (array $nameservers, bool $certificate): void {
        $this->app->instance(DomainDns::class, new readonly class($nameservers, $certificate) implements DomainDns
        {
            /** @param  list<string>  $nameservers */
            public function __construct(private array $nameservers, private bool $certificate) {}

            public function nameservers(string $domain): array
            {
                return $this->nameservers;
            }

            public function hasCertificate(string $domain): bool
            {
                return $this->certificate;
            }
        });
    };
    $row = fn (string $column) => DB::table('core.org_domains')->value($column);

    // Delega non ancora visibile: resta in attesa e dice quali nameserver servono.
    $dns(['ns1.altro.test'], false);
    $this->artisan('saas:portal:verify-domains')->assertSuccessful();
    expect($row('state'))->toBe('pending')->and((string) $row('message'))->toContain('ns1.example.test, ns2.example.test');

    // Dopo le ore di tolleranza la delega mancante è un errore.
    DB::table('core.org_domains')->update(['requested_at' => now()->subHours(100)]);
    $this->artisan('saas:portal:verify-domains')->assertSuccessful();
    expect($row('state'))->toBe('error');

    // Delega corretta ma ancora senza certificato: in attesa del certificato.
    $dns(['ns1.example.test', 'ns2.example.test', 'ns3.altro.test'], false);
    $this->artisan('saas:portal:verify-domains')->assertSuccessful();
    expect($row('state'))->toBe('pending')->and((string) $row('message'))->toContain('certificato');

    // Delega e certificato: attivo.
    $dns(['ns1.example.test', 'ns2.example.test'], true);
    $this->artisan('saas:portal:verify-domains')->assertSuccessful();

    expect($row('state'))->toBe('active');
    ($this->call)("orgs/{$this->orgId}/status", 'org_status', ['ref' => 'sub_123'])->assertOk()
        ->assertJsonPath('domain', ['name' => 'www.polisportivaalfa.it', 'state' => 'active']);
});

it('dà lo stato per l\'area clienti: solo numeri e stati', function (): void {
    $this->mock(Members::class, fn ($mock) => $mock->shouldReceive('activeCount')->andReturn(42));
    $this->putJson("/api/portale/v2/orgs/{$this->orgId}/subscription", ['token' => portalToken($this->portal, 'subscription', servicesSubscription($this->orgId, 3, [['code' => 'member_area', 'qty' => 1]]))])->assertOk();

    ($this->call)("orgs/{$this->orgId}/status", 'org_status', ['ref' => 'sub_123'])->assertOk()->assertExactJson([
        'org_id' => $this->orgId, 'status' => 'active', 'plan' => 'gestionale', 'plan_version' => 1, 'rev' => 3,
        'members' => 42, 'members_limit' => null, 'operators' => ['active' => 1, 'limit' => 1], 'storage_limit_gb' => 1,
        'packages' => ['member_area', 'site'], 'domain' => null,
    ]);

    ($this->call)("orgs/{$this->orgId}/status", 'org_status', ['ref' => 'sub_999'])->assertNotFound();
    ($this->call)('orgs/00000000-0000-7000-8000-000000000000/status', 'org_status', ['ref' => 'sub_123'])->assertNotFound();
    $this->postJson("/api/portale/v2/orgs/{$this->orgId}/status", ['token' => 'non.un.token'])->assertUnauthorized();
});
