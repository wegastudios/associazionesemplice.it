<?php

declare(strict_types=1);

use KeyService\KeyWrap;
use KeyService\MemoryNonceStore;
use KeyService\Protocol;
use KeyService\Service;

const ORG = '01a1a5f6-7d73-70ca-9522-e1bb5deeb707';

beforeEach(function (): void {
    $this->master = random_bytes(32);
    $this->pair = sodium_crypto_sign_keypair();
    $this->log = [];
    $this->service = new Service(
        new KeyWrap($this->master),
        sodium_crypto_sign_publickey($this->pair),
        ['10.0.0.5'],
        new MemoryNonceStore,
        function (string $line): void {
            $this->log[] = $line;
        },
    );
    $this->call = function (array $payload, ?int $ts = null, string $ip = '10.0.0.5', ?string $secret = null): array {
        $body = Protocol::body($payload, $ts ?? time());

        return $this->service->handle('POST', $body, Protocol::sign($body, $secret ?? sodium_crypto_sign_secretkey($this->pair)), $ip, time());
    };
});

it('cifra e decifra una chiave legandola ad associazione e versione', function (): void {
    $wrap = new KeyWrap(random_bytes(32));
    $key = random_bytes(32);
    $wrapped = $wrap->wrap(ORG, 1, $key);

    expect($wrapped)->not->toContain($key)
        ->and($wrap->unwrap(ORG, 1, $wrapped))->toBe($key)
        ->and(fn () => $wrap->unwrap(ORG, 2, $wrapped))->toThrow(RuntimeException::class)
        ->and(fn () => $wrap->unwrap('01a1a5f6-806e-7277-bd06-064d2db42959', 1, $wrapped))->toThrow(RuntimeException::class)
        ->and(fn () => (new KeyWrap(random_bytes(32)))->unwrap(ORG, 1, $wrapped))->toThrow(RuntimeException::class);
});

it('il servizio cifra e decifra per il SaaS firmato', function (): void {
    $key = random_bytes(32);

    $wrapped = ($this->call)(['op' => 'wrap', 'org_id' => ORG, 'version' => 1, 'key' => base64_encode($key)]);
    expect($wrapped['status'])->toBe(200);

    $unwrapped = ($this->call)(['op' => 'unwrap', 'org_id' => ORG, 'version' => 1, 'wrapped' => $wrapped['body']['wrapped']]);
    expect($unwrapped['status'])->toBe(200)
        ->and(base64_decode($unwrapped['body']['key']))->toBe($key);

    // Il registro non contiene mai le chiavi.
    $log = implode("\n", $this->log);
    expect($log)->toContain('ok ip=10.0.0.5 op=wrap', 'ok ip=10.0.0.5 op=unwrap')
        ->not->toContain(base64_encode($key))
        ->not->toContain($wrapped['body']['wrapped']);
});

it('rifiuta indirizzi non ammessi, firme sbagliate, richieste vecchie o ripetute', function (): void {
    $payload = ['op' => 'unwrap', 'org_id' => ORG, 'version' => 1, 'wrapped' => base64_encode('x')];

    expect(($this->call)($payload, ip: '203.0.113.9')['status'])->toBe(403)
        ->and(($this->call)($payload, secret: sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()))['status'])->toBe(401)
        ->and(($this->call)($payload, ts: time() - 120)['status'])->toBe(401)
        ->and($this->service->handle('GET', '{}', null, '10.0.0.5', time())['status'])->toBe(405)
        ->and($this->service->handle('POST', '{}', null, '10.0.0.5', time())['status'])->toBe(401);

    $body = Protocol::body($payload, time());
    $signature = Protocol::sign($body, sodium_crypto_sign_secretkey($this->pair));
    expect($this->service->handle('POST', $body, $signature, '10.0.0.5', time())['status'])->toBe(422)
        ->and($this->service->handle('POST', $body, $signature, '10.0.0.5', time())['status'])->toBe(401);

    // Una richiesta firmata ma alterata non passa.
    $tampered = str_replace('"version":1', '"version":2', $body);
    expect($this->service->handle('POST', $tampered, $signature, '10.0.0.5', time())['status'])->toBe(401);
});

it('rifiuta richieste malformate', function (array $payload): void {
    expect(($this->call)($payload)['status'])->toBe(400);
})->with([
    'operazione sconosciuta' => [['op' => 'export', 'org_id' => ORG, 'version' => 1]],
    'associazione non valida' => [['op' => 'wrap', 'org_id' => "x' OR 1=1", 'version' => 1, 'key' => 'eA==']],
    'versione non valida' => [['op' => 'wrap', 'org_id' => ORG, 'version' => 0, 'key' => 'eA==']],
]);

it('legge la configurazione e rifiuta chiavi mancanti o della lunghezza sbagliata', function (): void {
    $file = (string) tempnam(sys_get_temp_dir(), 'ks');
    $pub = base64_encode(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair()));

    file_put_contents($file, 'master_key = "'.base64_encode(random_bytes(32))."\"\nclient_public_key = \"{$pub}\"\nallowed_ips = \"10.0.0.5, 10.0.0.6\"\n");
    $config = KeyService\Config::load($file, '/srv/ks');
    expect($config->allowedIps)->toBe(['10.0.0.5', '10.0.0.6'])
        ->and($config->nonceDir)->toBe('/srv/ks/storage/nonces');

    file_put_contents($file, 'master_key = "'.base64_encode(random_bytes(16))."\"\nclient_public_key = \"{$pub}\"\nallowed_ips = \"10.0.0.5\"\n");
    expect(fn () => KeyService\Config::load($file, '/srv/ks'))->toThrow(RuntimeException::class);

    unlink($file);
    expect(fn () => KeyService\Config::load($file, '/srv/ks'))->toThrow(RuntimeException::class);
});
