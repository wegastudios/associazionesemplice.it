<?php

declare(strict_types=1);

use App\Modules\Portale\Jwt\EdDsaJwt;
use App\Modules\Portale\Jwt\InvalidToken;

beforeEach(function (): void {
    $this->seed = random_bytes(32);
    $x = EdDsaJwt::b64(sodium_crypto_sign_publickey(sodium_crypto_sign_seed_keypair($this->seed)));
    $this->keys = [EdDsaJwt::kid(EdDsaJwt::unb64($x)) => $x];
    $this->claims = fn (array $extra = []): array => $extra + ['iss' => 'https://p', 'aud' => 'saas', 'typ' => 'entitlements', 'iat' => time(), 'exp' => time() + 300];
    $this->verify = fn (string $token, string $type = 'entitlements'): array => EdDsaJwt::verify($token, $this->keys, 'https://p', 'saas', $type, time());
});

it('firma e verifica, con lo stesso kid del portale', function (): void {
    $token = EdDsaJwt::sign(($this->claims)(['rev' => 3]), $this->seed);
    $header = json_decode(EdDsaJwt::unb64(explode('.', $token)[0]), true);

    expect(($this->verify)($token)['rev'])->toBe(3)
        ->and($header)->toMatchArray(['typ' => 'JWT', 'alg' => 'EdDSA'])
        ->and($header['kid'])->toStartWith('portale-');
});

it('rifiuta token alterati, di altre chiavi, di altro tipo o destinatario', function (): void {
    $good = EdDsaJwt::sign(($this->claims)(), $this->seed);
    [$h, $b, $s] = explode('.', $good);
    $forgedBody = EdDsaJwt::b64((string) json_encode(($this->claims)(['rev' => 999])));
    $none = EdDsaJwt::b64('{"alg":"none","kid":"x"}').'.'.$b.'.';

    $cases = [
        'corpo alterato' => "{$h}.{$forgedBody}.{$s}",
        'firma di un\'altra chiave' => EdDsaJwt::sign(($this->claims)(), random_bytes(32)),
        'alg none' => $none,
        'malformato' => 'abc',
        'altro destinatario' => EdDsaJwt::sign(($this->claims)(['aud' => 'altro']), $this->seed),
        'altro emittente' => EdDsaJwt::sign(($this->claims)(['iss' => 'https://x']), $this->seed),
        'scaduto' => EdDsaJwt::sign(($this->claims)(['iat' => time() - 1000, 'exp' => time() - 700]), $this->seed),
        'vita troppo lunga' => EdDsaJwt::sign(($this->claims)(['exp' => time() + 86400]), $this->seed),
        'dal futuro' => EdDsaJwt::sign(($this->claims)(['iat' => time() + 300, 'exp' => time() + 600]), $this->seed),
    ];

    foreach ($cases as $name => $token) {
        expect(fn () => ($this->verify)($token))->toThrow(InvalidToken::class, null, $name);
    }
    expect(fn () => ($this->verify)($good, 'provision'))->toThrow(InvalidToken::class);
});
