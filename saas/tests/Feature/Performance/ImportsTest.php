<?php

declare(strict_types=1);

use App\Modules\Contabilita\Import\LedgerImportService;
use App\Modules\Contabilita\Jobs\RunLedgerImport;
use App\Modules\Core\Contracts\OrgContext;
use App\Modules\Core\Contracts\OrgRole;
use App\Modules\Soci\Contracts\MemberType;
use App\Modules\Soci\Jobs\RunPeopleImport;
use App\Modules\Soci\Models\Person;
use App\Modules\Soci\Services\PeopleImportService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

// Import soci e prima nota (H10, H11): query per riga, tempo, e la richiesta che non lavora più le righe. Dati inventati.

/**
 * Query e millisecondi di $work.
 *
 * @return array{0: int, 1: int}
 */
function importMeasure(Closure $work): array
{
    $n = 0;
    DB::listen(static function () use (&$n): void {
        $n++;
    });
    $start = hrtime(true);
    $work();

    return [$n, (int) round((hrtime(true) - $start) / 1e6)];
}

function importPeopleCsv(int $rows, int $offset = 0): string
{
    $csv = 'Nome;Cognome;Email;'.now()->format('Y')."\n";
    for ($i = $offset; $i < $offset + $rows; $i++) {
        $csv .= "Nome{$i};Importato{$i};imp{$i}@example.com;x\n";
    }

    return $csv;
}

function importLedgerCsv(int $rows, int $offset = 0): string
{
    $date = '10/01/'.now()->format('Y');
    $csv = "Data;Importo;Conto;Descrizione\n";
    for ($i = $offset; $i < $offset + $rows; $i++) {
        $csv .= "{$date};1".($i % 90).",00;Cassa contanti;Movimento {$i}\n";
    }

    return $csv;
}

function importTempFile(string $content): string
{
    $path = (string) tempnam(sys_get_temp_dir(), 'imp');
    file_put_contents($path, $content);

    return $path;
}

it('l\'import soci non fa query per ogni riga (H10)', function (): void {
    $org = $this->createOrg('Polisportiva Alfa');
    $run = fn (int $rows, int $offset): array => $this->orgContext()->run($org->id, function () use ($rows, $offset): array {
        $s = app(PeopleImportService::class);
        $plan = $s->preview(importTempFile(importPeopleCsv($rows, $offset)), 'soci.csv', MemberType::Ordinary)['plan'] ?? [];

        return importMeasure(static fn () => expect($s->apply($plan, false)['created'])->toBe($rows));
    });

    [$fewQ, $fewMs] = $run(20, 0);
    [$manyQ, $manyMs] = $run(60, 100);
    $perRow = ($manyQ - $fewQ) / 40;
    fwrite(STDERR, "\n[H10 misure] import soci con tessera: 20 righe {$fewQ} query {$fewMs} ms · 60 righe {$manyQ} query {$manyMs} ms · ".round($perRow, 2)." query in più per riga\n");

    $this->orgContext()->run($org->id, fn () => expect(Person::query()->count())->toBe(80));
    expect($perRow)->toBeLessThanOrEqual(1.5, 'import soci: troppe query per riga');
});

it('l\'import della prima nota non fa query per ogni movimento (H11)', function (): void {
    $org = $this->createOrg('Polisportiva Alfa');
    $run = fn (int $rows, int $offset): array => $this->orgContext()->run($org->id, function () use ($rows, $offset): array {
        $s = app(LedgerImportService::class);
        $plan = $s->preview(importTempFile(importLedgerCsv($rows, $offset)), 'pn.csv', null)['plan'] ?? [];

        return importMeasure(static fn () => expect($s->apply($plan)['income'])->toBe($rows));
    });

    [$fewQ, $fewMs] = $run(20, 0);
    [$manyQ, $manyMs] = $run(60, 100);
    $perRow = ($manyQ - $fewQ) / 40;
    fwrite(STDERR, "\n[H11 misure] import prima nota: 20 movimenti {$fewQ} query {$fewMs} ms · 60 movimenti {$manyQ} query {$manyMs} ms · ".round($perRow, 2)." query in più per movimento\n");

    expect($perRow)->toBeLessThanOrEqual(1.0, 'import prima nota: troppe query per movimento');
});

