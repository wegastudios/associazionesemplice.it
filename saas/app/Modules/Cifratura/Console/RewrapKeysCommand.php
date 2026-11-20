<?php

declare(strict_types=1);

namespace App\Modules\Cifratura\Console;

use App\Modules\Cifratura\Keys\KeyAudit;
use App\Modules\Cifratura\Keys\KeyMigration;
use App\Modules\Cifratura\Keys\VaultFactory;
use Illuminate\Console\Command;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

/**
 * Riavvolge le chiavi dati di tutte le associazioni dalla chiave principale locale al servizio chiavi («remote») o
 * viceversa («local», il ritorno). Servono entrambe le configurazioni nel file d'ambiente. Ripetibile; con --dry-run
 * prova tutto senza scrivere. Registra una riga nel registro della piattaforma; non stampa segreti.
 */
#[AsCommand(name: 'saas:keys:rewrap')]
final class RewrapKeysCommand extends Command
{
    protected $signature = 'saas:keys:rewrap
        {verso : remote (dalla chiave locale al servizio chiavi) oppure local (ritorno alla chiave locale)}
        {--dry-run : Prova tutto (anche il riavvolgimento) senza scrivere nel database}
        {--force : Senza conferma}';

    protected $description = 'Riavvolge le chiavi dati delle associazioni verso il servizio chiavi o verso la chiave locale';

    public function handle(VaultFactory $vaults, KeyMigration $migration, KeyAudit $audit): int
    {
        $to = $this->argument('verso');
        if ($to !== 'remote' && $to !== 'local') {
            $this->error('Il verso è remote oppure local.');

            return self::FAILURE;
        }
        $dryRun = $this->option('dry-run') === true;

        try {
            [$from, $target] = $to === 'remote' ? [$vaults->local(), $vaults->remote()] : [$vaults->remote(), $vaults->local()];
            KeyMigration::probe($target);
        } catch (Throwable $e) {
            $this->error('Custodi della chiave non pronti: '.($e instanceof RuntimeException ? $e->getMessage() : 'errore imprevisto.'));

            return self::FAILURE;
        }

        $label = $to === 'remote' ? 'il servizio chiavi' : 'la chiave principale locale';
        if (! $dryRun && $this->option('force') !== true
            && ! $this->confirm("Riavvolgere le chiavi dati di tutte le associazioni verso {$label}? (Prima: php artisan down)")) {
            return self::FAILURE;
        }

        $result = $migration->rewrap($from, $target, $dryRun);
        $failed = count($result['failed']);

        $this->line(($dryRun ? 'Prova, nulla è stato scritto. ' : '')."Verso {$label}:");
        $this->line("  associazioni: {$result['orgs']}");
        $this->line('  chiavi '.($dryRun ? 'da riavvolgere' : 'riavvolte').": {$result['rewrapped']}");
        $this->line("  già a posto: {$result['already']}");
        $this->line("  associazioni non riuscite: {$failed}");
        foreach ($result['failed'] as $orgId) {
            $this->error("  non riuscita: {$orgId} (nessuna modifica per questa associazione)");
        }

        if (! $dryRun) {
            $audit->record(KeyAudit::REWRAP, [
                'to' => $to,
                'orgs' => $result['orgs'],
                'rewrapped' => $result['rewrapped'],
                'already' => $result['already'],
                'failed' => $failed,
                'dry_run' => false,
            ]);
        }

        if ($failed > 0) {
            $this->error('Alcune associazioni non sono passate: ripeti il comando quando il problema è risolto.');

            return self::FAILURE;
        }

        if (! $dryRun) {
            $this->newLine();
            $this->line($to === 'remote'
                ? 'Ora: SAAS_KEY_VAULT=remote nel file d\'ambiente, php artisan config:cache, di nuovo questo comando (deve dire 0 riavvolte), php artisan up.'
                : 'Ora: SAAS_KEY_VAULT=local nel file d\'ambiente, php artisan config:cache, di nuovo questo comando (deve dire 0 riavvolte), php artisan up.');
        }

        return self::SUCCESS;
    }
}
