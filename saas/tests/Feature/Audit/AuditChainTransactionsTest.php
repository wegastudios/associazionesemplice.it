<?php

declare(strict_types=1);

use App\Modules\Audit\Contracts\AuditLog;
use App\Modules\Audit\Log\ChainedAuditLog;
use App\Modules\Audit\Log\ChainVerifier;
use App\Modules\Audit\Log\PendingEntries;
use App\Modules\Cifratura\Contracts\Sealer;
use App\Modules\Core\Contracts\CurrentOrg;
use App\Modules\Core\Contracts\OrgContext;
use App\Modules\Soci\Models\Person;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * Dentro una transazione le righe del registro si legano alla catena tutte insieme, come ultima cosa prima della
 * conferma: la testa della catena si blocca una volta sola, dopo tutti gli altri blocchi, e chi scrive nel frattempo
 * per la stessa associazione non aspetta la fine della transazione. Qui le transazioni si confermano davvero, come in
 * produzione: i dati si cancellano alla fine.
 */

beforeEach(function (): void {
    DB::commit();
    $this->owner = DB::connection('pgsql_owner');
    $this->alfa = $this->createOrg('Polisportiva Alfa')->id;
});

afterEach(function (): void {
    DB::purge('pgsql_probe');
    $this->owner->statement('TRUNCATE app.people, app.orgs CASCADE');
    $this->owner->statement('TRUNCATE core.org_keys, core.org_users, core.orgs CASCADE');
    DB::beginTransaction();
});

/** Azioni del registro di un'associazione in ordine di numero, senza la preparazione della contabilità. */
function chainActions(string $orgId): array
{
    return test()->orgContext()->run($orgId, fn () => DB::table('audit.log')->where('action', '<>', 'accounting.seeded')->orderBy('seq')->pluck('action')->all());
}

/** Seconda connessione allo stesso database, con l'associazione impostata: un'altra richiesta in parallelo. */
function probeConnection(string $orgId): Connection
{
    config(['database.connections.pgsql_probe' => config('database.connections.pgsql')]);
    $probe = DB::connection('pgsql_probe');
    $probe->statement("SET lock_timeout = '2s'");
    $probe->select("SELECT set_config('app.org_id', ?, false)", [$orgId]);

    return $probe;
}

/** Il registro come lo usa un'altra richiesta della stessa associazione, sulla connessione di prova. */
function probeAuditLog(string $orgId): ChainedAuditLog
{
    $context = new class(new CurrentOrg($orgId, 'pgsql_probe')) implements OrgContext
    {
        public function __construct(private readonly CurrentOrg $org) {}

        public function enter(string $orgId): CurrentOrg
        {
            return $this->org;
        }

        public function leave(): void {}

        public function current(): CurrentOrg
        {
            return $this->org;
        }

        public function require(): CurrentOrg
        {
            return $this->org;
        }

        public function run(string $orgId, callable $callback): mixed
        {
            return $callback($this->org);
        }

        public function withLongTimeouts(callable $callback): mixed
        {
            return $callback();
        }
    };

    return new ChainedAuditLog(app(), app('db'), $context, app(Sealer::class), new PendingEntries);
}

it('un\'altra richiesta della stessa associazione scrive nel registro senza aspettare la transazione aperta', function (): void {
    $waited = null;

    $this->orgContext()->run($this->alfa, function () use (&$waited): void {
        // Dopo l'ingresso nell'associazione, che azzera app.org_id su tutte le connessioni aperte.
        probeConnection($this->alfa);
        $other = probeAuditLog($this->alfa);

        DB::transaction(function () use ($other, &$waited): void {
            Person::create(['first_name' => 'Primo', 'last_name' => 'Prova']);
            Person::create(['first_name' => 'Secondo', 'last_name' => 'Prova']);

            // Prima la testa restava bloccata fino alla conferma: qui si aspettava fino al tempo massimo (2 s) e si falliva.
            $start = hrtime(true);
            $other->record('prova.altra_richiesta');
            $waited = (hrtime(true) - $start) / 1e6;
        });
    });

    fwrite(STDERR, sprintf("\n[H9] scrittura nel registro durante una transazione aperta: %.1f ms\n", $waited));
    expect($waited)->toBeLessThan(500.0)
        // L'altra richiesta ha confermato prima: nella catena viene prima, poi le righe della transazione nel loro ordine.
        ->and(chainActions($this->alfa))->toBe(['prova.altra_richiesta', 'person.created', 'person.created'])
        ->and(app(ChainVerifier::class)->verify($this->alfa)['ok'])->toBeTrue();
    $this->artisan('saas:audit:verify', ['org' => $this->alfa])->assertSuccessful();
});

