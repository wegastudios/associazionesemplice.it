<?php

declare(strict_types=1);

use App\Modules\Piattaforma\Catalogo\DbCatalog;
use App\Modules\Piattaforma\Contracts\Catalog;
use App\Modules\Portale\Jwt\EdDsaJwt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

require_once dirname(__DIR__).'/Portale/PortalHelpers.php';

beforeEach(function (): void {
    fakePortal();
    $this->saasKey = EdDsaJwt::b64(sodium_crypto_sign_publickey(sodium_crypto_sign_seed_keypair((string) base64_decode((string) config('saas.portal.signing_key')))));
});

/** Come al rilascio (`saas:catalog:align`): il catalogo si allinea alla configurazione, la lettura non scrive. */
function alignedCatalog(): void
{
    Http::fake(['*' => Http::response([], 200)]);
    app()->forgetInstance(Catalog::class);
    app()->forgetInstance(DbCatalog::class);
    app(DbCatalog::class)->align();
    app()->forgetInstance(Catalog::class);
    app()->forgetInstance(DbCatalog::class);
}

it('serve il catalogo pubblico, senza accesso né cookie, con firma sugli stessi byte', function (): void {
    alignedCatalog();
    $response = $this->get('/api/catalogo/v1')->assertOk()->assertHeader('Content-Type', 'application/json; charset=utf-8');

    $body = $response->getContent();
    expect($response->headers->getCookies())->toBe([])
        ->and($response->headers->get('ETag'))->toBe('"'.hash('sha256', (string) $body).'"')
        ->and($response->headers->get('Cache-Control'))->toContain('public');

    $doc = json_decode((string) $body, true, 32, JSON_THROW_ON_ERROR);
    expect($doc['schema'])->toBe('catalogo/v1')->and($doc['product'])->toBe('saas')->and($doc['rev'])->toBe(3);

    // Il portale verifica la firma con la chiave pubblica del SaaS, e che l'impronta sia quella del corpo.
    $signature = (string) $response->headers->get('X-Catalog-Signature');
    $claims = EdDsaJwt::verify($signature, [EdDsaJwt::kid(EdDsaJwt::unb64($this->saasKey)) => $this->saasKey], 'https://app.example.test', 'https://portale.example.test', 'catalog', time());
    expect($claims['sha256'])->toBe(hash('sha256', (string) $body))->and($claims['rev'])->toBe(3);
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

it('espone i giorni di conservazione dalla configurazione del gestionale, una sola fonte per il portale', function (): void {
    config(['saas.portal.retention_days' => 180, 'saas.portal.trial_purge_days' => 7]);
    alignedCatalog();
    $doc = json_decode((string) $this->get('/api/catalogo/v1')->getContent(), true, 32, JSON_THROW_ON_ERROR);

    expect($doc['terms']['lifecycle'])->toBe(['retention_days' => 180, 'trial_purge_days' => 7])
        ->and($doc['terms']['trial'])->not->toHaveKey('purge_days');

});

it('non cambia mai il contenuto senza alzare la revisione: il portale rifiuta la stessa rev con corpo diverso', function (): void {
    $serve = function (): array {
        app()->forgetInstance(Catalog::class);
        app()->forgetInstance(DbCatalog::class);
        Http::fake(['*' => Http::response([], 200)]);
        app(DbCatalog::class)->align();
        app()->forgetInstance(Catalog::class);
        app()->forgetInstance(DbCatalog::class);
        $published = app(Catalog::class)->published();

        return [$published->rev, hash('sha256', $published->json())];
    };

    config(['saas.portal.retention_days' => 180, 'saas.portal.trial_purge_days' => 7]);
    [$rev1, $hash1] = $serve();
    [$rev2, $hash2] = $serve();
    expect($rev2)->toBe($rev1)->and($hash2)->toBe($hash1);

    config(['saas.portal.retention_days' => 90, 'saas.portal.trial_purge_days' => 14]);
    [$rev3, $hash3] = $serve();
    expect($rev3)->toBeGreaterThan($rev1)->and($hash3)->not->toBe($hash1);

    // Ogni coppia (rev, corpo) già servita non si ripresenta con un corpo diverso.
    $seen = [$rev1 => $hash1, $rev3 => $hash3];
    config(['saas.portal.retention_days' => 180, 'saas.portal.trial_purge_days' => 7]);
    [$rev4, $hash4] = $serve();
    expect($seen)->not->toHaveKey($rev4)->and($rev4)->toBeGreaterThan($rev3);
});

it('avvisa il portale ogni volta che nasce una revisione nuova: revisione nuova, avviso inviato', function (): void {
    Http::fake(['*' => Http::response([], 200)]);
    config(['saas.portal.retention_days' => 180, 'saas.portal.trial_purge_days' => 7]);
    app()->forgetInstance(Catalog::class);
    app()->forgetInstance(DbCatalog::class);

    $rev = app(DbCatalog::class)->align()->rev;

    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/api/saas/v1/catalog-changed') && is_string($request['token'] ?? null));
    Http::assertSentCount(1);

    // Con la stessa configurazione non nasce nessuna revisione e non parte nessun avviso.
    app()->forgetInstance(Catalog::class);
    app()->forgetInstance(DbCatalog::class);
    expect(app(DbCatalog::class)->align()->rev)->toBe($rev);
    Http::assertSentCount(1);

    // Cambiano i numeri: revisione nuova, secondo avviso.
    config(['saas.portal.retention_days' => 90]);
    app()->forgetInstance(Catalog::class);
    app()->forgetInstance(DbCatalog::class);
    expect(app(DbCatalog::class)->align()->rev)->toBe($rev + 1);
    Http::assertSentCount(2);
});

it('la lettura del catalogo non scrive e non chiama il portale: le revisioni nascono al rilascio', function (): void {
    Http::fake(['*' => Http::response([], 200)]);
    config(['saas.portal.retention_days' => 180, 'saas.portal.trial_purge_days' => 7]);
    app()->forgetInstance(Catalog::class);
    app()->forgetInstance(DbCatalog::class);
    $before = DB::table('core.catalog_revisions')->count();

    // Prima del rilascio: si serve ciò che c'è (il contenuto iniziale), senza scrivere niente.
    $read = app(Catalog::class)->published();
    $this->get('/api/catalogo/v1')->assertOk();

    expect(DB::table('core.catalog_revisions')->count())->toBe($before)->and($read->rev)->toBe(2);
    Http::assertNothingSent();

    // Il comando di rilascio scrive la revisione e avvisa il portale; le letture seguenti la trovano.
    $this->artisan('saas:catalog:align')->assertSuccessful();
    Http::assertSentCount(1);
    app()->forgetInstance(Catalog::class);
    app()->forgetInstance(DbCatalog::class);
    expect(app(Catalog::class)->published()->rev)->toBe(3)->and(DB::table('core.catalog_revisions')->count())->toBe($before + 2);
});

it('rimanda l\'avviso della revisione in vigore con il comando', function (): void {
    Http::fake(['*' => Http::response([], 200)]);

    $this->artisan('saas:catalog:notify-portal')->expectsOutputToContain('consegnato')->assertSuccessful();

    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/api/saas/v1/catalog-changed'));
});
