<?php

declare(strict_types=1);

use App\Modules\Portale\Jwt\EdDsaJwt;
use Illuminate\Support\Facades\RateLimiter;

require_once __DIR__.'/PortalHelpers.php';

/*
 * Il limite delle API firmate scatta dopo il controllo della firma: due secchi per indirizzo, uno per le firme valide
 * e uno, più basso, per quelle assenti o non valide. Dati di prova inventati.
 */

beforeEach(function (): void {
    $this->portal = fakePortal();
    config(['saas.signed_api.valid_per_minute' => 6, 'saas.signed_api.invalid_per_minute' => 3]);
    foreach (['portal', 'staff'] as $scope) {
        RateLimiter::clear('signed-ok|'.$scope.'|127.0.0.1');
        RateLimiter::clear('signed-bad|'.$scope.'|127.0.0.1');
    }
    $this->valid = fn () => $this->postJson('/api/portale/v2/promo/check', ['token' => portalToken($this->portal, 'promo_check', ['code' => 'X'])]);
    $this->forged = fn () => $this->postJson('/api/portale/v2/promo/check', ['token' => portalToken(random_bytes(32), 'promo_check', ['code' => 'X'])]);
});

it('non tocca le richieste con firma valida quando dallo stesso indirizzo arrivano firme false', function (): void {
    for ($i = 0; $i < 3; $i++) {
        expect(($this->forged)()->status())->toBe(401);
    }
    ($this->forged)()->assertStatus(429);
    ($this->forged)()->assertStatus(429);

    for ($i = 0; $i < 6; $i++) {
        expect(($this->valid)()->status())->not->toBe(429);
    }
});

it('risponde 429 oltre la soglia dei fallimenti, anche senza token o con token malformato', function (): void {
    for ($i = 0; $i < 3; $i++) {
        $this->postJson('/api/portale/v1/orgs', [])->assertStatus(400);
    }

    $this->postJson('/api/portale/v1/orgs', ['token' => 'a.b.c'])->assertStatus(429)->assertHeader('Retry-After');
    ($this->forged)()->assertStatus(429);
});

it('ha per le firme valide un limite proprio', function (): void {
    for ($i = 0; $i < 6; $i++) {
        expect(($this->valid)()->status())->not->toBe(429);
    }

    ($this->valid)()->assertStatus(429);
    // Il secchio dei fallimenti è intatto: una firma falsa riceve ancora la risposta normale.
    ($this->forged)()->assertStatus(401);
});

it('tiene separati i secchi del portale e dello staff', function (): void {
    $seed = random_bytes(32);
    $x = EdDsaJwt::b64(sodium_crypto_sign_publickey(sodium_crypto_sign_seed_keypair($seed)));
    config([
        'saas.staff_issuer.enabled' => true,
        'saas.staff_issuer.area_keys' => 'portale-wp='.$x.'@wordpress',
        'saas.public_url' => 'https://associazionesemplice.it',
    ]);

    for ($i = 0; $i < 3; $i++) {
        $this->postJson('/saas/api/staff/v1/session-check', ['token' => 'non-una-firma'])->assertStatus(401);
    }
    $this->postJson('/saas/api/staff/v1/session-check', ['token' => 'non-una-firma'])->assertStatus(429);

    // Firma valida dello staff e del portale dallo stesso indirizzo: non toccate.
    $now = time();
    $head = EdDsaJwt::b64((string) json_encode(['typ' => 'JWT', 'alg' => 'EdDSA', 'kid' => 'portale-wp']));
    $body = EdDsaJwt::b64((string) json_encode(['typ' => 'staff_session_check', 'iat' => $now, 'exp' => $now + 60]));
    $sig = EdDsaJwt::b64(sodium_crypto_sign_detached($head.'.'.$body, sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair($seed))));
    expect($this->postJson('/saas/api/staff/v1/session-check', ['token' => $head.'.'.$body.'.'.$sig])->status())->not->toBe(429)
        ->and(($this->valid)()->status())->not->toBe(429);
});
