<?php

declare(strict_types=1);

use App\Modules\Contabilita\Contracts\LedgerError;
use App\Modules\Contabilita\Contracts\MemberReceipts;
use App\Modules\Contabilita\Contracts\ReceiptIssued;
use App\Modules\Contabilita\Models\Account;
use App\Modules\Contabilita\Receipts\ReceiptFiles;
use App\Modules\Contabilita\Receipts\ReceiptPdf;
use App\Modules\Core\Contracts\OrgRole;
use App\Modules\Soci\Models\Person;
use App\Modules\Soci\Services\MembershipService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

// Ricevute definitive: il PDF si fissa all'emissione e da lì si serve sempre lo stesso file. Dati di prova inventati.
beforeEach(function (): void {
    $this->org = $this->createOrg('Polisportiva Alfa');
    $this->owner = $this->createUser('own@example.com', twoFactor: true);
    $this->addOperator($this->org, $this->owner, OrgRole::Owner);
    $this->operator = $this->createUser('seg@example.com', twoFactor: true);
    $this->addOperator($this->org, $this->operator, OrgRole::Operator);
    $this->receipt = $this->orgContext()->run($this->org->id, function (): string {
        $p = Person::create(['first_name' => 'Mario', 'last_name' => 'Rossi', 'tax_code' => 'RSSMRA80A01H501U', 'email' => 'mario@example.com', 'birth_date' => '1980-01-01']);
        app(MembershipService::class)->renewWithPayment($p->id, Account::query()->sole()->id, 3000);
        $this->person = $p->id;

        return (string) DB::table('app.receipts')->value('id');
    });
});

function receiptRow(string $org, string $receipt): object
{
    return test()->orgContext()->run($org, fn () => DB::table('app.receipt_files')->where('receipt_id', $receipt)->sole());
}

it('fissa il PDF alla emissione e serve sempre lo stesso file, anche se i dati cambiano dopo', function (): void {
    $row = receiptRow($this->org->id, $this->receipt);
    expect($row->source)->toBe('emissione')->and(strlen((string) $row->sha256))->toBe(64);

    $first = (string) $this->actingAs($this->owner)->get("/gestionale/ricevute/{$this->receipt}.pdf")->assertOk()->getContent();
    expect(hash('sha256', $first))->toBe($row->sha256)->and($first)->toContain('Mario Rossi');

    $this->orgContext()->run($this->org->id, fn () => Person::query()->whereKey($this->person)->firstOrFail()->update(['first_name' => 'Marco']));
    $again = (string) $this->actingAs($this->owner)->get("/gestionale/ricevute/{$this->receipt}.pdf")->assertOk()->getContent();
    expect($again)->toBe($first)->and($again)->toContain('Mario Rossi')->not->toContain('Marco Rossi');
});

it('con il file mancante dà un errore chiaro e non lo rifà di nascosto', function (): void {
    $row = receiptRow($this->org->id, $this->receipt);
    Storage::disk('org_files')->delete($row->path);

    $res = $this->actingAs($this->owner)->get("/gestionale/ricevute/{$this->receipt}.pdf")->assertStatus(409);
    expect($res->getContent())->toContain('non si trova più')->toContain('Non viene rifatto in automatico');
    expect($this->orgContext()->run($this->org->id, fn () => app(ReceiptFiles::class)->status($this->receipt)))->toBe('missing');
});

it('con il file alterato non lo scarica', function (): void {
    $row = receiptRow($this->org->id, $this->receipt);
    Storage::disk('org_files')->put($row->path, 'altro contenuto');

    $res = $this->actingAs($this->owner)->get("/gestionale/ricevute/{$this->receipt}.pdf")->assertStatus(409);
    expect($res->getContent())->toContain('risulta diverso da quando è stato emesso');
    expect($this->orgContext()->run($this->org->id, fn () => app(ReceiptFiles::class)->status($this->receipt)))->toBe('altered');
});

it('l\'amministratore rifà il PDF dai dati dell\'emissione, anche se i dati vivi sono cambiati', function (): void {
    $row = receiptRow($this->org->id, $this->receipt);
    expect($row->snapshot)->not->toBeNull();
    Storage::disk('org_files')->delete($row->path);
    $this->actingAs($this->owner)->post("/gestionale/verifica-ricevute/{$this->receipt}/rifai")->assertSessionHasNoErrors();
    $this->actingAs($this->owner)->get("/gestionale/ricevute/{$this->receipt}.pdf")->assertOk();
    expect(receiptRow($this->org->id, $this->receipt)->source)->toBe('rifatta')
        ->and($this->orgContext()->run($this->org->id, fn () => DB::table('audit.log')->where('action', 'receipt.rebuilt')->exists()))->toBeTrue();

    // Dati cambiati dall'emissione: il PDF si rifà lo stesso, con i dati di allora.
    $this->orgContext()->run($this->org->id, fn () => Person::query()->whereKey($this->person)->firstOrFail()->update(['first_name' => 'Marco']));
    Storage::disk('org_files')->delete(receiptRow($this->org->id, $this->receipt)->path);
    $this->actingAs($this->owner)->post("/gestionale/verifica-ricevute/{$this->receipt}/rifai")->assertSessionHasNoErrors();
    $pdf = (string) $this->actingAs($this->owner)->get("/gestionale/ricevute/{$this->receipt}.pdf")->assertOk()->getContent();
    expect($pdf)->toContain('Mario Rossi')->not->toContain('Marco Rossi');
});

