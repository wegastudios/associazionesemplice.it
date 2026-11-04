<?php

declare(strict_types=1);

use App\Modules\Audit\Log\ChainVerifier;
use App\Modules\Core\Access\Memberships;
use App\Modules\Core\Access\OrgMembers;
use App\Modules\Core\Auth\InvitationNotification;
use App\Modules\Core\Contracts\OperatorLimitReached;
use App\Modules\Core\Contracts\OrgRole;
use App\Modules\Core\Models\Org;
use App\Modules\Core\Models\User;
use App\Modules\Portale\Contracts\Entitlements;
use App\Modules\Portale\Contracts\Package;
use App\Modules\Portale\Jwt\EdDsaJwt;
use App\Modules\Soci\Models\Person;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;

require_once __DIR__.'/PortalHelpers.php';

beforeEach(function (): void {
    Notification::fake();
    $this->portal = fakePortal();
    $this->provision = fn (array $claims = []) => $this->postJson('/api/portale/v1/orgs', ['token' => portalToken($this->portal, 'provision', $claims + [
        'ref' => 'sub_123',
        'org' => ['name' => 'Polisportiva Alfa', 'slug' => 'polisportiva-alfa'],
        'owner' => ['email' => 'Titolare@Example.com', 'name' => 'Anna Rossi'],
    ])]);
});

it('crea l\'associazione dal portale e invita il titolare, una volta sola', function (): void {
    $first = ($this->provision)()->assertCreated();
    $orgId = $first->json('org_id');

    ($this->provision)()->assertOk()->assertJson(['org_id' => $orgId, 'created' => false]);

    $owner = User::query()->where('email', 'titolare@example.com')->sole();
    expect(Org::query()->where('portal_ref', 'sub_123')->sole()->id)->toBe($orgId)
        ->and(app(Memberships::class)->find($owner->id, $orgId)?->role)->toBe(OrgRole::Owner)
        ->and(DB::table('core.org_keys')->where('org_id', $orgId)->exists())->toBeTrue();
    Notification::assertSentToTimes($owner, InvitationNotification::class, 1);
});

it('rifiuta token non firmati dal portale, sottodomini occupati e dati non validi', function (): void {
    $this->postJson('/api/portale/v1/orgs', ['token' => EdDsaJwt::sign(['typ' => 'provision'], random_bytes(32))])
        ->assertUnauthorized()->assertExactJson(['error' => 'invalid_token']);
    $this->postJson('/api/portale/v1/orgs', [])->assertStatus(400);

    ($this->provision)()->assertCreated();
    ($this->provision)(['ref' => 'sub_456'])->assertStatus(409)->assertJson(['error' => 'slug_taken']);
    ($this->provision)(['ref' => 'sub_789', 'org' => ['name' => 'X', 'slug' => 'NO SPAZI']])->assertStatus(422);
    ($this->provision)(['ref' => 'sub_789', 'owner' => ['email' => 'non-email', 'name' => 'X']])->assertStatus(422);

    // Nessuna sessione e nessun cookie sulle API del portale.
    expect(($this->provision)()->headers->getCookies())->toBe([]);
});

it('applica i diritti in ordine di revisione e li annota nel registro', function (): void {
    $orgId = ($this->provision)()->json('org_id');
    $put = fn (array $claims) => $this->putJson("/api/portale/v1/orgs/{$orgId}/entitlements", ['token' => portalToken($this->portal, 'entitlements', $claims)]);

    $put(entitlementClaims($orgId, 'sub_123', 2))->assertOk()->assertJson(['rev' => 2]);
    $put(entitlementClaims($orgId, 'sub_123', 2, ['operators' => 99]))->assertStatus(409)->assertJson(['current_rev' => 2]);
    $put(entitlementClaims($orgId, 'sub_123', 1))->assertStatus(409);

    $e = app(Entitlements::class)->for($orgId);
    expect($e->rev)->toBe(2)
        ->and($e->operators)->toBe(2)
        ->and($e->has(Package::MemberArea))->toBeTrue()
        ->and($e->has(Package::Site))->toBeFalse()
        ->and($e->limit(Package::Resources, 'hourly.tier'))->toBe(5)
        ->and($e->limit(Package::EInvoice, 'yearly'))->toBe(50);

    $updates = $this->orgContext()->run($orgId, fn () => DB::table('audit.log')->where('action', 'subscription.updated')->count());
    expect($updates)->toBe(1)->and(app(ChainVerifier::class)->verify($orgId)['ok'])->toBeTrue();
});

