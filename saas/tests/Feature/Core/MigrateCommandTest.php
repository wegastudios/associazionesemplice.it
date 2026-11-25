<?php

declare(strict_types=1);

use App\Modules\Core\Console\MigrateAllCommand;
use App\Modules\Core\Database\DatabaseRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;

it('in produzione rifiuta --fresh e chiede --force', function (): void {
    $this->app->instance('env', 'production');

    $this->artisan('saas:migrate', ['--fresh' => true, '--force' => true])->assertFailed();
    $this->artisan('saas:migrate')->assertFailed();
});

it('rifiuta un database sconosciuto', function (): void {
    $this->artisan('saas:migrate', ['--database' => ['inesistente']])->assertFailed();
});

it('risponde al controllo di salute', function (): void {
    $this->get('/up')->assertOk();
});

it('se un database non risponde migra comunque gli altri e alla fine lo segnala', function (): void {
    app(DatabaseRegistry::class)->define('irraggiungibile', ['database' => 'database_che_non_esiste']);

    $this->artisan('saas:migrate', ['--database' => ['irraggiungibile', DatabaseRegistry::SHARED]])
        ->expectsOutputToContain('Database shared')
        ->expectsOutputToContain('non riuscite su: irraggiungibile')
        ->assertFailed();
});

it('con un backup in corso aspetta che finisca prima di migrare, poi procede', function (): void {
    Sleep::fake();
    $owner = app(DatabaseRegistry::class)->ownerConnection(DatabaseRegistry::SHARED);
    config(['database.connections.prova_pg_dump' => config("database.connections.{$owner}")]);
    $dump = DB::connection('prova_pg_dump');
    $dump->statement("SET application_name = 'pg_dump'");

    try {
        $this->artisan('saas:migrate', ['--database' => [DatabaseRegistry::SHARED]])
            ->expectsOutputToContain('Backup in corso')
            ->assertSuccessful();
        Sleep::assertSleptTimes(intdiv(MigrateAllCommand::DUMP_WAIT_SECONDS, MigrateAllCommand::DUMP_POLL_SECONDS));
    } finally {
        $dump->disconnect();
    }
});

it('senza backup in corso migra subito', function (): void {
    Sleep::fake();

    $this->artisan('saas:migrate', ['--database' => [DatabaseRegistry::SHARED]])
        ->doesntExpectOutputToContain('Backup in corso')
        ->assertSuccessful();
    Sleep::assertNeverSlept();
});

it('--fresh cancella ogni schema che le migrazioni creano (altrimenti l\'azzeramento della demo fallisce alla seconda volta)', function (): void {
    $creati = [];
    foreach (glob(app_path('Modules/*/Database/Migrations/*/*.php')) ?: [] as $file) {
        if (preg_match_all('/CREATE SCHEMA (?:IF NOT EXISTS )?([a-z_]+)/', (string) file_get_contents($file), $m) > 0) {
            $creati = [...$creati, ...$m[1]];
        }
    }
    $schemi = (new ReflectionClassConstant(MigrateAllCommand::class, 'SCHEMAS'))->getValue();

    expect(array_values(array_diff(array_unique($creati), $schemi)))->toBe([]);
});
