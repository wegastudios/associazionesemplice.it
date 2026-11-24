<?php

declare(strict_types=1);

use App\Modules\Core\Console\DatabaseEmptyCommand;
use App\Modules\Core\Database\DatabaseRegistry;
use Illuminate\Support\Facades\DB;

/** Svuota le migrazioni registrate dentro una transazione col proprietario: a fine prova si annulla. */
function withEmptyMigrations(Closure $test): void
{
    $owner = DB::connection(app(DatabaseRegistry::class)->ownerConnection(DatabaseRegistry::SHARED));
    $owner->beginTransaction();
    try {
        $owner->table('migrations')->delete();
        $test();
    } finally {
        $owner->rollBack();
    }
}

it('su un database già migrato risponde «in uso»', function (): void {
    $this->artisan('saas:db:empty')->assertExitCode(DatabaseEmptyCommand::IN_USE);
});

it('su un database senza migrazioni registrate, fuori dalla produzione, risponde «vuoto»', function (): void {
    withEmptyMigrations(function (): void {
        $this->artisan('saas:db:empty')
            ->expectsOutputToContain('database vuoto: primo rilascio')
            ->assertExitCode(DatabaseEmptyCommand::EMPTY);
    });
});

it('in produzione un database vuoto non si migra da soli', function (): void {
    withEmptyMigrations(function (): void {
        $this->app->instance('env', 'production');
        $this->artisan('saas:db:empty')->assertExitCode(DatabaseEmptyCommand::EMPTY_IN_PRODUCTION);
    });
});

it('in produzione un database già migrato resta «in uso»', function (): void {
    $this->app->instance('env', 'production');
    $this->artisan('saas:db:empty')->assertExitCode(DatabaseEmptyCommand::IN_USE);
});

it('se il database non risponde dice «non leggibile»', function (): void {
    config(['database.connections.'.app(DatabaseRegistry::class)->ownerConnection(DatabaseRegistry::SHARED).'.database' => 'database_che_non_esiste']);
    DB::purge(app(DatabaseRegistry::class)->ownerConnection(DatabaseRegistry::SHARED));

    $this->artisan('saas:db:empty')->assertExitCode(DatabaseEmptyCommand::UNREADABLE);
});

it('remote.sh usa il comando: salta il controllo solo se vuoto, ferma in produzione, non torna a una release inesistente', function (): void {
    $remote = (string) file_get_contents(dirname(__DIR__, 3).'/deploy/remote.sh');

    expect($remote)->toContain('artisan saas:db:empty')
        ->and($remote)->toContain('1) health --skip-migrations ;;')
        ->and($remote)->toContain('3) echo "Database vuoto in produzione')
        ->and($remote)->toContain('rm -f "$BASE/current"')
        // il controllo prima delle migrazioni resta uno solo, e viene prima di saas:migrate
        ->and(substr_count($remote, 'health --skip-migrations'))->toBe(1)
        ->and(strpos($remote, 'saas:db:empty'))->toBeLessThan(strpos($remote, 'artisan saas:migrate --force'));
});
