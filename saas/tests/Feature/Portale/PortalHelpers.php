<?php

declare(strict_types=1);

use App\Modules\Portale\Jwt\EdDsaJwt;
use App\Modules\Portale\PortalConfig;

/*
 * Un portale finto: una coppia di chiavi generata per il test, la cui parte pubblica viene fissata nel SaaS.
 */

function fakePortal(): string
{
    $seed = random_bytes(32);
    $x = EdDsaJwt::b64(sodium_crypto_sign_publickey(sodium_crypto_sign_seed_keypair($seed)));
    config([
        'saas.portal.url' => 'https://portale.example.test',
        'saas.portal.public_keys' => EdDsaJwt::kid(EdDsaJwt::unb64($x)).'='.$x,
        'saas.portal.signing_key' => base64_encode(random_bytes(32)),
        'app.url' => 'https://app.example.test',
    ]);

    return $seed;
}

/** @param  array<string, mixed>  $claims */
function portalToken(string $seed, string $type, array $claims, int $lifetime = 300): string
{
    $now = time();

    return EdDsaJwt::sign([
        'iss' => 'https://portale.example.test',
        'aud' => PortalConfig::AUDIENCE,
        'typ' => $type,
        'iat' => $now,
        'exp' => $now + $lifetime,
        'jti' => bin2hex(random_bytes(8)),
    ] + $claims, $seed);
}

/**
 * Un abbonamento v2 come lo manda il portale. Piano «gestionale» con tre operatori (1 + 2 aggiuntivi), area soci e
 * prenotazione risorse a ore; chi serve altro lo cambia con $overrides.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function subscriptionClaims(string $orgId, int $rev, array $overrides = []): array
{
    return $overrides + [
        'sub' => $orgId,
        'ref' => 'sub_123',
        'rev' => $rev,
        'status' => 'active',
        'until' => now()->addMonth()->toDateString(),
        'trial_ends' => null,
        'plan' => 'gestionale',
        'plan_version' => 1,
        'members' => 150,
        'addons' => [['code' => 'operators_extra', 'qty' => 2], ['code' => 'member_area', 'qty' => 1], ['code' => 'resources_hourly_web', 'qty' => 1]],
        'promo' => 'PRIMAVERA',
        'price_cents' => 29000,
        'partner' => null,
    ];
}

/**
 * Manda l'abbonamento sulla rotta v2, firmato come dal portale, e restituisce la risposta.
 *
 * @param  array<string, mixed>  $claims
 */
function putSubscription(string $seed, array $claims, string $orgId): Illuminate\Testing\TestResponse
{
    return test()->putJson("/api/portale/v2/orgs/{$orgId}/subscription", ['token' => portalToken($seed, 'subscription', $claims)]);
}

/**
 * Porta un'associazione in uno stato preciso: manda l'abbonamento e pretende che sia accettato.
 *
 * @param  array<string, mixed>  $claims
 */
function applySubscription(string $seed, array $claims, string $orgId): void
{
    putSubscription($seed, $claims, $orgId)->assertOk();
}