it('blocca la testa della catena una volta sola, per ultima, con un solo inserimento', function (): void {
    $queries = [];
    DB::listen(function (QueryExecuted $q) use (&$queries): void {
        if ($q->connectionName === 'pgsql') {
            $queries[] = $q->sql;
        }
    });

    $this->orgContext()->run($this->alfa, function (): void {
        DB::transaction(function (): void {
            $person = Person::create(['first_name' => 'Mario', 'last_name' => 'Rossi']);
            // Un altro blocco dopo una riga del registro: la testa non deve essere già bloccata.
            DB::table('app.people')->where('id', $person->id)->lockForUpdate()->first();
            $person->update(['phone' => '+39 333 0000000']);
            DB::transaction(fn () => Person::create(['first_name' => 'Anna', 'last_name' => 'Bianchi']));
        });
    });

    $headLocks = array_keys(array_filter($queries, static fn (string $sql): bool => str_contains($sql, '"audit"."heads"') && str_contains($sql, 'for update')));
    $otherLocks = array_keys(array_filter($queries, static fn (string $sql): bool => ! str_contains($sql, '"audit".') && str_contains($sql, 'for update')));
    $peopleWrites = array_keys(array_filter($queries, static fn (string $sql): bool => preg_match('/^(insert into|update) "app"."people"/', $sql) === 1));
    $logInserts = array_filter($queries, static fn (string $sql): bool => str_starts_with($sql, 'insert into "audit"."log"'));

    expect($headLocks)->toHaveCount(1)
        ->and($logInserts)->toHaveCount(1)
        ->and($otherLocks)->not->toBeEmpty()
        ->and($headLocks[0])->toBeGreaterThan(max($otherLocks))
        ->and($headLocks[0])->toBeGreaterThan(max($peopleWrites))
        ->and($queries[$headLocks[0] + 1])->toStartWith('insert into "audit"."log"')
        ->and($queries[$headLocks[0] + 2])->toStartWith('update "audit"."heads"')
        ->and(chainActions($this->alfa))->toBe(['person.created', 'person.updated', 'person.created'])
        ->and(app(ChainVerifier::class)->verify($this->alfa)['ok'])->toBeTrue();
});

it('segue la transazione: le righe di un punto di salvataggio annullato o di una transazione annullata non entrano', function (): void {
    $this->orgContext()->run($this->alfa, function (): void {
        DB::transaction(function (): void {
            Person::create(['first_name' => 'Resta', 'last_name' => 'Prova']);
            try {
                DB::transaction(function (): void {
                    Person::create(['first_name' => 'Annullato', 'last_name' => 'Prova']);
                    throw new RuntimeException('annulla il punto di salvataggio');
                });
            } catch (RuntimeException) {
                // atteso
            }
            app(AuditLog::class)->record('prova.dopo');
        });

        try {
            DB::transaction(function (): void {
                Person::create(['first_name' => 'Annullata', 'last_name' => 'Prova']);
                throw new RuntimeException('annulla tutto');
            });
        } catch (RuntimeException) {
            // atteso
        }
    });

    $seqs = $this->orgContext()->run($this->alfa, fn () => DB::table('audit.log')->orderBy('seq')->pluck('seq')->map(fn ($s): int => (int) $s)->all());
    expect(chainActions($this->alfa))->toBe(['person.created', 'prova.dopo'])
        ->and($seqs)->toBe(range(1, count($seqs)))
        ->and(app(ChainVerifier::class)->verify($this->alfa)['ok'])->toBeTrue();
});

it('lega le righe di più associazioni nella stessa transazione, anche se alla conferma non c\'è un\'associazione corrente', function (): void {
    $beta = $this->createOrg('Circolo Beta')->id;

    DB::transaction(function () use ($beta): void {
        $this->orgContext()->run($this->alfa, fn () => Person::create(['first_name' => 'Socio', 'last_name' => 'Alfa']));
        $this->orgContext()->run($beta, fn () => Person::create(['first_name' => 'Socio', 'last_name' => 'Beta']));
        $this->orgContext()->run($this->alfa, fn () => app(AuditLog::class)->record('prova.alfa'));
    });

    expect(chainActions($this->alfa))->toBe(['person.created', 'prova.alfa'])
        ->and(chainActions($beta))->toBe(['person.created'])
        ->and(app(ChainVerifier::class)->verify($this->alfa)['ok'])->toBeTrue()
        ->and(app(ChainVerifier::class)->verify($beta)['ok'])->toBeTrue()
        ->and($this->orgSetting())->toBe('');
});

it('se le righe del registro non si legano, la transazione non si conferma e la connessione resta pulita', function (): void {
    $probe = probeConnection($this->alfa);
    $before = $this->orgContext()->run($this->alfa, fn () => Person::query()->count());

    // Un'altra connessione tiene la testa: alla conferma il blocco scade.
    // L'associazione si reimposta qui: l'ingresso nell'associazione qui sopra la azzera su tutte le connessioni aperte.
    $probe->beginTransaction();
    $probe->select("SELECT set_config('app.org_id', ?, true)", [$this->alfa]);
    expect($probe->select('SELECT 1 FROM audit.heads WHERE org_id = ? FOR UPDATE', [$this->alfa]))->toHaveCount(1);
    try {
        $this->orgContext()->run($this->alfa, function (): void {
            expect(fn () => DB::transaction(function (): void {
                DB::statement("SET LOCAL lock_timeout = '200ms'");
                Person::create(['first_name' => 'Mai', 'last_name' => 'Confermato']);
            }))->toThrow(QueryException::class);
        });
    } finally {
        $probe->rollBack();
    }

    expect(DB::transactionLevel())->toBe(0)
        ->and(DB::getPdo()->inTransaction())->toBeFalse()
        ->and($this->orgContext()->run($this->alfa, fn () => Person::query()->count()))->toBe($before)
        ->and(chainActions($this->alfa))->toBe([]);

    // La connessione si usa di nuovo normalmente.
    $this->orgContext()->run($this->alfa, fn () => DB::transaction(fn () => Person::create(['first_name' => 'Dopo', 'last_name' => 'Prova'])));
    expect(chainActions($this->alfa))->toBe(['person.created'])
        ->and(app(ChainVerifier::class)->verify($this->alfa)['ok'])->toBeTrue();
});
