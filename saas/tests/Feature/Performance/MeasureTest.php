<?php

declare(strict_types=1);

use App\Modules\AreaSoci\Mail\LoginLink;
use App\Modules\Attivita\Services\ActivityService;
use App\Modules\Attivita\Services\SessionService;
use App\Modules\Contabilita\Models\Account;
use App\Modules\Core\Contracts\DatabaseNames;
use App\Modules\Core\Contracts\OrgRole;
use App\Modules\Core\Contracts\OrgSettings;
use App\Modules\Portale\Contracts\Entitlements;
use App\Modules\Portale\Contracts\OrgEntitlements;
use App\Modules\Soci\Models\Person;
use App\Modules\Soci\Services\MembershipService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

// Strumenti di misura (E28): query per richiesta che non crescono con i soci, piani di esecuzione senza scansioni
// complete, registro delle query lente. Dati di prova inventati.

/** Quante query esegue la richiesta, senza contare i controlli di PostgreSQL sul catalogo. */
function queriesOf(Closure $request): int
{
    $n = 0;
    DB::listen(static function ($query) use (&$n): void {
        $n++;
    });
    $request();

    return $n;
}

function addPeople(string $orgId, int $count, int $offset = 0): void
{
    test()->orgContext()->run($orgId, function () use ($count, $offset): void {
        for ($i = 0; $i < $count; $i++) {
            Person::create(['first_name' => 'Nome'.($offset + $i), 'last_name' => 'Cognome'.($offset + $i), 'email' => 'p'.($offset + $i).'@example.com']);
        }
    });
}

it('elenco e scheda dei soci eseguono lo stesso numero di query con 5 o con 25 soci', function (): void {
    $org = $this->createOrg('Polisportiva Alfa');
    $user = $this->createUser('own@example.com', twoFactor: true);
    $this->addOperator($org, $user, OrgRole::Owner);
    $this->actingAs($user);

    addPeople($org->id, 5);
    $id = $this->orgContext()->run($org->id, fn () => (string) Person::query()->orderBy('last_name')->value('id'));
    $this->get('/gestionale/soci')->assertOk(); // la prima richiesta riempie le cache di processo
    $listFew = queriesOf(fn () => $this->get('/gestionale/soci')->assertOk());
    $cardFew = queriesOf(fn () => $this->get("/gestionale/soci/{$id}")->assertOk());

    addPeople($org->id, 20, 5);
    $listMany = queriesOf(fn () => $this->get('/gestionale/soci')->assertOk());
    $cardMany = queriesOf(fn () => $this->get("/gestionale/soci/{$id}")->assertOk());

    expect($listMany)->toBe($listFew, 'elenco soci: le query crescono con i soci')
        ->and($cardMany)->toBe($cardFew, 'scheda socio: le query crescono con i soci');
});

it('con due associazioni popolate le query sulle tabelle grandi usano un indice, non la scansione completa', function (): void {
    $a = $this->createOrg('Polisportiva Alfa');
    $b = $this->createOrg('Circolo Beta');
    addPeople($a->id, 5);
    addPeople($b->id, 5);

    $owner = DB::connection(app(DatabaseNames::class)->ownerConnection(null));
    $queries = [
        'app.people' => 'SELECT * FROM app.people WHERE last_name = ?',
        'app.people per id' => 'SELECT * FROM app.people WHERE id = ?',
        'app.transactions' => 'SELECT * FROM app.transactions WHERE id = ?',
    ];
    $found = [];
    $owner->transaction(function () use ($owner, $a, $queries, &$found): void {
        $owner->select("SELECT set_config('app.org_id', ?, true)", [$a->id]);
        // Con la scansione completa vietata, se per una query esiste un indice utilizzabile il piano non la contiene:
        // se la policy sulle righe lo impedisse (E1), il piano la mostrerebbe lo stesso.
        $owner->statement('SET LOCAL enable_seqscan = off');
        foreach ($queries as $label => $sql) {
            $bind = str_contains($sql, 'last_name') ? 'Cognome1' : '00000000-0000-7000-8000-000000000000';
            $plan = json_decode((string) ($owner->select('EXPLAIN (FORMAT JSON) '.$sql, [$bind])[0]->{'QUERY PLAN'} ?? '[]'), true);
            array_walk_recursive($plan, static function (mixed $value, mixed $key) use (&$found, $label): void {
                if ($key === 'Node Type' && $value === 'Seq Scan') {
                    $found[] = $label;
                }
            });
        }
    });

    expect($found)->toBe([], 'scansione completa nel piano: '.implode(', ', $found));
});

