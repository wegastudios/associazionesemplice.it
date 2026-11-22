<?php

declare(strict_types=1);

use App\Modules\Core\Contracts\OrgContext;
use App\Modules\Core\Http\DatabaseBusy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/*
 * Tempi massimi su blocchi e istruzioni: chi aspetta un blocco non aspetta per sempre tenendo un processo PHP e una
 * connessione. I tempi si impostano nella stessa istruzione che imposta l'associazione; le operazioni lunghe
 * (importazioni, copie, chiusura dell'anno) e i comandi hanno i propri, più alti. Un blocco o un'istruzione scaduti
 * diventano «riprova tra poco».
 */

/** @return array{lock: string, statement: string, idle: string} */
function dbTimeouts(): array
{
    $row = DB::selectOne("SELECT current_setting('lock_timeout') AS lock, current_setting('statement_timeout') AS statement, current_setting('idle_in_transaction_session_timeout') AS idle");

    return ['lock' => $row->lock, 'statement' => $row->statement, 'idle' => $row->idle];
}

it('entrando in un\'associazione blocchi e istruzioni hanno un tempo massimo, senza query in più', function (): void {
    $org = $this->createOrg('Polisportiva Alfa');
    $open = count(array_filter(DB::getConnections(), static fn ($c): bool => $c->getDriverName() === 'pgsql'));

    $queries = 0;
    DB::listen(static function () use (&$queries): void {
        $queries++;
    });
    $this->orgContext()->enter($org->id);
    $entered = $queries;

    // L'azzeramento delle connessioni aperte, la ricerca dell'associazione e una sola istruzione per associazione e tempi.
    expect($entered)->toBe($open + 2)
        ->and(dbTimeouts())->toBe(['lock' => '5s', 'statement' => '30s', 'idle' => '1min']);
});

it('le operazioni lunghe hanno tempi più alti e alla fine tornano quelli normali', function (): void {
    $org = $this->createOrg('Polisportiva Alfa');
    $context = $this->orgContext();
    $context->enter($org->id);

    $inside = $context->withLongTimeouts(static fn (): array => dbTimeouts());
    // Anche entrando in un'associazione durante l'operazione lunga.
    $entered = $context->withLongTimeouts(static function () use ($context, $org): array {
        $context->enter($org->id);

        return dbTimeouts();
    });

    expect($inside)->toBe(['lock' => '30s', 'statement' => '15min', 'idle' => '5min'])
        ->and($entered)->toBe($inside)
        ->and(dbTimeouts())->toBe(['lock' => '5s', 'statement' => '30s', 'idle' => '1min']);
});

it('comandi e code hanno i tempi delle operazioni lunghe', function (): void {
    $org = $this->createOrg('Polisportiva Alfa');
    $console = new App\Modules\Core\Org\DatabaseOrgContext(app('db'), app(App\Modules\Core\Database\DatabaseRegistry::class), long: true);

    $console->enter($org->id);
    $seen = dbTimeouts();
    $console->leave();

    expect($seen)->toBe(['lock' => '30s', 'statement' => '15min', 'idle' => '5min']);
});

describe('blocco o istruzione scaduti', function (): void {
    beforeEach(function (): void {
        $org = $this->createOrg('Polisportiva Alfa');
        // Dentro una transazione (punto di salvataggio nei test): l'errore annulla solo lei.
        Route::middleware('web')->post('/_test/occupato/{code}', function (string $code, OrgContext $context) use ($org) {
            $context->enter($org->id);
            DB::transaction(static fn () => DB::statement("DO \$\$ BEGIN RAISE EXCEPTION 'prova' USING ERRCODE = '{$code}'; END \$\$"));

            return response('fatto');
        })->whereAlphaNumeric('code');
        Route::middleware('web')->get('/_test/occupato/{code}', function (string $code, OrgContext $context) use ($org) {
            $context->enter($org->id);
            DB::transaction(static fn () => DB::statement("DO \$\$ BEGIN RAISE EXCEPTION 'prova' USING ERRCODE = '{$code}'; END \$\$"));

            return response('fatto');
        })->whereAlphaNumeric('code');
    });

    dataset('codici di tempo scaduto', [
        'attesa di un blocco (lock_timeout)' => ['55P03'],
        'istruzione troppo lunga (statement_timeout)' => ['57014'],
        'transazione ferma (idle_in_transaction_session_timeout)' => ['25P03'],
    ]);

    it('nelle pagine del gestionale (Inertia) torna indietro con l\'avviso «riprova tra poco»', function (string $code): void {
        $this->from('/gestionale')->post("/_test/occupato/{$code}", [], ['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest'])
            ->assertRedirect('/gestionale')
            ->assertSessionHasErrors(['ledger' => DatabaseBusy::NOTICE]);
    })->with('codici di tempo scaduto');

    it('per le API risponde 503 con il messaggio e quando riprovare', function (string $code): void {
        $this->postJson("/_test/occupato/{$code}")
            ->assertStatus(503)
            ->assertHeader('Retry-After', '5')
            ->assertExactJson(['message' => DatabaseBusy::NOTICE]);
    })->with('codici di tempo scaduto');

    it('aprendo una pagina mostra la pagina «riprova tra poco»', function (string $code): void {
        $this->get("/_test/occupato/{$code}")
            ->assertStatus(503)
            ->assertHeader('Retry-After', '5')
            ->assertSee(DatabaseBusy::HEADING)
            ->assertSee(DatabaseBusy::MESSAGE);
    })->with('codici di tempo scaduto');

    it('gli altri errori del database restano errori', function (): void {
        $this->postJson('/_test/occupato/22012')->assertStatus(500);
    });
});

it('chi aspetta un blocco tenuto da un altro smette dopo il tempo massimo e riceve «riprova tra poco»', function (): void {
    config(['saas.database_timeouts.web.lock' => '300ms']);
    $org = $this->createOrg('Polisportiva Alfa');
    $key = random_int(1, PHP_INT_MAX);
    Route::middleware('web')->post('/_test/blocco', function (OrgContext $context) use ($org, $key) {
        $context->enter($org->id);
        DB::transaction(static fn () => DB::select('SELECT pg_advisory_xact_lock(?)', [$key]));

        return response('fatto');
    });

    // Un'altra sessione tiene il blocco (come un'importazione in corso).
    $other = DB::connection(app(App\Modules\Core\Contracts\DatabaseNames::class)->ownerConnection(null));
    $other->select('SELECT pg_advisory_lock(?)', [$key]);
    try {
        $start = microtime(true);
        $response = $this->postJson('/_test/blocco');
        $elapsed = microtime(true) - $start;
    } finally {
        $other->select('SELECT pg_advisory_unlock(?)', [$key]);
    }

    $response->assertStatus(503)->assertExactJson(['message' => DatabaseBusy::NOTICE]);
    expect($elapsed)->toBeLessThan(3.0);
});
