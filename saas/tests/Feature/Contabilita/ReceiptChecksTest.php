<?php

declare(strict_types=1);

use App\Modules\Cifratura\Contracts\EncryptedFiles;
use App\Modules\Contabilita\Models\Account;
use App\Modules\Contabilita\Receipts\ReceiptFiles;
use App\Modules\Soci\Models\Person;
use App\Modules\Soci\Services\MembershipService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/*
 * «Verifica delle ricevute» senza leggere e decifrare ogni PDF a ogni apertura (H34): l'elenco controlla che il file ci
 * sia e abbia la dimensione giusta; il contenuto lo verifica per intero la notte (a blocchi) o la verifica su richiesta,
 * e da lì l'elenco ne mostra l'esito. Dati di prova inventati.
 */

beforeEach(function (): void {
    $this->org = $this->createOrg('Polisportiva Alfa');
    $this->receipts = $this->orgContext()->run($this->org->id, function (): array {
        foreach (['Mario', 'Anna', 'Luca'] as $i => $name) {
            $p = Person::create(['first_name' => $name, 'last_name' => 'Rossi', 'birth_date' => '1980-01-0'.($i + 1)]);
            app(MembershipService::class)->renewWithPayment($p->id, Account::query()->sole()->id, 3000);
        }

        return DB::table('app.receipts')->orderBy('number')->pluck('id')->all();
    });
    $this->year = (int) $this->orgContext()->run($this->org->id, fn () => DB::table('app.receipts')->value('year'));
});

afterEach(function (): void {
    Storage::disk('org_files')->deleteDirectory('/');
});

/** @return array<string, string> ricevuta => stato nell'elenco di «Verifica delle ricevute» */
function receiptOverview(string $orgId, int $year): array
{
    $rows = test()->orgContext()->run($orgId, fn () => app(ReceiptFiles::class)->overview($year));

    return array_column($rows, 'status', 'id');
}

function receiptFileRow(string $orgId, string $receipt): object
{
    return test()->orgContext()->run($orgId, fn () => DB::table('app.receipt_files')->where('receipt_id', $receipt)->sole());
}

it('alla creazione il PDF risulta già verificato, con il suo file', function (): void {
    $row = receiptFileRow($this->org->id, $this->receipts[0]);

    expect($row->checked_status)->toBe('ok')->and($row->checked_path)->toBe($row->path)->and($row->checked_at)->not->toBeNull();
});

it('l\'elenco non decifra i file: mancanti e troncati subito, alterati della stessa dimensione dopo la verifica completa', function (): void {
    [$missing, $truncated, $altered] = $this->receipts;
    $disk = Storage::disk('org_files');
    $disk->delete(receiptFileRow($this->org->id, $missing)->path);
    $path = receiptFileRow($this->org->id, $truncated)->path;
    $disk->put($path, substr((string) $disk->get($path), 0, 100));
    // Stessa dimensione, un byte cambiato: per vederlo bisogna leggere e decifrare il file.
    $path = receiptFileRow($this->org->id, $altered)->path;
    $bytes = (string) $disk->get($path);
    $bytes[200] = $bytes[200] === 'a' ? 'b' : 'a';
    $disk->put($path, $bytes);

    expect(receiptOverview($this->org->id, $this->year))->toBe([$missing => 'missing', $truncated => 'altered', $altered => 'ok']);

    // La verifica completa (di notte, o su richiesta dalla pagina prima di rifare il PDF) lo scopre e lo salva.
    $this->artisan('saas:receipts:verify')->expectsOutputToContain('3 verificate, 1 mancanti, 2 alterate')->assertSuccessful();
    expect(receiptOverview($this->org->id, $this->year))->toBe([$missing => 'missing', $truncated => 'altered', $altered => 'altered'])
        ->and(receiptFileRow($this->org->id, $altered)->checked_status)->toBe('altered');
});

it('la verifica su richiesta salva l\'esito e il PDF rifatto torna a posto', function (): void {
    $receipt = $this->receipts[1];
    $path = receiptFileRow($this->org->id, $receipt)->path;
    $disk = Storage::disk('org_files');
    $bytes = (string) $disk->get($path);
    $bytes[300] = $bytes[300] === 'a' ? 'b' : 'a';
    $disk->put($path, $bytes);

    expect($this->orgContext()->run($this->org->id, fn () => app(ReceiptFiles::class)->status($receipt)))->toBe('altered')
        ->and(receiptOverview($this->org->id, $this->year)[$receipt])->toBe('altered');

    $this->orgContext()->run($this->org->id, fn () => app(ReceiptFiles::class)->rebuild($receipt));
    expect(receiptOverview($this->org->id, $this->year)[$receipt])->toBe('ok')
        ->and(receiptFileRow($this->org->id, $receipt)->checked_path)->toBe(receiptFileRow($this->org->id, $receipt)->path);
});

it('le ricevute già fissate prima della verifica salvata si riempiono a blocchi, prima le mai verificate', function (): void {
    $this->orgContext()->run($this->org->id, fn () => DB::table('app.receipt_files')->update(['checked_path' => null, 'checked_status' => null, 'checked_at' => null]));

    $this->artisan('saas:receipts:verify', ['--batch' => 2])->assertSuccessful();
    $done = $this->orgContext()->run($this->org->id, fn () => DB::table('app.receipt_files')->whereNotNull('checked_at')->count());
    expect($done)->toBe(2);

    $this->artisan('saas:receipts:verify', ['--batch' => 2])->assertSuccessful();
    $rows = $this->orgContext()->run($this->org->id, fn () => DB::table('app.receipt_files')->get(['path', 'checked_path', 'checked_status'])->all());
    foreach ($rows as $row) {
        expect($row->checked_status)->toBe('ok')->and($row->checked_path)->toBe($row->path);
    }
});

it('il controllo leggero riconosce la dimensione di un file cifrato di ogni dimensione', function (int $size): void {
    $files = app(EncryptedFiles::class);
    $path = (string) tempnam(sys_get_temp_dir(), 'as');
    file_put_contents($path, $size === 0 ? '' : random_bytes($size));
    $stored = $files->store($this->org->id, $path, 'a.bin', 'application/octet-stream');

    expect($files->quickCheck($this->org->id, $stored))->toBe('ok');
    Storage::disk('org_files')->append($stored->path, 'x', '');
    expect($files->quickCheck($this->org->id, $stored))->toBe('altered');
    Storage::disk('org_files')->delete($stored->path);
    expect($files->quickCheck($this->org->id, $stored))->toBe('missing');
})->with([0, 10, 65536, 65537, 300000]);

it('la verifica completa delle ricevute gira ogni notte alle 02:23', function (): void {
    $events = array_values(array_filter(
        app(Schedule::class)->events(),
        static fn ($event): bool => str_contains((string) $event->command, 'saas:receipts:verify'),
    ));

    expect($events)->toHaveCount(1)->and($events[0]->expression)->toBe('23 2 * * *');
});