it('registra le query lente senza i valori', function (): void {
    config(['saas.slow_query_ms' => 1]);
    Log::spy();

    DB::select('SELECT pg_sleep(0.05), ? AS segreto', ['valore-riservato-123']);

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => $message === 'Query lenta'
        && ($context['ms'] ?? 0) >= 1
        && ! str_contains((string) json_encode($context), 'valore-riservato-123'))->atLeast()->once();
});

it('la salute segnala APCu e OPcache quando mancano, senza fallire', function (): void {
    $this->artisan('saas:health', ['--skip-migrations' => true])->assertSuccessful();
});

/** Soci con quota pagata: ogni socio porta con sé un movimento in prima nota e una riga nel libro soci. */
function addPaidPeople(string $orgId, int $count, int $offset = 0): void
{
    test()->orgContext()->run($orgId, function () use ($count, $offset): void {
        $account = Account::query()->sole()->id;
        for ($i = 0; $i < $count; $i++) {
            $person = Person::create(['first_name' => 'Nome'.($offset + $i), 'last_name' => 'Cognome'.($offset + $i), 'email' => 'q'.($offset + $i).'@example.com']);
            app(MembershipService::class)->renewWithPayment($person->id, $account, 3000);
        }
    });
}

it('prima nota, cruscotto e libro soci eseguono lo stesso numero di query con 5 o con 25 soci', function (): void {
    $org = $this->createOrg('Polisportiva Alfa');
    $user = $this->createUser('own@example.com', twoFactor: true);
    $this->addOperator($org, $user, OrgRole::Owner);
    $this->actingAs($user);
    $pages = ['prima nota' => '/gestionale/prima-nota', 'cruscotto' => '/gestionale', 'libro soci' => '/gestionale/soci/libro'];

    addPaidPeople($org->id, 5);
    $few = [];
    foreach ($pages as $label => $url) {
        $this->get($url)->assertOk(); // la prima richiesta riempie le cache di processo
        $few[$label] = queriesOf(fn () => $this->get($url)->assertOk());
    }

    addPaidPeople($org->id, 20, 5);
    foreach ($pages as $label => $url) {
        $many = queriesOf(fn () => $this->get($url)->assertOk());
        expect($many)->toBe($few[$label], $label.': le query crescono con i soci e i movimenti');
    }
});

function measureMemberArea(): void
{
    app()->instance(Entitlements::class, new class implements Entitlements
    {
        public function for(string $orgId): OrgEntitlements
        {
            return new OrgEntitlements(1, 'active', null, null, 150, 3, 5, ['member_area' => []], null);
        }
    });
}

/** Entra nell'area soci con email e codice e restituisce il cookie della sessione. */
function measureMemberLogin(string $base, string $email): string
{
    Mail::fake();
    test()->post($base.'/accesso', ['email' => $email])->assertRedirect('/area/accesso');
    $code = '';
    Mail::assertSent(LoginLink::class, function (LoginLink $m) use (&$code): bool {
        $code = $m->code;

        return true;
    });

    return (string) test()->post($base.'/accesso/codice', ['email' => $email, 'code' => $code])->assertRedirect('/area')->getCookie('as_socio', false)?->getValue();
}

function addEventsOf(string $orgId, int $count, int $offset = 0): void
{
    test()->orgContext()->run($orgId, function () use ($count, $offset): void {
        for ($i = 0; $i < $count; $i++) {
            $activity = app(ActivityService::class)->save(null, ['name' => 'Gita '.($offset + $i), 'kind' => 'recurring', 'social_year' => app(MembershipService::class)->socialYearLabel(), 'fee_cents' => 1000]);
            app(SessionService::class)->addDates($activity->id, now()->addDays(3 + $offset + $i)->toDateString(), null, false, '09:00', null, null, 20);
        }
    });
}

