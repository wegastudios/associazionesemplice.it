<?php

declare(strict_types=1);

use App\Modules\Piattaforma\Catalogo\DbCatalog;
use App\Modules\Piattaforma\Contracts\Catalog;
use App\Modules\Portale\Jwt\EdDsaJwt;

require_once dirname(__DIR__).'/Portale/PortalHelpers.php';

beforeEach(function (): void {
    fakePortal();
    $this->saasKey = EdDsaJwt::b64(sodium_crypto_sign_publickey(sodium_crypto_sign_seed_keypair((string) base64_decode((string) config('saas.portal.signing_key')))));
});

it('serve il catalogo pubblico, senza accesso né cookie, con firma sugli stessi byte', function (): void {
    $response = $this->get('/api/catalogo/v1')->assertOk()->assertHeader('Content-Type', 'application/json; charset=utf-8');

    $body = $response->getContent();
    expect($response->headers->getCookies())->toBe([])
        ->and($response->headers->get('ETag'))->toBe('"'.hash('sha256', (string) $body).'"')
        ->and($response->headers->get('Cache-Control'))->toContain('public');

    $doc = json_decode((string) $body, true, 32, JSON_THROW_ON_ERROR);
    expect($doc['schema'])->toBe('catalogo/v1')->and($doc['product'])->toBe('saas')->and($doc['rev'])->toBe(1);

    // Il portale verifica la firma con la chiave pubblica del SaaS, e che l'impronta sia quella del corpo.
    $signature = (string) $response->headers->get('X-Catalog-Signature');
    $claims = EdDsaJwt::verify($signature, [EdDsaJwt::kid(EdDsaJwt::unb64($this->saasKey)) => $this->saasKey], 'https://app.example.test', 'https://portale.example.test', 'catalog', time());
    expect($claims['sha256'])->toBe(hash('sha256', (string) $body))->and($claims['rev'])->toBe(1);
});

it('risponde 304 se il portale ha già la stessa revisione', function (): void {
    $etag = (string) $this->get('/api/catalogo/v1')->headers->get('ETag');

    $this->get('/api/catalogo/v1', ['If-None-Match' => $etag])->assertStatus(304)->assertHeader('ETag', $etag);
    $this->get('/api/catalogo/v1', ['If-None-Match' => '"altro"'])->assertOk();
});

it('non contiene dati personali né riferimenti ad associazioni', function (): void {
    $json = (string) $this->get('/api/catalogo/v1')->getContent();

    expect($json)->not->toContain('@')->and($json)->not->toContain('org_id');
});

it('rifiuta di pubblicare un catalogo non valido', function (): void {
    config(['catalogo.plans' => []]);
    app()->forgetInstance(Catalog::class);
    app()->forgetInstance(DbCatalog::class);

    expect(fn () => app(Catalog::class)->published())->toThrow(InvalidArgumentException::class);
});
