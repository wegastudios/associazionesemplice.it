<?php

declare(strict_types=1);

use App\Modules\Audit\Contracts\AuditLog;
use App\Modules\Cifratura\Contracts\EncryptedFiles;
use App\Modules\Cifratura\Contracts\StoredFile;
use App\Modules\Contabilita\Archive\ArchiveLock;
use App\Modules\Contabilita\Contracts\LedgerError;
use App\Modules\Contabilita\Ledger\FiscalYearService;
use App\Modules\Contabilita\Models\Account;
use App\Modules\Core\Contracts\OrgRole;
use App\Modules\Core\Contracts\YearArchives;
use App\Modules\Soci\Models\Person;
use App\Modules\Soci\Services\MembershipService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

// Archivio degli anni chiusi: un file cifrato per anno con le ricevute. Dati di prova inventati.
beforeEach(function (): void {
    $this->org = $this->createOrg('Polisportiva Alfa');
    $this->owner = $this->createUser('own@example.com', twoFactor: true);
    $this->addOperator($this->org, $this->owner, OrgRole::Owner);
    $this->year = (int) now()->format('Y');
    $this->receipt = $this->orgContext()->run($this->org->id, function (): string {
        $p = Person::create(['first_name' => 'Mario', 'last_name' => 'Rossi', 'tax_code' => 'RSSMRA80A01H501U', 'email' => 'mario@example.com', 'birth_date' => '1980-01-01']);
        app(MembershipService::class)->renewWithPayment($p->id, Account::query()->sole()->id, 3000);
        $years = app(FiscalYearService::class);
        $years->close($this->year);
        // L'anno dopo, chiuso a sua volta: così il primo non è più l'ultimo anno chiuso.
        $ends = (string) DB::table('app.fiscal_years')->where('year', $this->year)->value('ends_on');
        $years->create($this->year + 1, date('Y-m-d', (int) strtotime($ends.' +1 day')));
        $years->close($this->year + 1);

        return (string) DB::table('app.receipts')->value('id');
    });
});

function archiveRow(string $org, string $receipt): object
{
    return test()->orgContext()->run($org, fn () => DB::table('app.receipt_files')->where('receipt_id', $receipt)->sole());
}

it('archivia l\'anno, toglie il file singolo e la ricevuta si scarica come prima', function (): void {
    $before = (string) $this->actingAs($this->owner)->get("/gestionale/ricevute/{$this->receipt}.pdf")->assertOk()->getContent();
    $single = archiveRow($this->org->id, $this->receipt)->path;
    expect(Storage::disk('org_files')->exists($single))->toBeTrue();

    $this->actingAs($this->owner)->post("/gestionale/archivio/{$this->year}")->assertSessionHasNoErrors();

    $row = archiveRow($this->org->id, $this->receipt);
    expect((int) $row->archived_year)->toBe($this->year)->and(Storage::disk('org_files')->exists($single))->toBeFalse();
    $archive = $this->orgContext()->run($this->org->id, fn () => DB::table('app.year_archives')->sole());
    expect((int) $archive->file_count)->toBe(1)->and(Storage::disk('org_files')->exists($archive->path))->toBeTrue();
    $after = (string) $this->actingAs($this->owner)->get("/gestionale/ricevute/{$this->receipt}.pdf")->assertOk()->getContent();
    expect($after)->toBe($before)
        ->and($this->orgContext()->run($this->org->id, fn () => DB::table('audit.log')->where('action', 'archive.created')->exists()))->toBeTrue();
    $this->actingAs($this->owner)->get('/gestionale/verifica-ricevute?anno='.$this->year)->assertInertia(fn ($page) => $page->where('rows.0.status', 'archived')->where('rows.0.label', 'In archivio'));
});

it('l\'ultimo anno chiuso e gli anni aperti non si archiviano', function (): void {
    $this->actingAs($this->owner)->post('/gestionale/archivio/'.($this->year + 1))
        ->assertSessionHasErrors(['archive' => 'L\'anno fiscale '.($this->year + 1).' non si può archiviare: è aperto o è l\'ultimo anno chiuso.']);
    $this->actingAs($this->owner)->get('/gestionale/esportazione')->assertInertia(fn ($page) => $page->component('Esportazione')
        ->where('archives.0.year', $this->year + 1)->where('archives.0.state', 'not_yet')
        ->where('archives.1.year', $this->year)->where('archives.1.state', 'ready'));
});

