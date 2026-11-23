<?php

declare(strict_types=1);

use App\Modules\Contabilita\Contracts\CategoryKind;
use App\Modules\Contabilita\Contracts\Income;
use App\Modules\Contabilita\Contracts\IncomeLine;
use App\Modules\Contabilita\Contracts\Ledger;
use App\Modules\Contabilita\Models\Account;
use App\Modules\Contabilita\Models\Category;
use App\Modules\Core\Database\DatabaseRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

// Controllo notturno dei saldi. L'associazione sta nel database dedicato di prova, che non è nella transazione del test:
// così il ruolo dei backup (un'altra connessione) vede i suoi movimenti. Dati di prova inventati.

beforeEach(function (): void {
    $this->useDedicatedDatabase();
    $this->dir = sys_get_temp_dir().'/saldi-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($this->dir);
    config(['saas.ops_dir' => $this->dir]);
    $this->org = $this->createOrg('Fondazione Gamma', $this::DEDICATED);
    $this->account = $this->orgContext()->run($this->org->id, function (): string {
        $cash = Account::query()->where('type', 'cash')->sole()->id;
        $donation = Category::query()->where('kind', CategoryKind::Donation)->sole()->id;
        $ledger = app(Ledger::class);
        $ledger->recordIncome(new Income(now()->toDateString(), $cash, [new IncomeLine($donation, 5000)], null, null, false));
        $gone = $ledger->recordIncome(new Income(now()->toDateString(), $cash, [new IncomeLine($donation, 700)], null, null, false));
        $ledger->void($gone['transactions'][0], 'Errore');

        return $cash;
    });
});

afterEach(function (): void {
    File::deleteDirectory($this->dir);
});

it('con i saldi coerenti con i movimenti non scrive file e il controllo di salute è a posto', function (): void {
    Log::spy();

    $this->artisan('saas:balances:verify')->assertSuccessful();

    expect(glob($this->dir.'/saldi-*.txt'))->toBe([]);
    Log::shouldNotHaveReceived('error');
    $this->artisan('saas:health', ['--skip-migrations' => true])->expectsOutputToContain('ok    saldi dei conti');
});

it('con un saldo alterato a mano dà errore: il conto solo nel file 600, nel log solo il conteggio, niente corretto', function (): void {
    $owner = DB::connection(app(DatabaseRegistry::class)->ownerConnection($this::DEDICATED));
    $owner->transaction(function () use ($owner): void {
        $owner->select("SELECT set_config('app.org_id', ?, true)", [$this->org->id]);
        $owner->update('UPDATE app.account_balances SET balance_cents = balance_cents + 1 WHERE org_id = ? AND account_id = ?', [$this->org->id, $this->account]);
    });
    Log::spy();

    $this->artisan('saas:balances:verify')->assertFailed();

    $files = glob($this->dir.'/saldi-*.txt') ?: [];
    expect($files)->toHaveCount(1)
        ->and(substr(sprintf('%o', fileperms($files[0])), -3))->toBe('600')
        ->and((string) file_get_contents($files[0]))->toContain($this->org->id)->toContain($this->account)->toContain('saldo 5001 atteso 5000');
    Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context): bool => $context === ['conti' => 1])->once();
    $saldo = $owner->transaction(function () use ($owner): mixed {
        $owner->select("SELECT set_config('app.org_id', ?, true)", [$this->org->id]);

        return $owner->scalar('SELECT balance_cents FROM app.account_balances WHERE org_id = ? AND account_id = ?', [$this->org->id, $this->account]);
    });
    expect((int) $saldo)->toBe(5001);

    $this->artisan('saas:health', ['--skip-migrations' => true])->assertFailed()
        ->expectsOutputToContain('ERRORE saldi dei conti: 1 conti con il saldo diverso dai movimenti');
});
