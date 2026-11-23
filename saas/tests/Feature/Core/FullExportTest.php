<?php

declare(strict_types=1);

use App\Modules\Contabilita\Contracts\ReceiptIssued;
use App\Modules\Contabilita\Ledger\FiscalYearService;
use App\Modules\Contabilita\Models\Account;
use App\Modules\Contabilita\Receipts\ReceiptFiles;
use App\Modules\Core\Contracts\Operator;
use App\Modules\Core\Contracts\OrgRole;
use App\Modules\Core\Contracts\ReadableExport;
use App\Modules\Core\Export\FullExports;
use App\Modules\Core\Jobs\BuildFullExport;
use App\Modules\Soci\Models\Person;
use App\Modules\Soci\Services\CertificateService;
use App\Modules\Soci\Services\MembershipService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;

// Dati di prova inventati.
beforeEach(function (): void {
    $this->org = $this->createOrg('Polisportiva Alfa');
    $this->orgContext()->run($this->org->id, function (): void {
        $p = Person::create(['first_name' => 'Mario', 'last_name' => 'Rossi', 'tax_code' => 'RSSMRA80A01H501U', 'birth_date' => '1980-01-01']);
        app(MembershipService::class)->renewWithPayment($p->id, Account::query()->sole()->id, 3000);
        app(CertificateService::class)->add($p->id, ['kind' => 'agonistic', 'issued_on' => now()->subMonth()->toDateString(), 'expires_on' => now()->addYear()->toDateString(), 'doctor' => 'Dr. Bianchi']);
    });
});

it('esporta tutti i dati in un archivio per il titolare', function (): void {
    $owner = $this->createUser('own@example.com', twoFactor: true);
    $this->addOperator($this->org, $owner, OrgRole::Owner);

    $this->orgContext()->run($this->org->id, fn () => app(FiscalYearService::class)->close((int) now()->format('Y')));
    $files = zipContents(downloadFullExport($owner));

    $d = 'Dati per il programma/';
    expect(array_keys($files))->toContain(
        'LEGGIMI.txt', $d.'soci/anagrafica.csv', $d.'soci/libro-soci.csv', $d.'soci/tessere.csv', $d.'soci/certificati-medici.csv', $d.'soci/consensi.csv',
        $d.'contabilita/prima-nota.csv', $d.'contabilita/conti.csv', $d.'contabilita/ricevute.csv', $d.'attivita/attivita.csv',
        $d.'operatori.csv', $d.'ruoli.csv', $d.'impostazioni.csv', $d.'registro-delle-azioni.csv',
    )
        ->and(array_keys($files))->toContain($d.'contabilita/rendiconti-anni-fiscali-chiusi.json', $d.'soci/libri-soci-anni-fiscali-chiusi.json')
        ->and(json_decode($files[$d.'soci/libri-soci-anni-fiscali-chiusi.json'], true)[0]['rows'][0]['name'])->toBe('Rossi Mario')
        ->and($files[$d.'soci/anagrafica.csv'])->toContain('RSSMRA80A01H501U')
        ->and($files[$d.'soci/certificati-medici.csv'])->toContain('Dr. Bianchi')
        ->and($files[$d.'contabilita/prima-nota.csv'])->toContain('Quota associativa')
        ->and($files[$d.'operatori.csv'])->toContain('own@example.com')
        ->and($files['LEGGIMI.txt'])->toContain('Polisportiva Alfa')->toContain('I certificati medici non sono in questa copia.')
        ->toContain('i PDF con qualsiasi lettore PDF');
    $this->orgContext()->run($this->org->id, fn () => expect(DB::table('audit.log')->where('action', 'backup.downloaded')->where('actor_id', $owner->id)->exists())->toBeTrue());
});

it('mette nei documenti leggibili rendiconto, prima nota, libro soci e ricevute di ogni anno fiscale', function (): void {
    $owner = $this->createUser('own@example.com', twoFactor: true);
    $this->addOperator($this->org, $owner, OrgRole::Owner);
    $year = (int) now()->format('Y');
    $this->orgContext()->run($this->org->id, fn () => app(FiscalYearService::class)->close($year));

    $files = zipContents(downloadFullExport($owner));
    $r = 'Documenti leggibili/Anno fiscale '.$year.'/';
    expect(array_keys($files))->toContain($r.'Rendiconto.pdf', $r.'Prima nota.csv', $r.'Libro soci.pdf', $r.'Libro soci.csv');
    expect(str_starts_with($files[$r.'Rendiconto.pdf'], '%PDF'))->toBeTrue()
        ->and(str_starts_with($files[$r.'Libro soci.pdf'], '%PDF'))->toBeTrue()
        ->and($files[$r.'Libro soci.csv'])->toContain('Rossi Mario')
        ->and($files[$r.'Prima nota.csv'])->toContain('Quota associativa');
    $receipts = array_values(array_filter(array_keys($files), fn (string $n): bool => str_starts_with($n, $r.'Ricevute/Ricevuta ')));
    expect($receipts)->toHaveCount(1)->and($receipts[0])->toContain('Rossi Mario')->and(str_starts_with($files[$receipts[0]], '%PDF'))->toBeTrue();
    expect(array_keys($files))->not->toContain('Documenti leggibili/Certificati medici/');
});