it('rimette i file singoli e toglie l\'archivio', function (): void {
    $before = (string) $this->actingAs($this->owner)->get("/gestionale/ricevute/{$this->receipt}.pdf")->assertOk()->getContent();
    $this->actingAs($this->owner)->post("/gestionale/archivio/{$this->year}")->assertSessionHasNoErrors();
    $archive = $this->orgContext()->run($this->org->id, fn () => DB::table('app.year_archives')->sole());

    $this->actingAs($this->owner)->post("/gestionale/archivio/{$this->year}/rimetti")->assertSessionHasNoErrors();

    $row = archiveRow($this->org->id, $this->receipt);
    expect($row->archived_year)->toBeNull()->and(Storage::disk('org_files')->exists($row->path))->toBeTrue()
        ->and(Storage::disk('org_files')->exists($archive->path))->toBeFalse()
        ->and($this->orgContext()->run($this->org->id, fn () => DB::table('app.year_archives')->count()))->toBe(0);
    expect((string) $this->actingAs($this->owner)->get("/gestionale/ricevute/{$this->receipt}.pdf")->assertOk()->getContent())->toBe($before);
});

it('se una ricevuta non ha un PDF valido non cambia niente: nessun file tolto, nessun archivio', function (): void {
    $single = archiveRow($this->org->id, $this->receipt)->path;
    Storage::disk('org_files')->put($single, 'altro contenuto');
    $filesBefore = Storage::disk('org_files')->allFiles();

    $res = $this->actingAs($this->owner)->post("/gestionale/archivio/{$this->year}")->assertSessionHasErrors('archive');
    $msg = $res->baseResponse->getSession()->get('errors')->first('archive');

    expect(archiveRow($this->org->id, $this->receipt)->archived_year)->toBeNull()
        ->and(Storage::disk('org_files')->exists($single))->toBeTrue()
        ->and($this->orgContext()->run($this->org->id, fn () => DB::table('app.year_archives')->count()))->toBe(0)
        ->and(Storage::disk('org_files')->allFiles())->toEqual($filesBefore);
    expect((string) $msg)->toContain('la ricevuta n.')->toContain('non ha un PDF valido')->toContain('Verifica delle ricevute');
});

it('con l\'archivio alterato la ricevuta non si scarica e lo dice chiaro', function (): void {
    $this->actingAs($this->owner)->post("/gestionale/archivio/{$this->year}")->assertSessionHasNoErrors();
    $archive = $this->orgContext()->run($this->org->id, fn () => DB::table('app.year_archives')->sole());
    Storage::disk('org_files')->put($archive->path, 'archivio rovinato');

    $res = $this->actingAs($this->owner)->get("/gestionale/ricevute/{$this->receipt}.pdf")->assertStatus(409);
    expect($res->getContent())->toContain('è nell\'archivio dell\'anno fiscale '.$this->year)->toContain('non si apre');
    // Rimettere i file con un archivio rovinato non cambia niente.
    $this->actingAs($this->owner)->post("/gestionale/archivio/{$this->year}/rimetti")
        ->assertSessionHasErrors(['archive' => 'L\'archivio non si apre o le impronte non coincidono: nessun file è stato rimesso.']);
    expect((int) archiveRow($this->org->id, $this->receipt)->archived_year)->toBe($this->year);
});

it('la copia di sicurezza ha anche le ricevute degli anni archiviati', function (): void {
    $this->actingAs($this->owner)->post("/gestionale/archivio/{$this->year}")->assertSessionHasNoErrors();

    $zip = new ZipArchive;
    $zip->open($this->actingAs($this->owner)->withSession(['auth.password_confirmed_at' => time()])->post('/gestionale/esportazione.zip')->assertOk()->baseResponse->getFile()->getPathname());
    $names = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $names[] = (string) $zip->getNameIndex($i);
    }
    $pdfs = array_values(array_filter($names, fn (string $n): bool => str_contains($n, 'Anno fiscale '.$this->year.'/Ricevute/Ricevuta ')));
    expect($pdfs)->toHaveCount(1)->and(str_starts_with((string) $zip->getFromName($pdfs[0]), '%PDF'))->toBeTrue();
    $zip->close();
});

it('l\'archivio è solo per titolare e amministratore', function (): void {
    $op = $this->createUser('seg@example.com', twoFactor: true);
    $this->addOperator($this->org, $op, OrgRole::Operator);
    $this->actingAs($op)->post("/gestionale/archivio/{$this->year}")->assertForbidden();
    $this->actingAs($op)->post("/gestionale/archivio/{$this->year}/rimetti")->assertForbidden();
});

