<?php

declare(strict_types=1);

use App\Modules\Contabilita\Contracts\BalanceCheck;
use Illuminate\Support\Facades\DB;

// «ignora-saldi» del rilascio: saas:health --ignore-balances rende un avviso SOLO la voce «saldi dei conti».

beforeEach(function (): void {
    // Ultimo controllo notturno con un conto diverso dai movimenti.
    app()->instance(BalanceCheck::class, new class implements BalanceCheck
    {
        public function lastResult(): ?array
        {
            return ['at' => now()->getTimestamp(), 'orgs' => 3, 'mismatches' => 1, 'failed' => 0];
        }
    });
});

it('senza l\'opzione un saldo diverso è un errore', function (): void {
    $this->artisan('saas:health', ['--skip-migrations' => true])->assertFailed()
        ->expectsOutputToContain('ERRORE saldi dei conti: 1 conti con il saldo diverso dai movimenti');
});

it('con l\'opzione il saldo diverso è solo un avviso e il controllo passa', function (): void {
    $this->artisan('saas:health', ['--skip-migrations' => true, '--ignore-balances' => true])->assertSuccessful()
        ->expectsOutputToContain('AVVISO saldi dei conti: 1 conti con il saldo diverso dai movimenti')
        ->doesntExpectOutputToContain('ERRORE saldi dei conti');
});

it('con l\'opzione gli altri errori restano errori', function (): void {
    config(['saas.mail_queue.primary' => 'sconosciuto']);

    $this->artisan('saas:health', ['--skip-migrations' => true, '--ignore-balances' => true])->assertFailed()
        ->expectsOutputToContain('ERRORE configurazione di produzione')
        ->expectsOutputToContain('AVVISO saldi dei conti');
});

it('annota nel registro dell\'amministrazione chi ha lanciato il rilascio con ignora-saldi', function (): void {
    $this->artisan('saas:deploy:ignore-balances', ['actor' => 'wega-dev', 'revision' => 'abc1234'])->assertSuccessful();

    $row = DB::table('core.platform_audit')->where('action', 'deploy.balances_check_ignored')->sole();
    expect(json_decode((string) $row->details, true))->toEqual(['actor' => 'wega-dev', 'revision' => 'abc1234'])
        ->and($row->staff_id)->toBeNull();
});

it('rifiuta un utente del rilascio non valido e non annota nulla', function (): void {
    $this->artisan('saas:deploy:ignore-balances', ['actor' => 'x; rm -rf /'])->assertFailed();

    expect(DB::table('core.platform_audit')->where('action', 'deploy.balances_check_ignored')->count())->toBe(0);
});