it('la richiesta di import non lavora le righe: stesse query con 20 o con 80 righe (H10, H11)', function (): void {
    Queue::fake();
    $measure = function (string $url, string $csv, array $extra): array {
        $org = $this->createOrg('Polisportiva '.Str::random(4));
        $user = $this->createUser(Str::random(8).'@example.com', twoFactor: true);
        $this->addOperator($org, $user, OrgRole::Owner);
        $this->actingAs($user);

        return importMeasure(fn () => $this->post($url, ['file' => UploadedFile::fake()->createWithContent('f.csv', $csv), 'apply' => true] + $extra)->assertSessionHasNoErrors());
    };

    // La prima richiesta del processo riempie le cache: non si conta.
    $measure('/gestionale/soci/importa', importPeopleCsv(5), ['default_type' => 'ordinary']);
    $measure('/gestionale/prima-nota/importa', importLedgerCsv(5), []);
    [$peopleFew] = $measure('/gestionale/soci/importa', importPeopleCsv(20), ['default_type' => 'ordinary']);
    [$peopleMany] = $measure('/gestionale/soci/importa', importPeopleCsv(80), ['default_type' => 'ordinary']);
    [$ledgerFew] = $measure('/gestionale/prima-nota/importa', importLedgerCsv(20), []);
    [$ledgerMany] = $measure('/gestionale/prima-nota/importa', importLedgerCsv(80), []);
    fwrite(STDERR, "\n[H10/H11 misure] richiesta di import: soci {$peopleFew} query con 20 righe, {$peopleMany} con 80 · prima nota {$ledgerFew} con 20, {$ledgerMany} con 80\n");

    expect($peopleMany)->toBe($peopleFew, 'richiesta import soci: le query crescono con le righe')
        ->and($ledgerMany)->toBe($ledgerFew, 'richiesta import prima nota: le query crescono con le righe');
});

/** Un giro del lavoro in coda più recente, come lo farebbe il lavoratore (dentro l'associazione). */
function importRunLast(string $class, string $orgId): void
{
    $job = Queue::pushed($class)->last();
    app(OrgContext::class)->run($orgId, fn () => app()->call([$job, 'handle']));
}

/** Associazione con il titolare collegato; restituisce l'id dell'associazione. */
function importOwner(object $test, string $name): string
{
    // Chiusura legata al test: createOrg e gli altri aiuti sono protetti.
    return (function () use ($name): string {
        $org = $this->createOrg($name);
        $user = $this->createUser(Str::random(8).'@example.com', twoFactor: true);
        $this->addOperator($org, $user, OrgRole::Owner);
        $this->actingAs($user);

        return $org->id;
    })->call($test);
}

it('l\'import soci va in coda e avanza a blocchi fino al riepilogo, con lo stesso esito dell\'import diretto (H10)', function (): void {
    Queue::fake();
    config(['saas.imports.block' => 2, 'saas.imports.seconds_per_run' => 0]);
    $year = now()->format('Y');
    $csv = "Tessera;Tipo;Nome;Cognome;Email;Data di nascita;Ospite di;Tutore;{$year}\n"
        ."1;ordinario;Mario;Rossi;mario@example.com;01/01/1980;;;x\n"
        .";genitore;Anna;Verdi;anna@example.com;06/05/1985;;;\n"
        ."2;ordinario;Luigi;Verdi;luigi@example.com;01/01/2014;;anna@example.com;x\n"
        .";ospite;Gino;Bianchi;gino@example.com;;1;;\n"
        .";ordinario;Eva;Blu;eva@example.com;;;;x\n"
        .";ordinario;Senza;Contatti;;;;;\n";

    $direct = $this->orgContext()->run($this->createOrg('Circolo Diretto')->id, function () use ($csv): array {
        $s = app(PeopleImportService::class);

        return $s->apply($s->preview(importTempFile($csv), 'soci.csv', MemberType::Ordinary)['plan'] ?? [], false, 1000);
    });
    expect($direct)->toMatchArray(['created' => 5, 'memberships' => 3, 'guardians' => 1, 'failed' => []]);

    $orgId = importOwner($this, 'Polisportiva Alfa');
    $this->post('/gestionale/soci/importa', ['file' => UploadedFile::fake()->createWithContent('soci.csv', $csv), 'default_type' => 'ordinary', 'apply' => true])
        ->assertRedirectContains('/soci/importa');
    $page = fn () => $this->get('/gestionale/soci/importa')->assertOk();
    $page()->assertInertia(fn (Assert $p) => $p->where('job.status', 'queued')->where('job.total', 5)->where('job.done', 0));
    $this->orgContext()->run($orgId, fn () => expect(Person::query()->count())->toBe(0));

    importRunLast(RunPeopleImport::class, $orgId);
    $page()->assertInertia(fn (Assert $p) => $p->where('job.status', 'running')->where('job.done', 2)->where('job.result.created', 2));
    Queue::assertPushed(RunPeopleImport::class, 2);

    importRunLast(RunPeopleImport::class, $orgId);
    importRunLast(RunPeopleImport::class, $orgId);
    $page()->assertInertia(fn (Assert $p) => $p->where('job.status', 'done')->where('job.done', 5)->where('job.result', $direct));
    Queue::assertPushed(RunPeopleImport::class, 3);

    $this->orgContext()->run($orgId, function (): void {
        expect(Person::query()->count())->toBe(5)
            ->and(DB::table('audit.log')->where('action', 'people.imported')->count())->toBe(1)
            ->and(DB::table('audit.log')->where('action', 'person.created')->count())->toBe(0)
            ->and(Person::query()->where('first_name', 'Gino')->sole()->host_person_id)->toBe(Person::query()->where('card_number', '1')->sole()->id);
    });
});