it('rifiuta diritti di un\'altra associazione, di un altro abbonamento o con campi sconosciuti', function (): void {
    $orgId = ($this->provision)()->json('org_id');
    $other = ($this->provision)(['ref' => 'sub_999', 'org' => ['name' => 'Beta', 'slug' => 'circolo-beta']])->json('org_id');
    $put = fn (string $url, array $claims) => $this->putJson($url, ['token' => portalToken($this->portal, 'entitlements', $claims)]);

    $put("/api/portale/v1/orgs/{$orgId}/entitlements", entitlementClaims($other, 'sub_999', 1))->assertNotFound();
    $put("/api/portale/v1/orgs/{$orgId}/entitlements", entitlementClaims($orgId, 'sub_999', 1))->assertNotFound();
    $put("/api/portale/v1/orgs/{$orgId}/entitlements", entitlementClaims($orgId, 'sub_123', 1, ['packages' => ['jackpot' => (object) []]]))->assertStatus(422);
    $put("/api/portale/v1/orgs/{$orgId}/entitlements", entitlementClaims($orgId, 'sub_123', 1, ['packages' => ['einvoice' => ['yearly' => 50, 'unlimited' => true]]]))->assertStatus(422);
    $put("/api/portale/v1/orgs/{$orgId}/entitlements", entitlementClaims($orgId, 'sub_123', 1, ['status' => 'gratis']))->assertStatus(422);

    expect(app(Entitlements::class)->for($orgId)->rev)->toBe(0);
});

it('sospende e riapre l\'associazione secondo lo stato dell\'abbonamento', function (): void {
    $orgId = ($this->provision)()->json('org_id');
    $owner = User::query()->where('email', 'titolare@example.com')->sole();
    $owner->forceFill([
        'two_factor_secret' => Laravel\Fortify\Fortify::currentEncrypter()->encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_confirmed_at' => now(),
    ])->save();
    $put = fn (int $rev, string $status) => $this->putJson("/api/portale/v1/orgs/{$orgId}/entitlements", ['token' => portalToken($this->portal, 'entitlements', entitlementClaims($orgId, 'sub_123', $rev, ['status' => $status]))]);

    $put(1, 'suspended')->assertOk();
    $this->actingAs($owner)->get('/gestionale')->assertRedirect('/associazioni');

    $put(2, 'active')->assertOk();
    $this->get('/gestionale')->assertOk();

    $put(3, 'cancelled')->assertOk();
    expect(Org::query()->findOrFail($orgId)->status)->toBe('closed');
});

it('sospende da sé le associazioni scadute oltre la tolleranza', function (): void {
    $orgId = ($this->provision)()->json('org_id');
    $this->putJson("/api/portale/v1/orgs/{$orgId}/entitlements", ['token' => portalToken($this->portal, 'entitlements', entitlementClaims($orgId, 'sub_123', 1, ['until' => now()->subDays(10)->toDateString()]))])->assertOk();

    $this->artisan('saas:portal:suspend-expired')->assertSuccessful();
    expect(Org::query()->findOrFail($orgId)->status)->toBe('active');

    $this->travel(6)->days();
    $this->artisan('saas:portal:suspend-expired')->assertSuccessful();
    expect(Org::query()->findOrFail($orgId)->status)->toBe('suspended');
});

it('blocca le funzioni dei pacchetti non attivi', function (): void {
    $user = $this->createUser('anna@example.com', twoFactor: true);
    $org = $this->createOrg('Polisportiva Alfa');
    $this->addOperator($org, $user);
    DB::table('core.orgs')->where('id', $org->id)->update(['portal_ref' => 'sub_1']);
    Route::middleware(['web', 'auth', 'two-factor', 'operator', 'package:member_area'])->get('/_test/area-soci', fn () => 'ok');
    Route::middleware(['web', 'auth', 'two-factor', 'operator', 'package:site'])->get('/_test/sito', fn () => 'ok');

    $this->actingAs($user)->get('/_test/area-soci')->assertForbidden();

    $this->putJson("/api/portale/v1/orgs/{$org->id}/entitlements", ['token' => portalToken($this->portal, 'entitlements', entitlementClaims($org->id, 'sub_1', 1))])->assertOk();
    $this->get('/_test/area-soci')->assertOk();
    $this->get('/_test/sito')->assertForbidden();
});

