<?php

declare(strict_types=1);

use App\Modules\Attivita\Services\ActivityService;
use App\Modules\Contabilita\Import\LedgerExport;
use App\Modules\Contabilita\Import\LedgerImportService;
use App\Modules\Contabilita\Ledger\FiscalYearService;
use App\Modules\Contabilita\Models\Account;
use App\Modules\Core\Contracts\OrgRole;
use App\Modules\Soci\Models\Person;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

// Dati di prova inventati.
function ledgerFile(string $csv): string
{
    $path = tempnam(sys_get_temp_dir(), 'pn');
    file_put_contents((string) $path, $csv);

    return (string) $path;
}

beforeEach(function (): void {
    $this->org = $this->createOrg('Polisportiva Alfa');
    $this->year = now()->format('Y');
    $this->orgContext()->run($this->org->id, function (): void {
        Person::create(['first_name' => 'Mario', 'last_name' => 'Rossi', 'card_number' => '5']);
        app(ActivityService::class)->save(null, ['name' => 'Yoga', 'kind' => 'course', 'social_year' => $this->year, 'billing' => 'monthly', 'fee_cents' => 3000]);
    });
    $y = $this->year;
    $this->csv = "Data;Tipo;Conto;Modalità;Voce;Importo;Descrizione;Riferimento;N. tessera;Attività;Competenza\n"
        ."10/01/{$y};Entrata;Cassa contanti;Contanti;Quota associativa;30,00;Quota {$y};;5;;\n"
        ."11/01/{$y};Entrata;Cassa contanti;;Corso;40,00;Lezioni;;5;Yoga;gennaio {$y}\n"
        ."12/01/{$y};Uscita;Banca Prova;Bonifico;Affitto sala;120,00;Sala gennaio;FT-7;;;\n"
        ."13/01/{$y};Giroconto in uscita;Cassa contanti;;;50,00;Versamento;;;;\n"
        ."13/01/{$y};Giroconto in entrata;Banca Prova;;;50,00;Versamento;;;;\n"
        ."ieri;Entrata;Cassa contanti;;;5,00;Sbagliata;;;;\n";
});

it('importa entrate, uscite e giroconti, crea i conti e salta i doppioni', function (): void {
    $this->orgContext()->run($this->org->id, function (): void {
        $s = app(LedgerImportService::class);
        $preview = $s->preview(ledgerFile($this->csv), 'prima-nota.csv', null);
        expect(array_column($preview['plan'] ?? [], 'action'))->toBe(['create', 'create', 'create', 'transfer', 'paired', 'error'])
            ->and($preview['summary']['new_accounts'] ?? null)->toBe(['Banca Prova'])
            ->and($preview['summary']['income'] ?? null)->toBe(7000);

        $r = $s->apply($preview['plan'] ?? []);
        expect($r)->toMatchArray(['income' => 2, 'expense' => 1, 'transfer' => 1, 'accounts' => 1, 'failed' => []]);
        $bank = Account::query()->where('name', 'Banca Prova')->sole();
        $yoga = (string) DB::table('app.activities')->value('id');
        expect($bank->type)->toBe('bank')
            ->and(DB::table('app.transactions')->where('activity_id', $yoga)->value('competence_month'))->toBe($this->year.'-01')
            ->and(DB::table('app.transactions')->whereNotNull('person_id')->count())->toBe(2)
            ->and(DB::table('app.receipts')->count())->toBe(0)
            ->and(DB::table('app.transactions')->where('document_ref', 'FT-7')->value('method'))->toBe('bank_transfer')
            ->and(DB::table('audit.log')->where('action', 'ledger.imported')->exists())->toBeTrue();

        // Lo stesso file di nuovo: solo doppioni.
        $again = $s->preview(ledgerFile($this->csv), 'prima-nota.csv', null);
        expect(array_slice(array_column($again['plan'] ?? [], 'action'), 0, 4))->toBe(['duplicate', 'duplicate', 'duplicate', 'duplicate']);
    });
});

it('riconosce come doppioni i movimenti del suo stesso export', function (): void {
    $this->orgContext()->run($this->org->id, function (): void {
        $s = app(LedgerImportService::class);
        $s->apply($s->preview(ledgerFile($this->csv), 'x.csv', null)['plan'] ?? []);
        $csv = app(LedgerExport::class)->csv((int) $this->year)['csv'];
        expect($csv)->toContain('Giroconto in uscita')->toContain('Banca Prova')->toContain('Yoga');
        $plan = $s->preview(ledgerFile($csv), 'export.csv', null)['plan'] ?? [];
        expect(array_values(array_unique(array_column($plan, 'action'))))->toBe(['duplicate', 'paired']);
    });
});

it('salta le righe di un esercizio chiuso e lo dice', function (): void {
    $this->orgContext()->run($this->org->id, function (): void {
        $old = (int) $this->year - 2;
        $years = app(FiscalYearService::class);
        $years->yearFor($old.'-06-01');
        $years->close($old);
        $s = app(LedgerImportService::class);
        $r = $s->apply($s->preview(ledgerFile("Data;Importo;Conto\n10/01/{$old};10,00;Cassa contanti\n10/01/{$this->year};10,00;Cassa contanti\n"), 'x.csv', null)['plan'] ?? []);
        expect($r['income'])->toBe(1)->and($r['failed'])->toHaveCount(1)->and($r['failed'][0])->toContain('riga 2')->toContain('chiuso');
    });
});

it('importa ed esporta dalla prima nota secondo i permessi', function (): void {
    $op = $this->createUser('seg@example.com', twoFactor: true);
    $acc = $this->createUser('acc@example.com', twoFactor: true);
    $this->addOperator($this->org, $op, OrgRole::Operator);
    $this->addOperator($this->org, $acc, OrgRole::Accountant);
    $upload = fn () => UploadedFile::fake()->createWithContent('pn.csv', $this->csv);

    $this->actingAs($op)->get('/gestionale/prima-nota/importa')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Contabilita/Importa')->has('accounts', 1));
    $this->post('/gestionale/prima-nota/importa', ['file' => $upload()])
        ->assertInertia(fn (Assert $page) => $page->where('preview.summary.counts.create', 3)->where('preview.summary.counts.error', 1)->where('preview.rows.5.label', 'errore'));
    $this->post('/gestionale/prima-nota/importa', ['file' => $upload(), 'apply' => true])->assertInertia(fn (Assert $page) => $page->where('result.transfer', 1));
    $this->post('/gestionale/prima-nota/importa', ['file' => UploadedFile::fake()->createWithContent('x.csv', "Nome;Cognome\nA;B")])->assertSessionHasErrors('file');

    $this->actingAs($acc)->get('/gestionale/prima-nota/importa')->assertForbidden();
    $csv = (string) $this->get('/gestionale/prima-nota/esporta?anno='.$this->year)->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8')->getContent();
    expect($csv)->toStartWith("\xEF\xBB\xBFData;Tipo;Conto")->toContain('Affitto sala');
});
