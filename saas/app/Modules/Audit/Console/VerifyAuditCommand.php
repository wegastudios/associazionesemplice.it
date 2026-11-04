<?php

declare(strict_types=1);

namespace App\Modules\Audit\Console;

use App\Modules\Audit\Log\ChainVerifier;
use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use Symfony\Component\Console\Attribute\AsCommand;

/** Verifica la catena del registro: di un'associazione o di tutte. Esce con errore se una catena è rotta. */
#[AsCommand(name: 'saas:audit:verify')]
final class VerifyAuditCommand extends Command
{
    protected $signature = 'saas:audit:verify {org? : Identificativo dell\'associazione (vuoto = tutte)}';

    protected $description = 'Verifica che il registro delle azioni non sia stato alterato';

    public function handle(ChainVerifier $verifier, DatabaseManager $db): int
    {
        $org = $this->argument('org');
        $ids = is_string($org)
            ? [$org]
            : $db->connection('pgsql')->table('core.orgs')->whereNull('deleted_at')->orderBy('id')->pluck('id')->all();

        $broken = 0;
        foreach ($ids as $id) {
            if (! is_string($id)) {
                continue;
            }
            $result = $verifier->verify($id);
            if ($result['ok']) {
                $this->line("ok {$id}: {$result['checked']} righe");
            } else {
                $broken++;
                $this->error("ROTTA {$id}: {$result['reason']} (dopo {$result['checked']} righe valide)");
            }
        }

        return $broken === 0 ? self::SUCCESS : self::FAILURE;
    }
}
