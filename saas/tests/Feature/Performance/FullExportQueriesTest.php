<?php

declare(strict_types=1);

use App\Modules\Core\Access\Memberships;
use App\Modules\Core\Contracts\DatabaseNames;
use App\Modules\Core\Contracts\OrgContext;
use App\Modules\Core\Contracts\OrgRole;
use App\Modules\Core\Demo\DemoEnvironment;
use App\Modules\Core\Export\FullExport;
use App\Modules\Core\Models\Org;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

/*
 * Copia di sicurezza completa con i dati della demo (pochi soci: con più di 40 la preparazione in CI è lentissima). Le
 * query non devono crescere con i soci: con il doppio dei soci, al massimo poche query in più (nessuna query per socio o
 * per ricevuta). Durata, query e le istruzioni più ripetute finiscono nel log di CI.
 */

function seedExportDemo(int $people): Org
{
    config(['saas.demo.enabled' => true, 'saas.demo.people' => $people]);
    $owner = DB::connection(app(DatabaseNames::class)->ownerConnection(null));
    $owner->statement("SET lock_timeout = '20s'");
    test()->beforeApplicationDestroyed(static fn () => $owner->statement('RESET lock_timeout'));
    $org = app(DemoEnvironment::class)->seed();
    config(['saas.demo.enabled' => false]);

    return $org;
}

/** @return array{queries: int, ms: int} */
function measureFullExport(Org $org, string $userId, int $people): array
{
    $operator = app(Memberships::class)->find($userId, $org->id) ?? throw new RuntimeException('Operatore mancante.');
    $bySql = [];
    $queries = 0;
    DB::listen(static function (QueryExecuted $q) use (&$bySql, &$queries): void {
        $queries++;
        $bySql[$q->sql] = ($bySql[$q->sql] ?? 0) + 1;
    });
    $start = hrtime(true);
    $file = app(OrgContext::class)->run($org->id, static fn (): array => app(FullExport::class)->build($operator, $operator->orgName));
    $ms = (int) round((hrtime(true) - $start) / 1e6);
    @unlink($file['path']);

    arsort($bySql);
    fwrite(STDERR, "\n[H12 misure] copia completa, demo {$people} soci: {$ms} ms, {$queries} query, {$file['files']} file\n");
    foreach (array_slice($bySql, 0, 15, true) as $sql => $n) {
        fwrite(STDERR, "[H12 misure]   {$n} x ".substr((string) $sql, 0, 240)."\n");
    }

    return ['queries' => $queries, 'ms' => $ms];
}

test('copia completa con 20 soci', function (): array {
    $org = seedExportDemo(20);
    $user = $this->createUser('titolare-copia@example.com', twoFactor: true);
    $this->addOperator($org, $user, OrgRole::Owner);
    $this->actingAs($user);

    return measureFullExport($org, $user->id, 20);
});

test('copia completa con 40 soci: le query non crescono con i soci', function (array $small): void {
    $org = seedExportDemo(40);
    $user = $this->createUser('titolare-copia@example.com', twoFactor: true);
    $this->addOperator($org, $user, OrgRole::Owner);
    $this->actingAs($user);

    $large = measureFullExport($org, $user->id, 40);
    $perPerson = ($large['queries'] - $small['queries']) / 20;
    fwrite(STDERR, "[H12 misure] query in più per socio aggiunto: {$perPerson}\n");

    expect($perPerson)->toBeLessThan(0.5);
})->depends('copia completa con 20 soci');
