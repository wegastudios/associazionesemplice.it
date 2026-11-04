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

/** @return array<string, mixed> */
function entitlementClaims(string $orgId, string $ref, int $rev, array $overrides = []): array
{
    return $overrides + [
        'sub' => $orgId,
        'ref' => $ref,
        'rev' => $rev,
        'status' => 'active',
        'until' => now()->addMonth()->toDateString(),
        'trial_ends' => null,
        'members' => 150,
        'operators' => 2,
        'storage_gb' => 5,
        'packages' => [
            'member_area' => (object) [],
            'resources' => ['hourly' => ['tier' => 5, 'web' => true]],
            'einvoice' => ['yearly' => 50],
        ],
        'partner' => null,
    ];
}