it('i certificati medici in chiaro ci sono solo con la casella spuntata', function (): void {
    $owner = $this->createUser('own@example.com', twoFactor: true);
    $this->addOperator($this->org, $owner, OrgRole::Owner);
    $this->orgContext()->run($this->org->id, function (): void {
        $p = Person::query()->sole();
        app(CertificateService::class)->add($p->id, ['kind' => 'agonistic', 'issued_on' => '2026-01-10', 'expires_on' => '2027-01-10', 'doctor' => 'Dr. Verdi'], UploadedFile::fake()->create('cert.pdf', 4, 'application/pdf'));
    });

    $without = zipContents(downloadFullExport($owner));
    expect(array_filter(array_keys($without), fn (string $n): bool => str_contains($n, 'Certificati medici/')))->toBe([]);

    $with = zipContents(downloadFullExport($owner, ['certificati' => 1]));
    $certs = array_values(array_filter(array_keys($with), fn (string $n): bool => str_starts_with($n, 'Documenti leggibili/Certificati medici/')));
    expect($certs)->toHaveCount(1)->and($certs[0])->toContain('Rossi Mario - ')->toContain('scade 2027-01-10')
        ->and($with['LEGGIMI.txt'])->toContain('In «Certificati medici» ci sono i certificati in chiaro');
    [$counts, $healthReader] = $this->orgContext()->run($this->org->id, fn (): array => [array_map(
        fn ($d): int => (int) (json_decode((string) $d, true)['certificates'] ?? -1),
        DB::table('audit.log')->where('action', 'backup.downloaded')->pluck('details')->all(),
    ), DB::table('audit.log')->where('action', 'health.read')->value('actor_id')]);
    // La lettura dei certificati la fa il lavoro in coda, ma nel registro resta chi ha chiesto la copia.
    expect($counts)->toContain(0)->toContain(1)->and($healthReader)->toBe($owner->id);
});

it('non lascia l\'esportazione completa a segreteria e commercialista', function (): void {
    foreach ([OrgRole::Operator, OrgRole::Accountant] as $role) {
        $user = $this->createUser($role->value.'@example.com', twoFactor: true);
        $this->addOperator($this->org, $user, $role);
        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])->post('/gestionale/esportazione.zip')->assertForbidden();
    }
});

it('le ricevute non ancora fissate entrano nella copia (si fissano al momento)', function (): void {
    $owner = $this->createUser('own@example.com', twoFactor: true);
    $this->addOperator($this->org, $owner, OrgRole::Owner);
    // Senza l'evento di emissione la ricevuta resta «da fissare», come quelle emesse prima dell'aggiornamento.
    Event::fake([ReceiptIssued::class]);
    $this->orgContext()->run($this->org->id, function (): void {
        $p = Person::create(['first_name' => 'Anna', 'last_name' => 'Verdi', 'tax_code' => 'VRDNNA85B41H501X', 'birth_date' => '1985-02-01']);
        app(MembershipService::class)->renewWithPayment($p->id, Account::query()->sole()->id, 3000);
        expect(app(ReceiptFiles::class)->unfixedCount())->toBe(1);
    });

    $files = zipContents(downloadFullExport($owner));
    expect(array_filter(array_keys($files), fn (string $n): bool => str_contains($n, '/Ricevute/Ricevuta ')))->toHaveCount(2)
        ->and(array_keys($files))->not->toContain('Documenti leggibili/Anno fiscale '.now()->format('Y').'/Ricevute/Ricevute non incluse.txt');
});

it('con un anno scelto mette solo i documenti leggibili di quell\'anno', function (): void {
    $owner = $this->createUser('own@example.com', twoFactor: true);
    $this->addOperator($this->org, $owner, OrgRole::Owner);
    $year = (int) now()->format('Y');

    $other = zipContents(downloadFullExport($owner, ['anno' => $year - 5]));
    expect(array_filter(array_keys($other), fn (string $n): bool => str_starts_with($n, 'Documenti leggibili/')))->toBe([]);
    $mine = zipContents(downloadFullExport($owner, ['anno' => $year]));
    expect(array_keys($mine))->toContain('Documenti leggibili/Anno fiscale '.$year.'/Rendiconto.pdf');
});

it('oltre la soglia la copia di tutti gli anni non parte e spiega di sceglierne uno', function (): void {
    $owner = $this->createUser('own@example.com', twoFactor: true);
    $this->addOperator($this->org, $owner, OrgRole::Owner);
    $heavy = new class implements ReadableExport
    {
        public function files(Operator $operator, bool $withCertificates, ?int $only = null): array
        {
            return [];
        }

        public function weight(Operator $operator, bool $withCertificates, ?int $only = null): int
        {
            return $only === null ? 100000 : 10;
        }
    };
    app()->instance('test.heavy-export', $heavy);
    app()->tag(['test.heavy-export'], ReadableExport::TAG);

    $this->from('/gestionale/esportazione')->actingAs($owner)->withSession(['auth.password_confirmed_at' => time()])->post('/gestionale/esportazione.zip')
        ->assertRedirect('/gestionale/esportazione')->assertSessionHasErrors(['export' => 'Questa associazione ha molti documenti: una copia unica rischia di non finire. Scegli un anno fiscale e scarica una copia per anno.']);
    downloadFullExport($owner, ['anno' => now()->format('Y')]);
});