it('la pagina eventi dell\'area soci esegue lo stesso numero di query con 3 o con 12 eventi', function (): void {
    measureMemberArea();
    $org = $this->createOrg('Polisportiva Alfa');
    $base = 'http://'.$org->slug.'.associazionesemplice.it/area';
    $this->orgContext()->run($org->id, function (): void {
        app(OrgSettings::class)->set('ente.email', 'segreteria@alfa.example');
        $mario = Person::create(['first_name' => 'Mario', 'last_name' => 'Rossi', 'email' => 'mario@example.com', 'card_number' => '7', 'birth_date' => '1980-01-01']);
        app(MembershipService::class)->renewWithPayment($mario->id, Account::query()->sole()->id, 3000);
    });
    $me = $this->withUnencryptedCookie('as_socio', measureMemberLogin($base, 'mario@example.com'));

    addEventsOf($org->id, 3);
    $me->get($base.'/eventi')->assertOk(); // la prima richiesta riempie le cache di processo
    $few = queriesOf(fn () => $me->get($base.'/eventi')->assertOk());

    addEventsOf($org->id, 9, 3);
    $many = queriesOf(fn () => $me->get($base.'/eventi')->assertOk());

    expect($many)->toBe($few, 'eventi dell\'area soci: le query crescono con gli eventi');
});

it('le query tipiche su movimenti e date degli eventi leggono da un indice con la chiave giusta, non in scansione completa', function (): void {
    $a = $this->createOrg('Polisportiva Alfa');
    $b = $this->createOrg('Circolo Beta');
    foreach ([$a, $b] as $org) {
        addPaidPeople($org->id, 12);
        addEventsOf($org->id, 6);
    }
    $owner = DB::connection(app(DatabaseNames::class)->ownerConnection(null));
    // Statistiche fresche: il piano non dipende da quando è girato l'ultimo autoanalyze.
    $owner->statement('ANALYZE app.transactions');
    $owner->statement('ANALYZE app.activity_sessions');

    $nil = '00000000-0000-7000-8000-000000000000';
    $year = (int) now()->format('Y');
    // Etichetta => [query, parametri, colonna che l'indice deve usare oltre a org_id].
    $queries = [
        'transactions per anno fiscale' => ['SELECT * FROM app.transactions WHERE org_id = ? AND fiscal_year = ? ORDER BY tx_date DESC LIMIT 50', [$a->id, $year], 'fiscal_year'],
        'transactions per conto e data' => ['SELECT * FROM app.transactions WHERE org_id = ? AND account_id = ? AND tx_date >= ?', [$a->id, $nil, '2026-01-01'], 'account_id'],
        'transactions per socio' => ['SELECT * FROM app.transactions WHERE org_id = ? AND person_id = ?', [$a->id, $nil], 'person_id'],
        'transactions per attivita' => ['SELECT * FROM app.transactions WHERE org_id = ? AND activity_id = ?', [$a->id, $nil], 'activity_id'],
        'transactions per ricevuta' => ['SELECT * FROM app.transactions WHERE org_id = ? AND receipt_id = ?', [$a->id, $nil], 'receipt_id'],
        'activity_sessions prossime date' => ['SELECT * FROM app.activity_sessions WHERE org_id = ? AND session_date >= ? ORDER BY session_date LIMIT 50', [$a->id, now()->toDateString()], 'session_date'],
        'activity_sessions per attivita' => ['SELECT * FROM app.activity_sessions WHERE org_id = ? AND activity_id = ? ORDER BY session_date', [$a->id, $nil], 'activity_id'],
        'activity_sessions per id' => ['SELECT * FROM app.activity_sessions WHERE org_id = ? AND id = ?', [$a->id, $nil], 'id'],
    ];

    $problems = [];
    $owner->transaction(function () use ($owner, $a, $queries, &$problems): void {
        $owner->select("SELECT set_config('app.org_id', ?, true)", [$a->id]);
        // Le tabelle di prova sono piccole e il pianificatore preferirebbe comunque la scansione: la vietiamo, così il
        // piano contiene la scansione completa solo se non esiste un indice utilizzabile; poi si controlla che l'indice
        // scelto usi davvero la colonna della query (non una colonna qualsiasi).
        $owner->statement('SET LOCAL enable_seqscan = off');
        foreach ($queries as $label => [$sql, $params, $column]) {
            $plan = json_decode((string) ($owner->select('EXPLAIN (FORMAT JSON) '.$sql, $params)[0]->{'QUERY PLAN'} ?? '[]'), true);
            $seq = false;
            $conditions = '';
            array_walk_recursive($plan, static function (mixed $value, mixed $key) use (&$seq, &$conditions): void {
                if ($key === 'Node Type' && $value === 'Seq Scan') {
                    $seq = true;
                }
                if ($key === 'Index Cond' && is_string($value)) {
                    $conditions .= ' '.$value;
                }
            });
            if ($seq) {
                $problems[] = $label.': scansione completa';
            } elseif (! str_contains($conditions, $column)) {
                $problems[] = $label.': nessun indice usa '.$column;
            }
        }
    });

    expect($problems)->toBe([], implode('; ', $problems));
});
