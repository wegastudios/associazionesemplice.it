<?php

declare(strict_types=1);

namespace App\Modules\Contabilita\Console;

use App\Modules\Cifratura\Contracts\OrgBatch;
use App\Modules\Contabilita\Receipts\ReceiptFiles;
use App\Modules\Core\Contracts\OrgContext;
use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

/** Fissa a lotti il PDF delle ricevute emesse prima delle ricevute definitive: un lotto per associazione a ogni giro. */
#[AsCommand(name: 'saas:receipts:fix')]
final class FixReceiptsCommand extends Command
{
    protected $signature = 'saas:receipts:fix {org? : Identificativo dell\'associazione (vuoto = tutte)} {--batch=100 : Ricevute per associazione}';

    protected $description = 'Fissa il PDF delle ricevute vecchie, a lotti';

    public function handle(OrgContext $context, ReceiptFiles $files, DatabaseManager $db, OrgBatch $pace): int
    {
        $org = $this->argument('org');
        $ids = is_string($org)
            ? [$org]
            : $db->connection('pgsql')->table('core.orgs')->where('status', 'active')->whereNull('deleted_at')->orderBy('id')->pluck('id')->all();
        $batch = max(1, (int) $this->option('batch'));

        $failed = 0;
        foreach ($pace->paced($ids) as $id) {
            if (! is_string($id)) {
                continue;
            }
            try {
                $r = $context->run($id, static fn (): array => $files->fixNext($batch));
                if ($r['fixed'] > 0 || $r['left'] > 0) {
                    $this->line("{$id}: {$r['fixed']} fissate, {$r['left']} da fissare");
                }
            } catch (Throwable $e) {
                $failed++;
                $this->error("{$id}: {$e->getMessage()}");
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
