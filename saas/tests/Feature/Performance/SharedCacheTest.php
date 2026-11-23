<?php

declare(strict_types=1);

use App\Modules\Core\Contracts\OrgDirectory;
use App\Modules\Core\Contracts\SharedCache;
use App\Modules\Piattaforma\Catalogo\DbCatalog;
use App\Modules\Piattaforma\Contracts\Catalog;
use App\Modules\Portale\Contracts\Entitlements;
use App\Modules\Portale\Contracts\LifecyclePolicy;
use App\Modules\Portale\Entitlements\EntitlementReader;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

// Cache tra le richieste (H20): catalogo e diritti per associazione. Dati di prova inventati.

beforeEach(function (): void {
    config(['saas.shared_cache.catalog' => 300, 'saas.shared_cache.entitlements' => 300, 'saas.shared_cache.status' => 60]);
});

it('non supera le durate massime: 60 secondi per stato e sola lettura, 5 minuti per catalogo e diritti', function (): void {
    config(['saas.shared_cache.catalog' => 3600, 'saas.shared_cache.entitlements' => 3600, 'saas.shared_cache.status' => 3600]);
    expect(SharedCache::seconds('status'))->toBe(60)
        ->and(SharedCache::seconds('entitlements'))->toBe(300)
        ->and(SharedCache::seconds('catalog'))->toBe(300);

    config(['saas.shared_cache.status' => 30]);
    expect(SharedCache::seconds('status'))->toBe(30);
});

it('uno stato cambiato senza avviso vale al più dopo 60 secondi', function (): void {
    $alfa = $this->createOrg('Polisportiva Alfa');
    storeEntitlements($alfa->id, 'trial', 1);
    expect(app(LifecyclePolicy::class)->readOnly($alfa->id))->toBeFalse();

    // Scrittura diretta, senza setStatus (vietata nel codice: la prova di architettura la blocca): resta il limite di tempo.
    DB::table('core.orgs')->where('id', $alfa->id)->update(['status' => 'suspended']);
    nextRequest();
    expect(app(LifecyclePolicy::class)->readOnly($alfa->id))->toBeFalse();

    $this->travel(61)->seconds();
    nextRequest();
    expect(app(LifecyclePolicy::class)->readOnly($alfa->id))->toBeTrue();
});

/** Una richiesta nuova: i servizi «scoped» ripartono da zero, resta solo la cache generale. */
function nextRequest(): void
{
    app()->forgetScopedInstances();
}

/** Quante istruzioni toccano la tabella indicata. */
function readsOf(string $table, Closure $work): int
{
    $n = 0;
    DB::listen(static function ($query) use (&$n, $table): void {
        if (str_contains($query->sql, $table)) {
            $n++;
        }
    });
    $work();

    return $n;
}

/** @param  array<string, mixed>  $extra */
function storeEntitlements(string $orgId, string $status, int $storageGb, int $rev = 1, array $extra = []): void
{
    $until = now()->addMonth()->toDateString();
    $claims = $extra + ['sub' => $orgId, 'ref' => 'sub_prova', 'rev' => $rev, 'status' => $status, 'until' => $until, 'trial_ends' => null,
        'members' => 150, 'operators' => 3, 'storage_gb' => $storageGb, 'packages' => new stdClass, 'partner' => null];
    DB::table('core.org_entitlements')->upsert([['org_id' => $orgId, 'rev' => $rev, 'status' => $status, 'until' => $until,
        'claims' => json_encode($claims, JSON_THROW_ON_ERROR), 'token' => 'prova', 'received_at' => now()]],
        ['org_id'], ['rev', 'status', 'until', 'claims', 'token', 'received_at']);
}

it('legge il catalogo dalla tabella una volta sola tra più richieste e lo rilegge dopo una pubblicazione', function (): void {
    $first = app(Catalog::class)->published()->rev;
    nextRequest();

    // Prima: una lettura per richiesta. Dopo: nessuna.
    expect(readsOf('catalog_revisions', fn () => app(Catalog::class)->published()))->toBe(0);

    // Una revisione nuova si vede dopo la pubblicazione, che dimentica la copia (CatalogPublisher::publish → refresh()).
    $document = app(Catalog::class)->published()->document;
    $document['rev'] = $first + 1;
    DB::table('core.catalog_revisions')->insert(['rev' => $first + 1, 'document' => json_encode($document, JSON_THROW_ON_ERROR), 'note' => 'Prova', 'published_at' => now()]);
    nextRequest();
    expect(app(Catalog::class)->published()->rev)->toBe($first, 'senza pubblicazione resta la copia');

    app(DbCatalog::class)->refresh();
    nextRequest();
    expect(app(Catalog::class)->published()->rev)->toBe($first + 1);
});

