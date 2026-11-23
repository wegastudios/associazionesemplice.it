<?php

declare(strict_types=1);

use App\Modules\Core\Contracts\DatabaseNames;
use App\Modules\Core\Contracts\DemoScale;
use App\Modules\Core\Contracts\OrgRole;
use App\Modules\Core\Demo\DemoEnvironment;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/*
 * Pagine lunghe ma legittime con i dati della demo (400 soci, quattro anni fiscali): ognuna ha i tempi massimi delle
 * operazioni lunghe (middleware «db.long») e non si ferma a 30 secondi. Durata e query di ognuna finiscono nel log di CI.
 */

it('le pagine lunghe del browser hanno i tempi delle operazioni lunghe: durata e query con i dati della demo', function (): void {
    config(['saas.demo.enabled' => true, 'saas.demo.people' => 400]);
    // Le altre connessioni (proprietario) non aspettano per sempre la transazione del test: se succede, l'errore si vede.
    $owner = DB::connection(app(DatabaseNames::class)->ownerConnection(null));
    $owner->statement("SET lock_timeout = '20s'");
    $this->beforeApplicationDestroyed(static fn () => $owner->statement('RESET lock_timeout'));
    $seedStart = hrtime(true);
    $org = app(DemoEnvironment::class)->seed();
    fwrite(STDERR, "\n[H2 misure] dati della demo: ".(int) round((hrtime(true) - $seedStart) / 1e6)." ms\n");
    config(['saas.demo.enabled' => false]);
    $user = $this->createUser('titolare-misure@example.com', twoFactor: true);
    $this->addOperator($org, $user, OrgRole::Owner);
    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);

    $year = DemoScale::currentYear();
    $date = date('d/m/Y', (int) strtotime(DemoScale::day($year, 10)));
    $people = "Nome;Cognome;Email\n";
    $ledger = "Data;Importo;Conto\n";
    for ($i = 0; $i < 1000; $i++) {
        $people .= "Nome{$i};Importato{$i};imp{$i}@example.com\n";
        $ledger .= "{$date};1".($i % 90).",00;Cassa importata\n";
    }

    $long = false;
    $queries = 0;
    DB::listen(static function (QueryExecuted $q) use (&$long, &$queries): void {
        $queries++;
        if (str_contains($q->sql, "set_config('lock_timeout'") && ($q->bindings[1] ?? null) === config('saas.database_timeouts.long.lock')) {
            $long = true;
        }
    });

    $operations = [
        'esportazione completa' => fn () => $this->post('/gestionale/esportazione.zip'),
        'libro soci PDF' => fn () => $this->get('/gestionale/soci/libro.pdf'),
        'libro soci CSV' => fn () => $this->get('/gestionale/soci/libro.csv'),
        'esportazione soci' => fn () => $this->get('/gestionale/soci/esporta'),
        'esportazione prima nota' => fn () => $this->get('/gestionale/prima-nota/esporta'),
        'rendiconto anno aperto' => fn () => $this->get('/gestionale/rendiconto/'.($year - 1)),
        'verifica ricevute' => fn () => $this->get('/gestionale/verifica-ricevute?anno='.($year - 2)),
        'verifica ricevute, fissa i PDF' => fn () => $this->post('/gestionale/verifica-ricevute/fissa'),
        'importazione soci (1000 righe)' => fn () => $this->post('/gestionale/soci/importa', ['file' => UploadedFile::fake()->createWithContent('soci.csv', $people), 'default_type' => 'ordinary', 'apply' => true]),
        'importazione prima nota (1000 righe)' => fn () => $this->post('/gestionale/prima-nota/importa', ['file' => UploadedFile::fake()->createWithContent('pn.csv', $ledger), 'apply' => true]),
        'chiusura anno fiscale' => fn () => $this->post('/gestionale/esercizi/'.($year - 1).'/chiudi'),
        'archivio ricevute dell\'anno' => fn () => $this->post('/gestionale/archivio/'.($year - 2)),
    ];

    $report = [];
    foreach ($operations as $name => $request) {
        $long = false;
        $queries = 0;
        fwrite(STDERR, "[H2 misure] inizio: {$name}\n");
        $start = hrtime(true);
        $response = $request();
        $ms = (int) round((hrtime(true) - $start) / 1e6);

        expect($response->getStatusCode())->toBeLessThan(500, "{$name}: risposta {$response->getStatusCode()}")
            ->and($long)->toBeTrue("{$name}: senza i tempi delle operazioni lunghe");
        $report[] = "{$name}: {$ms} ms, {$queries} query";
        fwrite(STDERR, "[H2 misure] {$name}: {$ms} ms, {$queries} query\n");
    }

    fwrite(STDERR, "\n[H2 misure, demo 400 soci] ".implode(' · ', $report)."\n");
});
