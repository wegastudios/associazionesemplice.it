<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use KeyService\KeyWrap;
use KeyService\MemoryNonceStore;
use KeyService\Protocol;
use KeyService\Service;

/**
 * Servizio chiavi di prova: il servizio vero (keyservice/src) con una chiave principale casuale, raggiunto dal client
 * HTTP finto di Laravel. install() lo collega e mette indirizzo e chiave di firma nella configurazione del SaaS.
 */
final class FakeKeyService
{
    public const URL = 'https://chiavi.example.test/';

    public int $calls = 0;

    public bool $down = false;

    /** Firma di un'altra coppia: il servizio deve rifiutarla. */
    public bool $wrongSignature = false;

    public readonly string $masterKey;

    public readonly string $signingKey;

    private Service $service;

    public function __construct(?string $masterKey = null)
    {
        $this->masterKey = $masterKey ?? random_bytes(32);
        $pair = sodium_crypto_sign_keypair();
        $this->signingKey = sodium_crypto_sign_secretkey($pair);
        $this->service = new Service(new KeyWrap($this->masterKey), sodium_crypto_sign_publickey($pair), ['127.0.0.1'], new MemoryNonceStore, static function (string $line): void {});
    }

    public function install(): self
    {
        Http::fake([self::URL.'*' => function (Request $request): PromiseInterface {
            $this->calls++;
            if ($this->down) {
                return Http::response(['error' => 'non disponibile'], 503);
            }
            $header = $request->header(Protocol::SIGNATURE_HEADER)[0] ?? null;
            $signature = is_string($header) ? $header : null;
            if ($this->wrongSignature) {
                $signature = Protocol::sign($request->body(), sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()));
            }
            $result = $this->service->handle('POST', $request->body(), $signature, '127.0.0.1', Date::now()->getTimestamp());

            return Http::response($result['body'], $result['status']);
        }]);

        config([
            'saas.crypto.service_url' => self::URL,
            'saas.crypto.signing_key' => base64_encode($this->signingKey),
        ]);

        return $this;
    }
}
