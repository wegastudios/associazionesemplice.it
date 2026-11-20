<?php

declare(strict_types=1);

namespace App\Modules\Audit\Console;

use App\Modules\Audit\Log\HeadCopies;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

/** Copia fuori dal database numero e sigillo di testa del registro di ogni associazione (usati poi da saas:audit:verify). */
#[AsCommand(name: 'saas:audit:heads:copy')]
final class AuditHeadsCopyCommand extends Command
{
    protected $signature = 'saas:audit:heads:copy';

    protected $description = 'Salva nello spazio dei backup la testa del registro delle azioni di ogni associazione';

    public function handle(HeadCopies $copies): int
    {
        try {
            $result = $copies->export(CarbonImmutable::now());
        } catch (Throwable $e) {
            $this->error('Copia della testa del registro NON riuscita: '.$e->getMessage());
            report($e);

            return self::FAILURE;
        }

        $this->info("Copiate le teste di {$result['orgs']} associazioni: {$result['path']}".($result['removed'] > 0 ? " (tolte {$result['removed']} copie vecchie)" : ''));

        return self::SUCCESS;
    }
}
