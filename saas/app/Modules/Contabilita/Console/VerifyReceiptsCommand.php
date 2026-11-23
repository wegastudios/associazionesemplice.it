<?php

declare(strict_types=1);

namespace App\Modules\Contabilita\Console;

use App\Modules\Cifratura\Contracts\OrgBatch;
use App\Modules\Contabilita\Receipts\ReceiptFiles;
use App\Modules\Core\Contracts\OrgContext;
use App\Modules\Core\Contracts\OrgDirectory;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

/**
 * Verifica completa dei PDF delle ricevute, a blocchi (ReceiptFiles::verifyNext): ogni notte un blocco per associazione,
 * prima le ricevute mai verificate. «Verifica delle ricevute» mostra l'esito senza rileggere i file.
 */
#[AsCommand(name: 'saas:receipts:verify')]
final class VerifyReceiptsCommand extends Command
{
    protected $signature = 'saas:receipts:verify {--batch='.ReceiptFiles::VERIFY_BATCH.' : Ricevute per associazione}';

    protected $description = 'Verifica completa dei PDF delle ricevute, a blocchi';

    public function handle(OrgDirectory $directory, OrgContext $context, ReceiptFiles $files, OrgBatch $pace): int
    {
        $batch = max(1, (int) $this->option('batch'));
        $failed = 0;
        foreach ($pace->paced($directory->activeIds()) as $orgId) {
            try {
                $r = $context->run($orgId, static fn (): array => $files->verifyNext($batch));
            } catch (Throwable $e) {
                $failed++;
                report($e);
                $this->error("{$orgId}: errore");

                continue;
            }
            if ($r['missing'] > 0 || $r['altered'] > 0) {
                $this->line("{$orgId}: {$r['checked']} verificate, {$r['missing']} mancanti, {$r['altered']} alterate");
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
