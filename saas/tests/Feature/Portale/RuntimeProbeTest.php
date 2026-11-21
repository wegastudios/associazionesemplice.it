<?php

declare(strict_types=1);

use App\Modules\Portale\Contracts\WebRuntimeProbe;
use App\Modules\Portale\Jwt\EdDsaJwt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

require_once __DIR__.'/PortalHelpers.php';

/*
 * Rotta firmata che riporta come gira il PHP web: firma del SaaS stesso, tipo e destinatario propri, nessun dato.
 */

/** @param  array<string, mixed>  $overrides */
function runtimeToken(array $overrides = [], ?string $seed = null): string
{
    $now = time();

    return EdDsaJwt::sign($overrides + [
        'iss' => 'https://app.example.test',
        'aud' => WebRuntimeProbe::AUDIENCE,
        'typ' => WebRuntimeProbe::TYPE,
        'iat' => $now,
        'exp' => $now + 60,
        'jti' => bin2hex(random_bytes(8)),
    ], $seed ?? (string) base64_decode((string) config('saas.portal.signing_key'), true), 'saas-');
}

beforeEach(function (): void {
    $this->portal = fakePortal();
    config(['saas.signed_api.valid_per_minute' => 50, 'saas.signed_api.invalid_per_minute' => 50]);
    RateLimiter::clear('signed-ok|runtime|127.0.0.1');
    RateLimiter::clear('signed-bad|runtime|127.0.0.1');
});

it('con la firma del SaaS riporta SAPI, APCu e OPcache', function (): void {
    $response = $this->postJson('/api/portale/v2/runtime', ['token' => runtimeToken()])->assertOk();

    $response->assertJsonStructure(['sapi', 'php', 'apcu', 'opcache' => ['enabled', 'memory_used', 'memory_free', 'cached_scripts', 'validate_timestamps']]);
    expect($response->json('sapi'))->toBeString()
        ->and($response->json('apcu'))->toBeBool()
        ->and($response->json('opcache.enabled'))->toBeBool()
        ->and($response->json('opcache.memory_used'))->toBeInt();
});

it('non espone segreti né dati dell\'applicazione', function (): void {
    $body = (string) $this->postJson('/api/portale/v2/runtime', ['token' => runtimeToken()])->assertOk()->getContent();

    expect(array_keys((array) json_decode($body, true)))->toEqualCanonicalizing(['sapi', 'php', 'apcu', 'opcache'])
        ->and($body)->not->toContain((string) config('saas.portal.signing_key'))
        ->and($body)->not->toContain((string) config('saas.portal.public_keys'))
        ->and($body)->not->toContain((string) config('app.key'))
        ->and($body)->not->toContain((string) config('database.connections.pgsql.password'));
});

it('rifiuta con 401 una firma falsa e con 400 l\'assenza del token', function (): void {
    $this->postJson('/api/portale/v2/runtime', ['token' => runtimeToken([], random_bytes(32))])->assertStatus(401);
    $this->postJson('/api/portale/v2/runtime', ['token' => 'a.b.c'])->assertStatus(401);
    $this->postJson('/api/portale/v2/runtime', [])->assertStatus(400);
});

it('rifiuta il token di un altro tipo o di un altro destinatario, anche se firmato dal SaaS', function (): void {
    $this->postJson('/api/portale/v2/runtime', ['token' => runtimeToken(['typ' => 'catalog'])])->assertStatus(401);
    $this->postJson('/api/portale/v2/runtime', ['token' => runtimeToken(['aud' => 'https://portale.example.test'])])->assertStatus(401);
    $this->postJson('/api/portale/v2/runtime', ['token' => runtimeToken(['iss' => 'https://altro.example.test'])])->assertStatus(401);
});

it('non accetta un token del portale, e un token di questa rotta non vale sulle altre', function (): void {
    $this->postJson('/api/portale/v2/runtime', ['token' => portalToken($this->portal, WebRuntimeProbe::TYPE, [])])->assertStatus(401);

    // La chiave del SaaS non è tra quelle del portale: sulle altre rotte il token del runtime è una firma sconosciuta.
    $this->postJson('/api/portale/v2/promo/check', ['token' => runtimeToken()])->assertStatus(401);
});

it('ha i limiti di frequenza delle altre rotte firmate, con secchi propri', function (): void {
    config(['saas.signed_api.valid_per_minute' => 2, 'saas.signed_api.invalid_per_minute' => 2]);

    for ($i = 0; $i < 2; $i++) {
        $this->postJson('/api/portale/v2/runtime', ['token' => runtimeToken([], random_bytes(32))])->assertStatus(401);
    }
    $this->postJson('/api/portale/v2/runtime', ['token' => runtimeToken([], random_bytes(32))])->assertStatus(429);

    // Chi firma bene ha il suo secchio, intatto.
    $this->postJson('/api/portale/v2/runtime', ['token' => runtimeToken()])->assertOk();
});

it('la salute usa la rotta e, se non risponde, avvisa senza fallire', function (): void {
    Http::fake(['*' => Http::response('', 500)]);
    $this->artisan('saas:health', ['--skip-migrations' => true])
        ->expectsOutputToContain('PHP web non raggiungibile')
        ->assertSuccessful();

    Http::fake(['*' => Http::response(['sapi' => 'litespeed', 'php' => '8.4.0', 'apcu' => false, 'opcache' => ['enabled' => false]], 200)]);
    $this->artisan('saas:health', ['--skip-migrations' => true])
        ->expectsOutputToContain('APCu non attivo sul PHP web')
        ->expectsOutputToContain('OPcache non attivo sul PHP web')
        ->assertSuccessful();
});