it('limita gli inviti al numero di operatori dell\'abbonamento', function (): void {
    $orgId = ($this->provision)()->json('org_id');
    $org = Org::query()->findOrFail($orgId);
    $owner = User::query()->where('email', 'titolare@example.com')->sole();
    $by = app(Memberships::class)->find($owner->id, $orgId);
    $members = app(OrgMembers::class);

    // Senza diritti ricevuti: solo il titolare.
    expect(fn () => $members->invite($org, 'op@example.com', 'Op', OrgRole::Operator, $by))->toThrow(OperatorLimitReached::class);

    $this->putJson("/api/portale/v1/orgs/{$orgId}/entitlements", ['token' => portalToken($this->portal, 'entitlements', entitlementClaims($orgId, 'sub_123', 1))])->assertOk();
    $members->invite($org, 'op@example.com', 'Op', OrgRole::Operator, $by);
    expect(fn () => $members->invite($org, 'op2@example.com', 'Op2', OrgRole::Operator, $by))->toThrow(OperatorLimitReached::class);

    // Cambiare il ruolo di chi è già operatore non conta come nuovo operatore.
    $members->invite($org, 'op@example.com', 'Op', OrgRole::Accountant, $by);
});

it('manda al portale solo i numeri di ogni associazione, firmati dal SaaS', function (): void {
    $alfa = ($this->provision)()->json('org_id');
    $beta = ($this->provision)(['ref' => 'sub_999', 'org' => ['name' => 'Beta', 'slug' => 'circolo-beta']])->json('org_id');
    $this->orgContext()->run($alfa, function (): void {
        Person::create(['first_name' => 'Mario', 'last_name' => 'Rossi', 'tax_code' => 'RSSMRA80A01H501U']);
        Person::create(['first_name' => 'Maria', 'last_name' => 'Rossi']);
    });
    $this->orgContext()->run($beta, fn () => Person::create(['first_name' => 'Bruno', 'last_name' => 'Bianchi']));

    $sent = null;
    Http::fake(['https://portale.example.test/api/saas/v1/usage' => function (HttpRequest $request) use (&$sent) {
        $sent = $request['token'];

        return Http::response(['ok' => true]);
    }]);

    $this->artisan('saas:portal:usage')->assertSuccessful();

    $seed = (string) base64_decode((string) config('saas.portal.signing_key'));
    $x = EdDsaJwt::b64(sodium_crypto_sign_publickey(sodium_crypto_sign_seed_keypair($seed)));
    $claims = EdDsaJwt::verify((string) $sent, [EdDsaJwt::kid(EdDsaJwt::unb64($x)) => $x], 'https://app.example.test', 'https://portale.example.test', 'usage', time());

    $byOrg = array_column($claims['orgs'], null, 'org_id');
    expect($byOrg[$alfa])->toMatchArray(['ref' => 'sub_123', 'members' => 2, 'operators' => 1])
        ->and($byOrg[$beta]['members'])->toBe(1)
        ->and((string) $sent)->not->toContain('Rossi');
    expect(json_encode($claims))->not->toContain('Rossi')->not->toContain('RSSMRA');
});

it('porta il titolare al portale con un token di 60 secondi, e solo lui', function (): void {
    $orgId = ($this->provision)()->json('org_id');
    $owner = User::query()->where('email', 'titolare@example.com')->sole();
    $owner->forceFill(['two_factor_secret' => Laravel\Fortify\Fortify::currentEncrypter()->encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()])->save();
    $accountant = $this->createUser('commercialista@example.com', twoFactor: true);
    $this->addOperator(Org::query()->findOrFail($orgId), $accountant, OrgRole::Accountant);

    $location = (string) $this->actingAs($owner)->get('/gestionale/abbonamento')->assertRedirect()->headers->get('Location');
    expect($location)->toStartWith('https://portale.example.test/saas/accedi?t=');

    $seed = (string) base64_decode((string) config('saas.portal.signing_key'));
    $x = EdDsaJwt::b64(sodium_crypto_sign_publickey(sodium_crypto_sign_seed_keypair($seed)));
    $claims = EdDsaJwt::verify(substr($location, strlen('https://portale.example.test/saas/accedi?t=')), [EdDsaJwt::kid(EdDsaJwt::unb64($x)) => $x], 'https://app.example.test', 'https://portale.example.test', 'sso', time());
    expect($claims)->toMatchArray(['sub' => 'titolare@example.com', 'org_id' => $orgId, 'ref' => 'sub_123', 'role' => 'owner'])
        ->and($claims['exp'] - $claims['iat'])->toBe(60);

    $this->actingAs($accountant)->get('/gestionale/abbonamento')->assertForbidden();
});
