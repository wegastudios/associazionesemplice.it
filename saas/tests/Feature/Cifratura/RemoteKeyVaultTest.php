<?php

declare(strict_types=1);

use App\Modules\Cifratura\Contracts\KeyVault;
use App\Modules\Cifratura\Keys\KeyCache;
use App\Modules\Cifratura\Keys\RemoteKeyVault;
use App\Modules\Soci\Models\Person;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use KeyService\KeyWrap;
use KeyService\MemoryNonceStore;
use KeyService\Protocol;
use KeyService\Service;

/*
 * SaaS e servizio chiavi insieme: il servizio vero, raggiunto tramite il client HTTP finto di Laravel.
 */

beforeEach(function (): void {
    $pair = sodium_crypto_sign_keypair();
    $this->calls = 0;
    $this->down = false;
    $service = new Service(new KeyWrap(random_bytes(32)), sodium_crypto_sign_publickey($pair), ['127.0.0.1'], new MemoryNonceStore, static function (): void {});

    Http::fake(['https://chiavi.example.test/*' => function (Request $request) use ($service) {
        $this->calls++;
        if ($this->down) {
            return Http::response(['error' => 'non disponibile'], 503);
        }
        $result = $service->handle('POST', $request->body(), $request->header(Protocol::SIGNATURE_HEADER)[0] ?? null, '127.0.0.1', time());

        return Http::response($result['body'], $result['status']);
    }]);

    $this->app->instance(KeyVault::class, new RemoteKeyVault(app(Factory::class), 'https://chiavi.example.test/', sodium_crypto_sign_secretkey($pair), 3));
    $this->app->instance(KeyCache::class, new KeyCache(300));
});

it('crea le chiavi e cifra i dati passando dal servizio chiavi', function (): void {
    $org = $this->createOrg('Polisportiva Alfa');
    // wrap della nuova chiave, poi unwrap per il sigillo del registro (preparazione della contabilità)
    expect($this->calls)->toBe(2);

    $person = $this->orgContext()->run($org->id, fn () => Person::create(['first_name' => 'Mario', 'last_name' => 'Rossi', 'tax_code' => 'RSSMRA80A01H501U']));
    expect($this->calls)->toBe(2); // la chiave resta in memoria

    $this->orgContext()->run($org->id, function () use ($person): void {
        expect(Person::findOrFail($person->id)->tax_code)->toBe('RSSMRA80A01H501U');
    });
    expect($this->calls)->toBe(2);
});

it('se il servizio non risponde i dati restano chiusi, senza dettagli nell\'errore', function (): void {
    $this->down = true;

    expect(fn () => $this->createOrg('Polisportiva Alfa'))->toThrow(RuntimeException::class, 'Servizio chiavi non disponibile (HTTP 503).');
});

it('rifiuta un servizio chiavi senza HTTPS', function (): void {
    expect(fn () => new RemoteKeyVault(app(Factory::class), 'http://chiavi.example.test/', str_repeat('x', 64), 3))
        ->toThrow(RuntimeException::class);
});
