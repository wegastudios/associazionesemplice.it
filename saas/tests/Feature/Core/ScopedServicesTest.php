<?php

declare(strict_types=1);

use App\Modules\Audit\Contracts\ActingMember;
use App\Modules\Cifratura\Contracts\EncryptedFiles;
use App\Modules\Cifratura\Contracts\StorageFull;
use App\Modules\Cifratura\Contracts\StorageQuota;
use App\Modules\Piattaforma\Contracts\Catalog;
use App\Modules\Portale\Contracts\LifecyclePolicy;
use Illuminate\Support\Facades\DB;
use Mockery;
use stdClass;

/*
 * Più lavori nello stesso processo (il lavoratore della coda): ognuno parte da servizi nuovi, e diritti, quote e socio
 * che agisce di un lavoro non passano al successivo. Il lavoratore, tra un lavoro e l'altro, fa proprio questo:
 * dimentica le istanze «scoped».
 */

/** Il passaggio da un lavoro al successivo. */
function nextJob(): void
{
    app()->forgetScopedInstances();
}

/** @param  array<string, mixed>  $overrides */
function setEntitlements(string $orgId, string $status, array $overrides = []): void
{
    $claims = $overrides + [
        'sub' => $orgId, 'ref' => 'sub_'.substr($orgId, 0, 8), 'rev' => 1, 'status' => $status,
        'until' => now()->addMonth()->toDateString(), 'trial_ends' => null,
        'members' => 150, 'operators' => 3, 'storage_gb' => 1, 'packages' => new stdClass, 'partner' => null,
    ];
    DB::table('core.org_entitlements')->upsert([[
        'org_id' => $orgId, 'rev' => 1, 'status' => $status, 'until' => $claims['until'],
        'claims' => json_encode($claims, JSON_THROW_ON_ERROR), 'token' => 'prova', 'received_at' => now(),
    ]], ['org_id'], ['rev', 'status', 'until', 'claims', 'token', 'received_at']);
}

it('il socio che agisce non passa al lavoro successivo', function (): void {
    app(ActingMember::class)->set('persona-del-primo-lavoro');
    expect(app(ActingMember::class)->get())->toBe('persona-del-primo-lavoro');

    nextJob();

    expect(app(ActingMember::class)->get())->toBeNull();
});

it('la quota di spazio del primo lavoro non vale per il secondo', function (): void {
    $alfa = $this->createOrg('Polisportiva Alfa');
    $beta = $this->createOrg('Circolo Beta');
    $files = Mockery::mock(EncryptedFiles::class);
    $files->shouldReceive('usedBytes')->andReturn(0);
    app()->instance(EncryptedFiles::class, $files);
    $twoGb = 2 * 1024 ** 3;

    // Primo lavoro: l'associazione Alfa ha 1 GB, il file da 2 GB non entra.
    setEntitlements($alfa->id, 'active', ['storage_gb' => 1]);
    expect(fn () => app(StorageQuota::class)->assertRoom($alfa->id, $twoGb))->toThrow(StorageFull::class);

    // Secondo lavoro: Beta ha 10 GB e Alfa, nel frattempo, ne ha avuti 5 in più.
    setEntitlements($beta->id, 'active', ['storage_gb' => 10]);
    setEntitlements($alfa->id, 'active', ['storage_gb' => 5, 'rev' => 2]);
    nextJob();

    app(StorageQuota::class)->assertRoom($beta->id, $twoGb);
    app(StorageQuota::class)->assertRoom($alfa->id, $twoGb);
    expect(true)->toBeTrue();
});

it('la sola lettura del primo lavoro non passa al secondo e si calcola una volta per lavoro', function (): void {
    $alfa = $this->createOrg('Polisportiva Alfa');
    $beta = $this->createOrg('Circolo Beta');
    setEntitlements($alfa->id, 'trial');
    setEntitlements($beta->id, 'active');
    DB::table('core.orgs')->where('id', $alfa->id)->update(['status' => 'suspended']);

    $queries = 0;
    DB::listen(function ($query) use (&$queries): void {
        if (str_contains($query->sql, 'core"."orgs') && str_contains($query->sql, 'org_entitlements')) {
            $queries++;
        }
    });

    $policy = app(LifecyclePolicy::class);
    expect($policy->readOnly($alfa->id))->toBeTrue()
        ->and($policy->readOnly($alfa->id))->toBeTrue()
        ->and($policy->readOnly($beta->id))->toBeFalse()
        ->and($queries)->toBe(2); // una per associazione, non una per chiamata

    // Secondo lavoro: Alfa ha scelto un piano.
    setEntitlements($alfa->id, 'active');
    DB::table('core.orgs')->where('id', $alfa->id)->update(['status' => 'active']);
    nextJob();

    expect(app(LifecyclePolicy::class)->readOnly($alfa->id))->toBeFalse();
});

it('il catalogo letto da un lavoro non resta in memoria nel successivo', function (): void {
    $first = app(Catalog::class);
    expect(app(Catalog::class))->toBe($first);

    nextJob();

    expect(app(Catalog::class))->not->toBe($first);
});
