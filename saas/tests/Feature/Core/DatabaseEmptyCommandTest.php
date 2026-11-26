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

/** Il blocco del ritorno automatico di remote.sh: [ramo «then» (c'è una versione precedente), ramo «else» (primo rilascio), blocco intero]. */
function rollbackBlock(): array
{
    $remote = (string) file_get_contents(dirname(__DIR__, 3).'/deploy/remote.sh');
    expect(preg_match('/^    if \[ -n "\$PREVIOUS" \]; then\n(.*?)\n    else\n(.*?)\n    fi\n/ms', $remote, $m))->toBe(1);

    return [$m[1], $m[2], $m[0]];
}

it('rm -f current compare una sola volta in remote.sh, e solo nel ramo else di «c\'è una versione precedente»', function (): void {
    $remote = (string) file_get_contents(dirname(__DIR__, 3).'/deploy/remote.sh');
    [$then, $else] = rollbackBlock();

    expect(substr_count($remote, 'rm -f "$BASE/current"'))->toBe(1)
        ->and($then)->not->toContain('rm -f "$BASE/current"')
        ->and($else)->toContain('rm -f "$BASE/current"')
        ->and(strpos($remote, 'rm -f "$BASE/current"'))->toBeGreaterThan(strpos($remote, "    else\n"));
});

it('eseguendo il blocco: con una versione precedente current torna indietro e resta, senza è rimosso', function (): void {
    [, , $block] = rollbackBlock();
    $run = function (bool $withPrevious) use ($block): array {
        $base = sys_get_temp_dir().'/remote-sh-'.bin2hex(random_bytes(4));
        mkdir($base.'/releases/vecchia', 0777, true);
        mkdir($base.'/releases/nuova', 0777, true);
        symlink($base.'/releases/nuova', $base.'/current');
        $previous = $withPrevious ? $base.'/releases/vecchia' : '';
        $script = 'BASE='.escapeshellarg($base).'; PREVIOUS='.escapeshellarg($previous).'; PHP=true'."\n".$block;
        shell_exec('bash -c '.escapeshellarg($script).' 2>/dev/null');
        $result = [is_link($base.'/current'), is_link($base.'/current') ? readlink($base.'/current') : null, $base];

        return $result;
    };

    [$exists, $target, $base] = $run(true);
    expect($exists)->toBeTrue()->and($target)->toBe($base.'/releases/vecchia');

    [$exists, , $base2] = $run(false);
    expect($exists)->toBeFalse()
        ->and(is_dir($base2.'/releases/nuova'))->toBeTrue(); // i dati (le release) restano
});