it('la copia si chiede solo con un invio del modulo e dopo la conferma della password', function (): void {
    $owner = $this->createUser('own@example.com', twoFactor: true);
    $this->addOperator($this->org, $owner, OrgRole::Owner);

    $this->actingAs($owner)->get('/gestionale/esportazione.zip')->assertStatus(405);
    $this->actingAs($owner)->post('/gestionale/esportazione.zip')->assertRedirect(route('password.confirm'));
    $this->actingAs($owner)->get('/gestionale/esportazione')->assertOk()->assertInertia(fn ($page) => $page->has('csrf')->where('backup', null));
});

it('la copia si prepara in coda: la richiesta risponde subito e la pagina mostra lo stato', function (): void {
    $owner = $this->createUser('own@example.com', twoFactor: true);
    $this->addOperator($this->org, $owner, OrgRole::Owner);
    Queue::fake();

    $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => time()])->post('/gestionale/esportazione.zip')->assertRedirect('/gestionale/esportazione');
    Queue::assertPushed(BuildFullExport::class, fn (BuildFullExport $job): bool => $job->orgId() === $this->org->id);
    $this->get('/gestionale/esportazione')->assertInertia(fn ($page) => $page->where('backup.status', 'queued')->where('backup.url', null));

    // Una sola copia in preparazione per associazione.
    $this->from('/gestionale/esportazione')->post('/gestionale/esportazione.zip')->assertSessionHasErrors(['export' => FullExports::BUSY]);
    Queue::assertPushed(BuildFullExport::class, 1);

    // Il lavoro gira: un secondo giro della coda sulla stessa copia non la rifà.
    $job = Queue::pushed(BuildFullExport::class)->first();
    $this->orgContext()->run($this->org->id, function () use ($job): void {
        app(FullExports::class)->run($job->exportId);
        app(FullExports::class)->run($job->exportId);
    });
    $this->get('/gestionale/esportazione')->assertInertia(fn ($page) => $page->where('backup.status', 'ready')->where('backup.url', fn (string $url): bool => str_contains($url, 'signature=')));
});

it('la copia pronta la scarica solo chi l\'ha chiesta, con il collegamento firmato e fino alla scadenza', function (): void {
    $owner = $this->createUser('own@example.com', twoFactor: true);
    $this->addOperator($this->org, $owner, OrgRole::Owner);
    $admin = $this->createUser('admin@example.com', twoFactor: true);
    $this->addOperator($this->org, $admin, OrgRole::Admin);
    downloadFullExport($owner);
    $id = $this->orgContext()->run($this->org->id, fn (): string => (string) DB::table('app.full_exports')->value('id'));
    $path = FullExports::path($this->org->id, $id);
    expect(is_file($path))->toBeTrue()->and(fileperms($path) & 0777)->toBe(0600);

    // Senza firma, o con la firma di un'altra copia: niente.
    $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => time()])->get('/gestionale/esportazione/'.$id.'.zip')->assertForbidden();
    // Un altro amministratore con il collegamento firmato: la copia non è sua.
    $signed = URL::temporarySignedRoute('export.file', now()->addHour(), ['export' => $id], absolute: false);
    $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])->get($signed)->assertRedirect('/gestionale/esportazione')->assertSessionHasErrors(['export' => FullExports::GONE]);

    // Scaduta: non si scarica più e la pulizia toglie il file.
    $this->orgContext()->run($this->org->id, fn () => DB::table('app.full_exports')->where('id', $id)->update(['expires_at' => now()->subMinute()]));
    $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => time()])->get($signed)->assertRedirect('/gestionale/esportazione')->assertSessionHasErrors(['export' => FullExports::GONE]);
    touch($path, time() - 25 * 3600);
    Artisan::call('saas:tmp:purge');
    expect(is_file($path))->toBeFalse();
});

it('una copia ferma oltre il tempo massimo risulta non riuscita e se ne può chiedere un\'altra', function (): void {
    $owner = $this->createUser('own@example.com', twoFactor: true);
    $this->addOperator($this->org, $owner, OrgRole::Owner);
    Queue::fake();
    $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => time()])->post('/gestionale/esportazione.zip')->assertRedirect();
    $this->orgContext()->run($this->org->id, fn () => DB::table('app.full_exports')->update(['status' => 'running', 'started_at' => now()->subSeconds(FullExports::STALE_AFTER + 60)]));

    $this->get('/gestionale/esportazione')->assertInertia(fn ($page) => $page->where('backup.status', 'failed')->where('backup.error', FullExports::FAILED));
    $this->post('/gestionale/esportazione.zip')->assertRedirect('/gestionale/esportazione')->assertSessionHasNoErrors();
    Queue::assertPushed(BuildFullExport::class, 2);
});