it('senza fotografia (ricevuta vecchia) si rifà solo se i dati di oggi danno lo stesso file', function (): void {
    $this->orgContext()->run($this->org->id, fn () => DB::table('app.receipt_files')->where('receipt_id', $this->receipt)->update(['snapshot' => null]));
    Storage::disk('org_files')->delete(receiptRow($this->org->id, $this->receipt)->path);
    $this->actingAs($this->owner)->post("/gestionale/verifica-ricevute/{$this->receipt}/rifai")->assertSessionHasNoErrors();

    $this->orgContext()->run($this->org->id, fn () => Person::query()->whereKey($this->person)->firstOrFail()->update(['first_name' => 'Marco']));
    Storage::disk('org_files')->delete(receiptRow($this->org->id, $this->receipt)->path);
    $this->actingAs($this->owner)->post("/gestionale/verifica-ricevute/{$this->receipt}/rifai")->assertSessionHasErrors('ledger');
});

it('la fotografia dei dati è cifrata e non contiene testo in chiaro', function (): void {
    $raw = $this->orgContext()->run($this->org->id, fn (): string => (string) DB::table('app.receipt_files')->selectRaw("encode(snapshot, 'escape') as s")->value('s'));
    expect($raw)->not->toBe('')->not->toContain('Rossi')->not->toContain('RSSMRA80A01H501U');
});

it('la verifica è per gli amministratori e mostra lo stato delle ricevute', function (): void {
    $this->actingAs($this->operator)->get('/gestionale/verifica-ricevute')->assertForbidden();
    $this->actingAs($this->owner)->get('/gestionale/verifica-ricevute')->assertOk()
        ->assertInertia(fn ($page) => $page->component('Contabilita/VerificaRicevute')->where('unfixed', 0)->where('rows.0.status', 'ok'));
});

it('al socio con il file mancante dice di avvisare la segreteria', function (): void {
    $row = receiptRow($this->org->id, $this->receipt);
    Storage::disk('org_files')->delete($row->path);
    $msg = $this->orgContext()->run($this->org->id, function (): string {
        try {
            app(MemberReceipts::class)->pdf($this->receipt, [$this->person]);
        } catch (LedgerError $e) {
            return $e->getMessage();
        }

        return '';
    });
    expect($msg)->toBe('Al momento questa ricevuta non è disponibile. Scrivi alla segreteria.');
});

it('il lavoro programmato scorre le associazioni e non trova niente da fissare', function (): void {
    $this->artisan('saas:receipts:fix', ['org' => $this->org->id])->assertSuccessful();
    expect($this->orgContext()->run($this->org->id, fn () => app(ReceiptFiles::class)->unfixedCount()))->toBe(0);
});

it('il PDF rifatto dagli stessi dati è identico byte per byte', function (): void {
    $a = $this->orgContext()->run($this->org->id, fn () => app(ReceiptPdf::class)->build($this->receipt)['pdf']);
    $b = $this->orgContext()->run($this->org->id, fn () => app(ReceiptPdf::class)->build($this->receipt)['pdf']);
    expect($a)->toBe($b);
});

it('il lavoro programmato su tutte le associazioni salta quelle sospese', function (): void {
    Event::fake([ReceiptIssued::class]);
    $this->orgContext()->run($this->org->id, function (): void {
        $p = Person::create(['first_name' => 'Anna', 'last_name' => 'Verdi', 'tax_code' => 'VRDNNA85B41H501X', 'birth_date' => '1985-02-01']);
        app(MembershipService::class)->renewWithPayment($p->id, Account::query()->sole()->id, 3000);
    });
    DB::connection('pgsql')->table('core.orgs')->where('id', $this->org->id)->update(['status' => 'suspended']);

    $this->artisan('saas:receipts:fix')->assertSuccessful();
    expect($this->orgContext()->run($this->org->id, fn () => app(ReceiptFiles::class)->unfixedCount()))->toBe(1);
});

it('la verifica dell\'anno dà lo stato di ogni ricevuta con una sola lettura dell\'elenco', function (): void {
    $year = (int) $this->orgContext()->run($this->org->id, fn () => DB::table('app.receipts')->value('year'));
    $rows = $this->orgContext()->run($this->org->id, fn () => app(ReceiptFiles::class)->overview($year));

    expect($rows)->toHaveCount(1)->and($rows[0]['status'])->toBe('ok');
});

it('il documento di una ricevuta fissata non rifà il PDF: numero e intestatario vengono dall\'emissione', function (): void {
    $row = receiptRow($this->org->id, $this->receipt);
    $queries = 0;
    $doc = $this->orgContext()->run($this->org->id, function () use (&$queries) {
        DB::listen(static function () use (&$queries): void {
            $queries++;
        });

        return app(ReceiptFiles::class)->document($this->receipt);
    });

    expect(hash('sha256', $doc['pdf']))->toBe($row->sha256)
        ->and($doc['number'])->toMatch('/^\d+\/\d{4}$/')
        ->and($doc['filename'])->toBe($row->original_name)
        ->and($queries)->toBeLessThan(8);
});
