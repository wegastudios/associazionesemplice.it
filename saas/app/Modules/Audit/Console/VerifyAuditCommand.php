<?php

declare(strict_types=1);

namespace App\Modules\Audit\Console;

use App\Modules\Audit\Log\ChainVerifier;
use App\Modules\Audit\Log\HeadCopies;
use App\Modules\Cifratura\Contracts\OrgBatch;
use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;

/** Verifica la catena del registro: di un'associazione o di tutte. Esce con errore se una catena è rotta. */
#[AsCommand(name: 'saas:audit:verify')]
final class VerifyAuditCommand extends Command
{
    /** Giorni dopo i quali una copia esterna ferma è un errore. */
    private const COPY_MAX_DAYS = 3;

    protected $signature = 'saas:audit:verify {org? : Identificativo dell\'associazione (vuoto = tutte)} {--full : Ripercorre tutta la catena invece di ripartire dal punto di controllo} {--rotate : Dal punto di controllo, ma un settimo delle associazioni a notte ripercorre tutta la catena}';

    protected $description = 'Verifica che il registro delle azioni non sia stato alterato';

    public function handle(ChainVerifier $verifier, DatabaseManager $db, HeadCopies $copies, OrgBatch $pace): int
    {
        $org = $this->argument('org');
        $ids = is_string($org)
            ? [$org]
            : $db->connection('pgsql')->table('core.orgs')->whereNull('deleted_at')->orderBy('id')->pluck('id')->all();

        $broken = 0;
        foreach ($pace->paced($ids) as $id) {
            if (! is_string($id)) {
                continue;
            }
            $full = $this->option('full') === true || ($this->option('rotate') === true && self::dayOf($id) === now()->dayOfWeek);
            $result = $verifier->verify($id, ! $full);
            if ($result['ok']) {
                $this->line("ok {$id}: {$result['checked']} righe".($full ? ' (catena intera)' : ''));
            } else {
                $broken++;
                $this->error("ROTTA {$id}: {$result['reason']} (dopo {$result['checked']} righe valide)");
            }
        }

        // La copia esterna delle teste è ciò che rende visibile una coda tagliata: se si ferma, la verifica lo dice.
        try {
            $copy = $copies->latest();
        } catch (RuntimeException) {
            $this->error('La copia esterna delle teste del registro non è autentica.');
            $broken++;
            $copy = null;
        }
        if ($copy === null) {
            $this->warn('Nessuna copia esterna delle teste del registro: la coda tagliata non si vede (saas:audit:heads:copy).');
        } elseif ($copy['at']->lt(now()->subDays(self::COPY_MAX_DAYS))) {
            $this->error('L\'ultima copia esterna delle teste del registro è del '.$copy['at']->format('d/m/Y').': la copia giornaliera si è fermata.');
            $broken++;
        }

        return $broken === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** Giorno della settimana (0 = domenica) in cui l'associazione fa la verifica dell'intera catena: un settimo per notte. */
    public static function dayOf(string $orgId): int
    {
        return crc32($orgId) % 7;
    }
}