it('l\'import della prima nota va in coda a blocchi, non crea due volte lo stesso conto e ha lo stesso esito dell\'import diretto (H11)', function (): void {
    Queue::fake();
    config(['saas.imports.block' => 2, 'saas.imports.seconds_per_run' => 0]);
    $y = now()->format('Y');
    $csv = "Data;Tipo;Conto;Voce;Importo;Descrizione\n"
        ."10/01/{$y};Entrata;Cassa contanti;Quota associativa;30,00;Uno\n"
        ."11/01/{$y};Uscita;Banca Prova;Affitto sala;20,00;Due\n"
        ."12/01/{$y};Entrata;Banca Prova;Quota associativa;10,00;Tre\n"
        ."13/01/{$y};Giroconto in uscita;Cassa contanti;;5,00;Versamento\n"
        ."13/01/{$y};Giroconto in entrata;Banca Prova;;5,00;Versamento\n"
        ."14/01/{$y};Uscita;Banca Prova;Affitto sala;7,00;Quattro\n"
        ."ieri;Entrata;Cassa contanti;;5,00;Sbagliata\n";

    $direct = $this->orgContext()->run($this->createOrg('Circolo Diretto')->id, function () use ($csv): array {
        $s = app(LedgerImportService::class);

        return $s->apply($s->preview(importTempFile($csv), 'pn.csv', null)['plan'] ?? [], true, true, 1000);
    });
    expect($direct)->toMatchArray(['income' => 2, 'expense' => 2, 'transfer' => 1, 'accounts' => 1, 'failed' => []]);

    $orgId = importOwner($this, 'Polisportiva Alfa');
    $this->post('/gestionale/prima-nota/importa', ['file' => UploadedFile::fake()->createWithContent('pn.csv', $csv), 'apply' => true])
        ->assertRedirectContains('/prima-nota/importa');
    $page = fn () => $this->get('/gestionale/prima-nota/importa')->assertOk();
    $page()->assertInertia(fn (Assert $p) => $p->where('job.status', 'queued')->where('job.total', 5));

    importRunLast(RunLedgerImport::class, $orgId);
    $page()->assertInertia(fn (Assert $p) => $p->where('job.status', 'running')->where('job.done', 2));
    importRunLast(RunLedgerImport::class, $orgId);
    importRunLast(RunLedgerImport::class, $orgId);
    $page()->assertInertia(fn (Assert $p) => $p->where('job.status', 'done')->where('job.done', 5)->where('job.result', $direct));

    $this->orgContext()->run($orgId, function (): void {
        expect(DB::table('app.accounts')->where('name', 'Banca Prova')->count())->toBe(1)
            ->and(DB::table('app.transactions')->count())->toBe(6)
            ->and(DB::table('audit.log')->where('action', 'ledger.imported')->count())->toBe(1);
    });
});

