<?php

declare(strict_types=1);

namespace App\Modules\Cifratura\Console;

use App\Modules\Cifratura\Contracts\EncryptedFiles;
use App\Modules\Core\Contracts\OrgDirectory;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

/**
 * Ogni notte riconta dal disco lo spazio dei file di ogni associazione attiva e riallinea il contatore che usa il
 * controllo della quota. Una associazione in errore non ferma le altre.
 */
#[AsCommand(name: 'saas:files:usage')]
final class RecountFileUsageCommand extends Command
{
    protected $signature = 'saas:files:usage';

    protected $description = 'Riallinea al disco il contatore dello spazio dei file di ogni associazione';

    public function handle(OrgDirectory $directory, EncryptedFiles $files): int
    {
        $failed = 0;
        foreach ($directory->activeIds() as $orgId) {
            try {
                $files->recountUsedBytes($orgId);
            } catch (Throwable $e) {
                $failed++;
                report($e);
                $this->error("{$orgId}: errore");
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
