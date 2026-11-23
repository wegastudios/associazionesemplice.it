<?php

declare(strict_types=1);

use App\Modules\Soci\Models\Person;
use Illuminate\Support\Facades\DB;

/*
 * H17, condizioni di Audit: il ricordo «associazione impostata» vale per la singola sessione del database (il PDO), le
 * connessioni non sono persistenti né passano da un pooler, e due richieste di associazioni diverse sulla stessa
 * connessione non si vedono i dati. Dati di prova inventati.
 */

it('non usa connessioni persistenti né pooler', function (): void {
    foreach (config('database.connections') as $name => $connection) {
        expect(is_array($connection) ? ($connection['options'][PDO::ATTR_PERSISTENT] ?? false) : false)->toBeFalse("connessione {$name} persistente")
            ->and(is_array($connection) && isset($connection['connect_via_database']))->toBeFalse("connessione {$name} attraverso un pooler")
            ->and(DB::connection('pgsql')->getPdo()->getAttribute(PDO::ATTR_PERSISTENT))->toBeFalse();
    }

    $found = [];
    foreach (['app', 'config', 'bootstrap'] as $dir) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($dir), FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->getExtension() === 'php' && ! str_contains($file->getPathname(), 'bootstrap/cache')
                && preg_match('/ATTR_PERSISTENT|pgbouncer|connect_via_(database|port)/i', (string) file_get_contents($file->getPathname())) === 1) {
                $found[] = $file->getFilename();
            }
        }
    }
    expect($found)->toBe([]);
});

it('due richieste di associazioni diverse sulla stessa connessione non si vedono i dati', function (): void {
    $alfa = $this->createOrg('Polisportiva Alfa');
    $beta = $this->createOrg('Circolo Beta');
    $pdo = DB::connection('pgsql')->getPdo();

    // Prima richiesta: Alfa scrive un socio, poi la richiesta finisce (ResetOrgContext chiama leave()).
    $this->orgContext()->enter($alfa->id);
    Person::create(['first_name' => 'Anna', 'last_name' => 'Alfa', 'email' => 'anna@example.com']);
    $this->orgContext()->leave();

    // Tra le due richieste la sessione è senza associazione: la RLS non mostra nessuna riga.
    expect(DB::scalar("SELECT current_setting('app.org_id', true)"))->toBe('')
        ->and(DB::table('app.people')->count())->toBe(0);

    // Seconda richiesta, stessa connessione: Beta non vede il socio di Alfa.
    $this->orgContext()->enter($beta->id);
    expect(DB::connection('pgsql')->getPdo())->toBe($pdo)
        ->and(Person::query()->where('last_name', 'Alfa')->count())->toBe(0);
    $this->orgContext()->leave();

    // E Alfa lo vede ancora: il dato c'è, è la RLS che lo separa.
    expect($this->orgContext()->run($alfa->id, fn (): int => Person::query()->where('last_name', 'Alfa')->count()))->toBe(1);
});

it('ricorda l\'associazione impostata per singola connessione: una connessione nuova parte pulita e non si azzera', function (): void {
    $alfa = $this->createOrg('Polisportiva Alfa');
    $resets = static function (Closure $work): int {
        $n = 0;
        DB::listen(static function ($query) use (&$n): void {
            if (str_contains($query->sql, "set_config('app.org_id', ''")) {
                $n++;
            }
        });
        $work();

        return $n;
    };

    $this->orgContext()->enter($alfa->id);

    // Una seconda connessione allo stesso database (altro PDO) non eredita il ricordo né l'associazione.
    config(['database.connections.pgsql_probe' => config('database.connections.pgsql')]);
    $probe = DB::connection('pgsql_probe');
    expect($probe->getPdo())->not->toBe(DB::connection('pgsql')->getPdo())
        ->and($probe->scalar("SELECT current_setting('app.org_id', true)"))->toBeIn(['', null]);

    // Si azzera solo la connessione su cui l'associazione è stata impostata.
    expect($resets(fn () => $this->orgContext()->leave()))->toBe(1)
        ->and($probe->scalar("SELECT current_setting('app.org_id', true)"))->toBeIn(['', null]);
});
