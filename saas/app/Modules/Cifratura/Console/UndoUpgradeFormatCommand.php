<?php

declare(strict_types=1);

namespace App\Modules\Cifratura\Console;

use App\Modules\Cifratura\Keys\KeyAudit;
use App\Modules\Cifratura\Upgrade\FormatUpgrader;
use App\Modules\Core\Contracts\OrgContext;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

/**
 * Comando inverso di saas:crypt:upgrade-format: rimette i vecchi testi cifrati (0x01) dalle copie d'appoggio, ma solo
 * dove la riga è ancora quella scritta dal passaggio; se il campo è stato modificato dopo, lo salta e lo dice. Una
 * associazione per volta, in una transazione sua. Non stampa mai valori: solo conteggi.
 */
#[AsCommand(name: 'saas:crypt:upgrade-format:undo')]
final class UndoUpgradeFormatCommand extends Command
{
    use SelectsOrgs;

    protected $signature = 'saas:crypt:upgrade-format:undo
        {--org= : Solo questa associazione (identificativo)}
        {--dry-run : Conta senza scrivere nulla}';

    protected $description = 'Rimette i campi cifrati al formato 0x01 dalle copie d\'appoggio (finché esistono)';

    public function handle(FormatUpgrader $upgrader, OrgContext $context, KeyAudit $platform): int
    {
        $dryRun = $this->option('dry-run') === true;
        $ids = $this->selectedOrgs($upgrader);
        if ($ids === null) {
            return self::FAILURE;
        }

        $this->line(($dryRun ? 'Prova, nulla viene scritto. ' : '').'Associazioni: '.count($ids));

        $restored = 0;
        $skipped = 0;
        $failed = 0;
        foreach ($ids as $id) {
            try {
                $result = $context->run($id, static fn (): array => $upgrader->undo($dryRun));
            } catch (Throwable $e) {
                $this->error("associazione {$id}: errore (".$e::class.'), nessuna modifica per questa associazione.');
                $failed++;

                continue;
            }

            $this->line("associazione {$id}");
            foreach ($result as $table => $count) {
                $this->line("  {$table}: da rimettere {$count['restore']}, saltati perché cambiati dopo il passaggio {$count['changed']}");
                $restored += $count['restore'];
                $skipped += $count['changed'];
            }
        }

        $this->newLine();
        $this->line("Totale: campi da rimettere {$restored}, saltati (cambiati dopo) {$skipped}, associazioni con errore {$failed}");

        if (! $dryRun) {
            $platform->record(KeyAudit::FORMAT_UNDO, ['orgs' => count($ids), 'fields_restored' => $restored, 'fields_skipped' => $skipped, 'orgs_failed' => $failed], 'formato dei campi cifrati');
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
