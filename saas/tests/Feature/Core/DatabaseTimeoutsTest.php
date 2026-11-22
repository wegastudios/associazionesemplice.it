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
    $this->orgContext()->leave();

    $queries = 0;
    DB::listen(static function () use (&$queries): void {
        $queries++;
    });
    $this->orgContext()->enter($org->id);
    $entered = $queries;

    // Ricerca dell'associazione e una sola istruzione per associazione e tempi.
    expect($entered)->toBe(2)
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
    $console = new App\Modules\Core\Org\DatabaseOrgContext(app('db'), app(App\Modules\Core\Database\DatabaseRegistry::class), console: true);

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
            ->assertSee(DatabaseBusy::MESSAGE, false);
    })->with('codici di tempo scaduto');

    it('gli altri errori del database restano errori', function (): void {
        $this->postJson('/_test/occupato/22012')->assertStatus(500);
    });
});
