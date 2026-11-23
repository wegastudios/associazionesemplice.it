<?php

declare(strict_types=1);

use App\Modules\Core\Contracts\OrgRole;
use App\Modules\Core\Platform\PlatformConfig;
use App\Modules\Core\Platform\PlatformSettings;
use App\Modules\Portale\Contracts\Entitlements;
use App\Modules\Portale\Contracts\LifecyclePolicy;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

// Costo fisso di ogni richiesta (lotto 3, H17): le istruzioni che una pagina del gestionale esegue prima del suo lavoro.
// Dati di prova inventati.

/** @return list<string> le istruzioni SQL eseguite dalla richiesta */
function sqlOf(Closure $request): array
{
    $sql = [];
    DB::listen(static function ($query) use (&$sql): void {
        $sql[] = $query->sql;
    });
    $request();

    return $sql;
}

it('misura le istruzioni di una pagina del gestionale', function (string $url): void {
    $org = $this->createOrg('Polisportiva Alfa');
    $user = $this->createUser('own@example.com', twoFactor: true);
    $this->addOperator($org, $user, OrgRole::Owner);
    $this->actingAs($user);

    $this->get($url)->assertOk(); // la prima richiesta riempie le cache di processo
    $sql = sqlOf(fn () => $this->get($url)->assertOk());

    expect(count($sql))->toBeLessThanOrEqual(0, count($sql)." istruzioni:\n".implode("\n", $sql));
})->with(['/gestionale', '/gestionale/soci']);

it('legge le impostazioni della piattaforma dal database una volta sola e le rilegge dopo un salvataggio', function (): void {
    $reads = static fn (array $sql): int => count(array_filter($sql, static fn (string $s): bool => str_contains($s, 'platform_settings') && str_starts_with(ltrim($s), 'select')));

    // Prima: una lettura a ogni avvio (2 su 2). Dopo: la seconda viene dalla cache.
    expect($reads(sqlOf(function (): void {
        app(PlatformConfig::class)->apply();
        app(PlatformConfig::class)->apply();
    })))->toBe(1);

    // Il salvataggio dimentica la copia: il valore nuovo vale subito, anche se la copia non era scaduta.
    app(PlatformSettings::class)->save(['domains.delegation_hours' => '36'], [], null);
    app(PlatformConfig::class)->apply();
    expect(config('saas.domains.delegation_hours'))->toBe(36);

    // In cache le righe restano cifrate.
    $cached = (string) json_encode(Cache::store('array')->get('saas:platform-settings:v1'));
    expect($cached)->toContain('domains.delegation_hours')->not->toContain('"36"');
});

it('non azzera l\'associazione su una connessione su cui non è mai stata impostata', function (): void {
    config(['database.connections.pgsql_probe' => config('database.connections.pgsql')]);
    DB::connection('pgsql_probe')->select('select 1');
    $resets = static fn (array $sql): int => count(array_filter($sql, static fn (string $s): bool => str_contains($s, "set_config('app.org_id', ''")));

    // Prima: un azzeramento per ogni connessione aperta (pgsql e la prova). Dopo: nessuno.
    expect($resets(sqlOf(fn () => $this->orgContext()->leave())))->toBe(0);
});

it('azzera l\'associazione dove è stata impostata, e dentro una transazione anche alla volta successiva', function (): void {
    $org = $this->createOrg('Polisportiva Alfa');
    $resets = static fn (array $sql): int => count(array_filter($sql, static fn (string $s): bool => str_contains($s, "set_config('app.org_id', ''")));

    $this->orgContext()->enter($org->id);
    expect($resets(sqlOf(fn () => $this->orgContext()->leave())))->toBe(1)
        ->and(DB::scalar("SELECT current_setting('app.org_id', true)"))->toBe('');

    // I test girano in una transazione: un rollback annullerebbe l'azzeramento, quindi si ripete.
    expect($resets(sqlOf(fn () => $this->orgContext()->leave())))->toBe(1);
});

it('legge diritti e sola lettura dell\'associazione con una sola istruzione', function (): void {
    $org = $this->createOrg('Polisportiva Alfa');
    $until = now()->addMonth()->toDateString();
    $claims = ['sub' => $org->id, 'ref' => 'sub_prova', 'rev' => 1, 'status' => 'trial', 'until' => $until, 'trial_ends' => null,
        'members' => 150, 'operators' => 3, 'storage_gb' => 1, 'packages' => new stdClass, 'partner' => null];
    DB::table('core.org_entitlements')->upsert([['org_id' => $org->id, 'rev' => 1, 'status' => 'trial', 'until' => $until,
        'claims' => json_encode($claims, JSON_THROW_ON_ERROR), 'token' => 'prova', 'received_at' => now()]],
        ['org_id'], ['rev', 'status', 'until', 'claims', 'token', 'received_at']);
    DB::table('core.orgs')->where('id', $org->id)->update(['status' => 'suspended']);

    // Prima: due (diritti e sola lettura). Dopo: una.
    $sql = sqlOf(function () use ($org): void {
        expect(app(LifecyclePolicy::class)->readOnly($org->id))->toBeTrue();
        app(Entitlements::class)->for($org->id);
    });
    expect(count($sql))->toBe(1, implode("\n", $sql));
});