it('un import in coda si annulla senza importare niente; partito si porta a termine; fermo riprende senza ripetere righe (H10)', function (): void {
    Queue::fake();
    config(['saas.imports.block' => 2, 'saas.imports.seconds_per_run' => 0]);
    $orgId = importOwner($this, 'Polisportiva Alfa');
    $send = fn () => $this->post('/gestionale/soci/importa', ['file' => UploadedFile::fake()->createWithContent('soci.csv', importPeopleCsv(5)), 'default_type' => 'ordinary', 'apply' => true]);
    $page = fn () => $this->get('/gestionale/soci/importa')->assertOk();

    $send()->assertRedirectContains('/soci/importa');
    $first = Queue::pushed(RunPeopleImport::class)->last()->importId;
    // Un secondo import dello stesso tipo aspetta che finisca il primo.
    $send()->assertSessionHasErrors(['file' => 'C\'è già un\'importazione di soci in corso: aspetta che finisca.']);
    $this->post("/gestionale/soci/importa/{$first}/annulla")->assertRedirectContains('/soci/importa');
    $page()->assertInertia(fn (Assert $p) => $p->where('job.status', 'cancelled'));
    importRunLast(RunPeopleImport::class, $orgId); // il lavoro già in coda trova l'import annullato e non fa niente
    $this->orgContext()->run($orgId, fn () => expect(Person::query()->count())->toBe(0));

    $send()->assertRedirectContains('/soci/importa');
    $second = Queue::pushed(RunPeopleImport::class)->last()->importId;
    importRunLast(RunPeopleImport::class, $orgId);
    $this->post("/gestionale/soci/importa/{$second}/annulla")
        ->assertSessionHasErrors(['file' => 'L\'importazione è già partita: non si può più annullare, si porta a termine.']);

    // Il lavoratore si arrende dopo i tentativi: l'import resta fermo dov'era.
    (new RunPeopleImport($orgId, $second))->failed(new RuntimeException('prova'));
    $page()->assertInertia(fn (Assert $p) => $p->where('job.status', 'failed')->where('job.done', 2)->where('job.result.created', 2));

    $this->post("/gestionale/soci/importa/{$second}/riprendi")->assertRedirectContains('/soci/importa');
    $page()->assertInertia(fn (Assert $p) => $p->where('job.status', 'queued')->where('job.done', 2));
    importRunLast(RunPeopleImport::class, $orgId);
    importRunLast(RunPeopleImport::class, $orgId);
    $page()->assertInertia(fn (Assert $p) => $p->where('job.status', 'done')->where('job.done', 5)->where('job.result.created', 5)->where('job.result.memberships', 5));
    $this->orgContext()->run($orgId, fn () => expect(Person::query()->count())->toBe(5)->and(DB::table('app.memberships')->count())->toBe(5));
});

it('un file di 5.000 righe non si importa dentro la richiesta: la richiesta resta breve e il lavoro va a giri brevi (H10, H11)', function (): void {
    Queue::fake();
    config(['saas.imports.seconds_per_run' => 1]);
    $orgId = importOwner($this, 'Polisportiva Alfa');

    [$peopleQ, $peopleMs] = importMeasure(fn () => $this->post('/gestionale/soci/importa', ['file' => UploadedFile::fake()->createWithContent('soci.csv', importPeopleCsv(5000)), 'default_type' => 'ordinary', 'apply' => true])
        ->assertRedirectContains('/soci/importa'));
    [$ledgerQ, $ledgerMs] = importMeasure(fn () => $this->post('/gestionale/prima-nota/importa', ['file' => UploadedFile::fake()->createWithContent('pn.csv', importLedgerCsv(5000)), 'apply' => true])
        ->assertRedirectContains('/prima-nota/importa'));
    $this->orgContext()->run($orgId, fn () => expect(Person::query()->count())->toBe(0)->and(DB::table('app.transactions')->count())->toBe(0));

    $start = hrtime(true);
    importRunLast(RunPeopleImport::class, $orgId);
    $peopleRunMs = (int) round((hrtime(true) - $start) / 1e6);
    $start = hrtime(true);
    importRunLast(RunLedgerImport::class, $orgId);
    $ledgerRunMs = (int) round((hrtime(true) - $start) / 1e6);

    $people = $this->get('/gestionale/soci/importa')->assertOk()->viewData('page')['props']['job'];
    $ledger = $this->get('/gestionale/prima-nota/importa')->assertOk()->viewData('page')['props']['job'];
    fwrite(STDERR, "\n[H10/H11 misure] 5.000 righe: richiesta soci {$peopleMs} ms ({$peopleQ} query), prima nota {$ledgerMs} ms ({$ledgerQ} query) · "
        ."primo giro in coda: soci {$people['done']} righe in {$peopleRunMs} ms, prima nota {$ledger['done']} movimenti in {$ledgerRunMs} ms\n");

    expect($peopleMs)->toBeLessThan(60_000)->and($ledgerMs)->toBeLessThan(60_000)
        ->and($people['total'])->toBe(5000)->and($people['status'])->toBe('running')->and($people['done'])->toBeGreaterThanOrEqual(100)->toBeLessThan(5000)
        ->and($ledger['total'])->toBe(5000)->and($ledger['status'])->toBe('running')->and($ledger['done'])->toBeGreaterThanOrEqual(100)->toBeLessThan(5000);
    Queue::assertPushed(RunPeopleImport::class, 2);
    Queue::assertPushed(RunLedgerImport::class, 2);
});
