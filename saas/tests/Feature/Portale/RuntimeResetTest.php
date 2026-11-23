<?php

declare(strict_types=1);

use App\Modules\Portale\Contracts\WebRuntimeProbe;
use App\Modules\Portale\Contracts\WebRuntimeReset;
use App\Modules\Portale\Jwt\EdDsaJwt;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

require_once __DIR__.'/PortalHelpers.php';

/*
 * H22: dopo il rilascio il SaaS chiede a se stesso, con un token firmato, di azzerare OPcache e la cache dei percorsi.
 * La rotta non ha parametri, ha tipo e destinatario propri e non si apre con il token del rapporto di sola lettura.
 */

/** @param  array<string, mixed>  $overrides */
function resetToken(array $overrides = [], ?string $seed = null): string
{
    $now = time();

    return EdDsaJwt::sign($overrides + [
        'iss' => (string) config('app.url'),
        'aud' => WebRuntimeReset::AUDIENCE,
        'typ' => WebRuntimeReset::TYPE,
        'iat' => $now,
        'exp' => $now + 60,
        'jti' => bin2hex(random_bytes(8)),
    ], $seed ?? (string) base64_decode((string) config('saas.portal.signing_key'), true), 'saas-');
}

beforeEach(function (): void {
    $this->portal = fakePortal();
    config(['saas.signed_api.valid_per_minute' => 50, 'saas.signed_api.invalid_per_minute' => 50]);
    foreach (['runtime', 'runtime-reset'] as $scope) {
        RateLimiter::clear("signed-ok|{$scope}|127.0.0.1");
        RateLimiter::clear("signed-bad|{$scope}|127.0.0.1");
    }
});

it('con la firma del SaaS azzera e riporta solo due interruttori', function (): void {
    $response = $this->postJson('/api/portale/v2/runtime/reset', ['token' => resetToken()])->assertOk();

    expect(array_keys((array) $response->json()))->toEqualCanonicalizing(['opcache_reset', 'realpath_cache_cleared'])
        ->and($response->json('opcache_reset'))->toBeBool()
        ->and($response->json('realpath_cache_cleared'))->toBeTrue();
});

it('rifiuta con 401 una firma falsa e con 400 l\'assenza del token', function (): void {
    $this->postJson('/api/portale/v2/runtime/reset', ['token' => resetToken([], random_bytes(32))])->assertStatus(401);
    $this->postJson('/api/portale/v2/runtime/reset', ['token' => 'a.b.c'])->assertStatus(401);
    $this->postJson('/api/portale/v2/runtime/reset', [])->assertStatus(400);
});

it('rifiuta il token di un altro tipo, di un altro destinatario o scaduto, anche se firmato dal SaaS', function (): void {
    $this->postJson('/api/portale/v2/runtime/reset', ['token' => resetToken(['typ' => 'catalog'])])->assertStatus(401);
    $this->postJson('/api/portale/v2/runtime/reset', ['token' => resetToken(['aud' => 'https://portale.example.test'])])->assertStatus(401);
    $this->postJson('/api/portale/v2/runtime/reset', ['token' => resetToken(['iss' => 'https://altro.example.test'])])->assertStatus(401);
    $this->postJson('/api/portale/v2/runtime/reset', ['token' => resetToken(['iat' => time() - 600, 'exp' => time() - 300])])->assertStatus(401);
});

it('il token del rapporto di sola lettura non azzera, e quello dell\'azzeramento non apre il rapporto', function (): void {
    $probe = resetToken(['aud' => WebRuntimeProbe::AUDIENCE, 'typ' => WebRuntimeProbe::TYPE]);
    $this->postJson('/api/portale/v2/runtime/reset', ['token' => $probe])->assertStatus(401);
    $this->postJson('/api/portale/v2/runtime', ['token' => resetToken()])->assertStatus(401);
    $this->postJson('/api/portale/v2/runtime/reset', ['token' => portalToken($this->portal, WebRuntimeReset::TYPE, [])])->assertStatus(401);
});

it('ha i limiti di frequenza delle altre rotte firmate, con secchi propri', function (): void {
    config(['saas.signed_api.valid_per_minute' => 2, 'saas.signed_api.invalid_per_minute' => 2]);

    for ($i = 0; $i < 2; $i++) {
        $this->postJson('/api/portale/v2/runtime/reset', ['token' => resetToken([], random_bytes(32))])->assertStatus(401);
    }
    $this->postJson('/api/portale/v2/runtime/reset', ['token' => resetToken([], random_bytes(32))])->assertStatus(429);

    // Chi firma bene ha il suo secchio, e la rotta del rapporto ha i suoi.
    $this->postJson('/api/portale/v2/runtime/reset', ['token' => resetToken()])->assertOk();
    $this->postJson('/api/portale/v2/runtime', ['token' => resetToken([], random_bytes(32))])->assertStatus(401);
});

it('il comando chiede l\'azzeramento con un token firmato e fa le richieste di riscaldamento', function (): void {
    Http::fake([
        '*/api/portale/v2/runtime/reset' => Http::response(['opcache_reset' => true, 'realpath_cache_cleared' => true], 200),
        '*' => Http::response('', 200),
    ]);

    $this->artisan('saas:web:refresh')
        ->expectsOutputToContain('OPcache azzerato')
        ->expectsOutputToContain('riscaldamento /up: 200')
        ->expectsOutputToContain('riscaldamento /accesso: 200')
        ->assertSuccessful();

    Http::assertSent(fn (HttpRequest $r): bool => $r->method() === 'POST' && str_ends_with($r->url(), '/api/portale/v2/runtime/reset') && is_string($r['token'] ?? null));
    Http::assertSentCount(1 + count(WebRuntimeReset::WARM_PATHS));
});

it('il comando non fallisce se il PHP web non risponde: avvisa e va avanti', function (): void {
    Http::fake(['*' => Http::response('', 500)]);

    $this->artisan('saas:web:refresh')
        ->expectsOutputToContain('OPcache non azzerato')
        ->assertSuccessful();
});

it('con --no-warm si azzera soltanto', function (): void {
    Http::fake(['*' => Http::response(['opcache_reset' => false, 'realpath_cache_cleared' => true], 200)]);

    $this->artisan('saas:web:refresh', ['--no-warm' => true])->expectsOutputToContain('non azzerato')->assertSuccessful();

    Http::assertSentCount(1);
});