it('comprime i PDF nell\'archivio e annota quanto spazio libera', function (): void {
    $this->actingAs($this->owner)->post("/gestionale/archivio/{$this->year}")->assertSessionHasNoErrors();

    [$archive, $details] = $this->orgContext()->run($this->org->id, fn () => [
        DB::table('app.year_archives')->sole(),
        json_decode((string) DB::table('audit.log')->where('action', 'archive.created')->value('details'), true),
    ]);
    $tmp = tempnam(sys_get_temp_dir(), 'arc');
    $this->orgContext()->run($this->org->id, fn () => app(EncryptedFiles::class)->readToFile($this->org->id, new StoredFile($archive->path, 'archivio.zip', 'application/zip', (int) $archive->size_bytes, (string) $archive->sha256), (string) $tmp));
    $zip = new ZipArchive;
    expect($zip->open((string) $tmp))->toBeTrue();
    $entry = $zip->statName($this->receipt.'.pdf');
    $zip->close();
    @unlink((string) $tmp);

    expect($entry['comp_method'])->toBe(ZipArchive::CM_DEFLATE)
        ->and($entry['comp_size'])->toBeLessThan($entry['size'])
        ->and($details['bytes_before'])->toBeGreaterThan(0)
        ->and($details['bytes_after'])->toBe((int) $archive->size_bytes);
});

it('se il registro delle azioni non si scrive non cambia niente: l\'archivio non si registra e i file singoli restano', function (): void {
    $single = archiveRow($this->org->id, $this->receipt)->path;
    $filesBefore = Storage::disk('org_files')->allFiles();
    // Si rompe solo la scrittura dell'archivio; il resto (accesso, associazione) scrive come sempre.
    $this->app->instance(AuditLog::class, new class($this->app->make(AuditLog::class)) implements AuditLog
    {
        public function __construct(private readonly AuditLog $real) {}

        public function record(string $action, ?string $subjectType = null, ?string $subjectId = null, array $details = []): void
        {
            if (str_starts_with($action, 'archive.')) {
                throw new RuntimeException('registro non disponibile');
            }
            $this->real->record($action, $subjectType, $subjectId, $details);
        }
    });

    $this->actingAs($this->owner)->post("/gestionale/archivio/{$this->year}")->assertSessionHasErrors('archive');

    expect(archiveRow($this->org->id, $this->receipt)->archived_year)->toBeNull()
        ->and(Storage::disk('org_files')->exists($single))->toBeTrue()
        ->and($this->orgContext()->run($this->org->id, fn () => DB::table('app.year_archives')->count()))->toBe(0)
        ->and(Storage::disk('org_files')->allFiles())->toEqual($filesBefore);
});

it('il registro dell\'archivio e di «Rimetti i file» è scritto con l\'operazione stessa', function (): void {
    $this->actingAs($this->owner)->post("/gestionale/archivio/{$this->year}")->assertRedirect();
    $this->actingAs($this->owner)->post("/gestionale/archivio/{$this->year}/rimetti")->assertRedirect();

    expect($this->orgContext()->run($this->org->id, fn () => DB::table('audit.log')->whereIn('action', ['archive.created', 'archive.restored'])->orderBy('seq')->pluck('action')->all()))
        ->toBe(['archive.created', 'archive.restored']);
});

it('un anno con le ricevute nell\'archivio non si riapre finché non si rimettono i file', function (): void {
    $this->actingAs($this->owner)->post("/gestionale/archivio/{$this->year}")->assertSessionHasNoErrors();
    $reopen = fn (int $y) => $this->orgContext()->run($this->org->id, fn () => app(FiscalYearService::class)->reopen($y, 'Correzione'));

    $reopen($this->year + 1);
    expect(fn () => $reopen($this->year))
        ->toThrow(LedgerError::class, "L'anno fiscale {$this->year} ha le ricevute nell'archivio: prima rimetti i file da «Copie di sicurezza», poi lo riapri.");

    $this->actingAs($this->owner)->post("/gestionale/archivio/{$this->year}/rimetti")->assertSessionHasNoErrors();
    $reopen($this->year);
    expect($this->orgContext()->run($this->org->id, fn () => DB::table('app.fiscal_years')->where('year', $this->year)->value('status')))->toBe('open');
});