it('tiene i diritti di ogni associazione con la sua chiave: nessuna associazione vede quelli di un\'altra', function (): void {
    $alfa = $this->createOrg('Polisportiva Alfa');
    $beta = $this->createOrg('Circolo Beta');
    storeEntitlements($alfa->id, 'active', 1);
    storeEntitlements($beta->id, 'active', 10);

    app(Entitlements::class)->for($alfa->id);
    app(Entitlements::class)->for($beta->id);
    nextRequest();

    // Prima: una lettura per associazione a ogni richiesta. Dopo: nessuna.
    $reads = readsOf('org_entitlements', function () use ($alfa, $beta): void {
        expect(app(Entitlements::class)->for($alfa->id)->storageGb)->toBe(1)
            ->and(app(Entitlements::class)->for($beta->id)->storageGb)->toBe(10)
            ->and(app(LifecyclePolicy::class)->readOnly($alfa->id))->toBeFalse();
    });
    expect($reads)->toBe(0);
});

it('una sospensione vale dalla richiesta successiva, anche con i diritti in cache', function (): void {
    $alfa = $this->createOrg('Polisportiva Alfa');
    storeEntitlements($alfa->id, 'trial', 1);
    expect(app(LifecyclePolicy::class)->readOnly($alfa->id))->toBeFalse();
    nextRequest();

    app(OrgDirectory::class)->setStatus($alfa->id, 'suspended');
    nextRequest();

    expect(app(LifecyclePolicy::class)->readOnly($alfa->id))->toBeTrue();

    app(OrgDirectory::class)->setStatus($alfa->id, 'active');
    nextRequest();
    expect(app(LifecyclePolicy::class)->readOnly($alfa->id))->toBeFalse();
});

it('i diritti nuovi del portale valgono dalla richiesta successiva', function (): void {
    $alfa = $this->createOrg('Polisportiva Alfa');
    storeEntitlements($alfa->id, 'active', 1);
    expect(app(Entitlements::class)->for($alfa->id)->storageGb)->toBe(1);
    nextRequest();

    // Come PortalSync: scrive i diritti e dimentica la copia.
    storeEntitlements($alfa->id, 'active', 5, 2);
    app(EntitlementReader::class)->forget($alfa->id);
    nextRequest();

    expect(app(Entitlements::class)->for($alfa->id)->storageGb)->toBe(5);
});

it('una copia salvata con il giro vecchio non vale più', function (): void {
    $alfa = $this->createOrg('Polisportiva Alfa');
    storeEntitlements($alfa->id, 'active', 1);
    app(Entitlements::class)->for($alfa->id);
    $stale = Cache::get('saas:entitlements:copy:'.$alfa->id);
    expect($stale)->toBeArray();

    // Una richiesta lenta rimette la copia vecchia dopo la scrittura: il giro è cambiato, la copia si scarta.
    storeEntitlements($alfa->id, 'active', 7, 2);
    app(EntitlementReader::class)->forget($alfa->id);
    Cache::put('saas:entitlements:copy:'.$alfa->id, $stale, 300);
    nextRequest();

    expect(app(Entitlements::class)->for($alfa->id)->storageGb)->toBe(7);
});

it('in cache non finiscono nomi ed email del titolare', function (): void {
    $alfa = $this->createOrg('Polisportiva Alfa');
    storeEntitlements($alfa->id, 'active', 1, 1, ['owner' => ['email' => 'titolare@example.com', 'name' => 'Mario Rossi'], 'org' => ['name' => 'Polisportiva Alfa']]);
    app(Entitlements::class)->for($alfa->id);

    $cached = (string) json_encode(Cache::get('saas:entitlements:copy:'.$alfa->id));
    expect($cached)->toContain('storage_gb')->not->toContain('titolare@example.com')->not->toContain('Mario Rossi');
});

it('con la cache sul database (Redis assente) non la usa: si legge la tabella', function (): void {
    config(['cache.default' => 'database']);
    expect(SharedCache::store('entitlements'))->toBeNull()->and(SharedCache::store('catalog'))->toBeNull();

    config(['cache.default' => 'array', 'saas.shared_cache.entitlements' => 0]);
    expect(SharedCache::store('entitlements'))->toBeNull()->and(SharedCache::store('catalog'))->not->toBeNull();
});
