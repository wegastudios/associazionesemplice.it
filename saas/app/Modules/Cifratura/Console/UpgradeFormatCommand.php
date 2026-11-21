<?php

declare(strict_types=1);

namespace App\Modules\Cifratura\Console;

use App\Modules\Cifratura\Keys\KeyAudit;
use App\Modules\Cifratura\Upgrade\FormatReport;
use App\Modules\Cifratura\Upgrade\FormatUpgradeFailed;
use App\Modules\Cifratura\Upgrade\FormatUpgrader;
use App\Modules\Core\Contracts\OrgContext;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

/**
 * Porta al formato legato alla riga (0x02) i campi cifrati di app.people e delle impostazioni di pagamento ancora in
 * 0x01. Una associazione per volta, ognuna in una transazione sua con le righe bloccate: se un campo non si decifra
 * quella associazione non cambia, le altre vanno avanti e l'esito è un errore. Ripetibile (le righe già 0x02 si
 * saltano). Prima di sostituire un campo ne salva il vecchio testo cifrato in app.crypt_format_backups (30 giorni:
 * saas:crypt:upgrade-format:undo lo rimette). Lavora con la connessione dell'applicazione, soggetta alla Row-Level
 * Security, dentro il contesto di ogni associazione. Non stampa mai valori, in chiaro o cifrati: solo conteggi.
 */
#[AsCommand(name: 'saas:crypt:upgrade-format')]
final class UpgradeFormatCommand extends Command
{
    use SelectsOrgs;

    protected $signature = 'saas:crypt:upgrade-format
        {--org= : Solo questa associazione (identificativo)}
        {--dry-run : Conta e verifica senza scrivere nulla}';

    protected $description = 'Porta i campi cifrati dei soci e dei pagamenti dal formato 0x01 al formato legato alla riga 0x02';

    public function handle(FormatUpgrader $upgrader, OrgContext $context, KeyAudit $platform): int
    {
        $dryRun = $this->option('dry-run') === true;
        $ids = $this->selectedOrgs($upgrader);
        if ($ids === null) {
            return self::FAILURE;
        }

        $this->line(($dryRun ? 'Prova, nulla viene scritto. ' : '').'Associazioni: '.count($ids));

        $totals = ['orgs' => count($ids), 'rows_converted' => 0, 'fields_converted' => 0, 'rows_already' => 0, 'rows_unreadable' => 0, 'orgs_failed' => 0];
        foreach ($ids as $id) {
            $this->line("associazione {$id}");
            try {
                $report = $context->run($id, static fn (): FormatReport => $upgrader->upgrade($dryRun));
            } catch (FormatUpgradeFailed $e) {
                $report = $e->report;
                $this->error('  non si decifrano alcuni campi: nessuna modifica per questa associazione.');
                $this->recordFailure($upgrader, $context, $id, $report);
                $totals['orgs_failed']++;
            } catch (Throwable $e) {
                // Del messaggio dell'errore non si stampa niente: potrebbe contenere dati.
                $this->error('  errore ('.$e::class.'): nessuna modifica per questa associazione.');
                $totals['orgs_failed']++;

                continue;
            }

            foreach ($report->tables as $table => $count) {
                $this->line("  {$table}: righe {$count->rows}, da convertire {$count->convertRows} (campi {$count->convertFields}), già 0x02 {$count->alreadyRows}, che non si decifrano {$count->unreadableRows}");
                foreach ($count->unreadable as $where) {
                    $this->error("    non si decifra: {$where}");
                }
            }
            $this->line("  da salvare in appoggio: {$report->convertFields()} campi");

            $totals['rows_converted'] += $report->convertRows();
            $totals['fields_converted'] += $report->convertFields();
            $totals['rows_already'] += $report->alreadyRows();
            $totals['rows_unreadable'] += $report->unreadableRows();
            if ($dryRun && $report->unreadableRows() > 0) {
                $totals['orgs_failed']++;
            }
        }

        $this->newLine();
        $this->line("Totale: righe da convertire {$totals['rows_converted']} (campi {$totals['fields_converted']}), già 0x02 {$totals['rows_already']}, che non si decifrano {$totals['rows_unreadable']}, associazioni con problemi {$totals['orgs_failed']}");

        if (! $dryRun) {
            $platform->record(KeyAudit::FORMAT_UPGRADE, $totals, 'formato dei campi cifrati');
        }

        if ($totals['orgs_failed'] > 0) {
            $this->error('Alcune associazioni non sono passate: nessuna loro riga è stata toccata. Risolvi e ripeti il comando.');

            return self::FAILURE;
        }
        if (! $dryRun) {
            $this->line('Fatto. Le copie d\'appoggio restano 30 giorni (saas:crypt:upgrade-format:undo le rimette).');
        }

        return self::SUCCESS;
    }

    /** Dopo l'annullamento, una riga nel registro dell'associazione (conteggi, mai dati). */
    private function recordFailure(FormatUpgrader $upgrader, OrgContext $context, string $id, FormatReport $report): void
    {
        try {
            $context->run($id, static function () use ($upgrader, $report): void {
                $upgrader->recordFailure($report);
            });
        } catch (Throwable) {
            $this->error('  (non è stato possibile scrivere la riga nel registro)');
        }
    }
}