it('archivia, rimette e rifà non si sovrappongono: con un lavoro in corso non cambia niente', function (): void {
    $lock = Cache::lock('receipts-archive:'.$this->org->id, 60);
    expect($lock->get())->toBeTrue();
    $filesBefore = Storage::disk('org_files')->allFiles();

    $this->actingAs($this->owner)->post("/gestionale/archivio/{$this->year}")->assertSessionHasErrors(['archive' => ArchiveLock::BUSY]);
    Storage::disk('org_files')->delete(archiveRow($this->org->id, $this->receipt)->path);
    $this->actingAs($this->owner)->post("/gestionale/verifica-ricevute/{$this->receipt}/rifai")->assertSessionHasErrors(['ledger' => ArchiveLock::BUSY]);

    expect($this->orgContext()->run($this->org->id, fn () => DB::table('app.year_archives')->count()))->toBe(0)
        ->and(count(Storage::disk('org_files')->allFiles()))->toBe(count($filesBefore) - 1);
    $lock->release();
    $this->actingAs($this->owner)->post("/gestionale/verifica-ricevute/{$this->receipt}/rifai")->assertSessionHasNoErrors();
});

it('«Rifai» su un PDF sano non fa niente e lo dice', function (): void {
    $before = archiveRow($this->org->id, $this->receipt);
    $this->actingAs($this->owner)->post("/gestionale/verifica-ricevute/{$this->receipt}/rifai")
        ->assertSessionHasErrors(['ledger' => 'Il PDF di questa ricevuta è a posto: non serve rifarlo.']);
    expect(archiveRow($this->org->id, $this->receipt)->sha256)->toBe($before->sha256);
});

it('con l\'archivio rovinato ricostruisce le ricevute dalle fotografie dei dati', function (): void {
    $before = (string) $this->actingAs($this->owner)->get("/gestionale/ricevute/{$this->receipt}.pdf")->assertOk()->getContent();
    $this->actingAs($this->owner)->post("/gestionale/archivio/{$this->year}")->assertSessionHasNoErrors();
    $archive = $this->orgContext()->run($this->org->id, fn () => DB::table('app.year_archives')->sole());

    // Archivio sano: la ricostruzione non serve e non cambia niente.
    $this->actingAs($this->owner)->post("/gestionale/archivio/{$this->year}/ricostruisci")
        ->assertSessionHasErrors(['archive' => "L'archivio dell'anno fiscale {$this->year} si apre: usa «Rimetti i file»."]);
    expect($this->orgContext()->run($this->org->id, fn () => app(YearArchives::class)->check($this->year)))->toBeTrue();

    Storage::disk('org_files')->put($archive->path, 'archivio rovinato');
    expect($this->orgContext()->run($this->org->id, fn () => app(YearArchives::class)->check($this->year)))->toBeFalse();
    $this->artisan('saas:archive:verify', ['org' => $this->org->id])->assertFailed();
    $this->actingAs($this->owner)->post("/gestionale/archivio/{$this->year}/rimetti")->assertSessionHasErrors(['archive_recover' => (string) $this->year]);

    $this->actingAs($this->owner)->post("/gestionale/archivio/{$this->year}/ricostruisci")->assertSessionHasNoErrors();

    $row = archiveRow($this->org->id, $this->receipt);
    expect($row->archived_year)->toBeNull()->and($row->source)->toBe('rifatta')
        ->and($this->orgContext()->run($this->org->id, fn () => DB::table('app.year_archives')->count()))->toBe(0)
        ->and($this->orgContext()->run($this->org->id, fn () => DB::table('audit.log')->where('action', 'archive.rebuilt_from_snapshots')->exists()))->toBeTrue();
    expect((string) $this->actingAs($this->owner)->get("/gestionale/ricevute/{$this->receipt}.pdf")->assertOk()->getContent())->toBe($before);
});

it('senza le fotografie dei dati la ricostruzione non cambia niente e lo dice', function (): void {
    $this->actingAs($this->owner)->post("/gestionale/archivio/{$this->year}")->assertSessionHasNoErrors();
    $archive = $this->orgContext()->run($this->org->id, fn () => DB::table('app.year_archives')->sole());
    Storage::disk('org_files')->put($archive->path, 'archivio rovinato');
    $this->orgContext()->run($this->org->id, fn () => DB::table('app.receipt_files')->update(['snapshot' => null]));

    $this->actingAs($this->owner)->post("/gestionale/archivio/{$this->year}/ricostruisci")
        ->assertSessionHasErrors(['archive' => 'Per 1 ricevute non c\'è la fotografia dei dati: non è cambiato nulla. Scrivi all\'assistenza di Associazione Semplice.']);
    expect((int) archiveRow($this->org->id, $this->receipt)->archived_year)->toBe($this->year)
        ->and($this->orgContext()->run($this->org->id, fn () => DB::table('app.year_archives')->count()))->toBe(1);
});
