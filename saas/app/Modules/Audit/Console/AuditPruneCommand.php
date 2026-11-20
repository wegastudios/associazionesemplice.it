<?php

declare(strict_types=1);

namespace App\Modules\Audit\Console;

use App\Modules\Core\Contracts\DatabaseNames;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Tiene del registro delle azioni l'anno corrente e il precedente (docs/CANCELLAZIONE_DATI.md §5). Prima di cancellare
 * le partizioni mensili più vecchie salva per ogni associazione l'ultimo numero e l'ultimo sigillo (audit.anchors):
 * la verifica della catena parte da lì. Solo sul database condiviso; quelli dedicati si trattano a parte.
 */
#[AsCommand(name: 'saas:audit:prune')]
final class AuditPruneCommand extends Command
{
    protected $signature = 'saas:audit:prune {--before= : Data (AAAA-MM-GG) prima della quale cancellare; vuoto = 1 gennaio dell\'anno precedente} {--dry-run : Elenca soltanto} {--force : Senza conferma (uso non interattivo con --before)}';

    protected $description = 'Cancella le partizioni del registro più vecchie dell\'anno precedente, lasciando l\'ancora della catena';

    public function handle(DatabaseNames $databases, DatabaseManager $db): int
    {
        $before = $this->option('before');
        $cutoff = is_string($before) && $before !== ''
            ? CarbonImmutable::parse($before, 'UTC')->startOfDay()
            : CarbonImmutable::now('UTC')->subYear()->startOfYear();

        $owner = $db->connection($databases->ownerConnection(null));
        $old = [];
        foreach ($owner->select("SELECT c.relname AS name FROM pg_class c JOIN pg_inherits i ON i.inhrelid = c.oid JOIN pg_class p ON p.oid = i.inhparent JOIN pg_namespace n ON n.oid = p.relnamespace WHERE n.nspname = 'audit' AND p.relname = 'log' AND c.relname ~ '^log_[0-9]{4}_[0-9]{2}$' ORDER BY 1") as $row) {
            $name = is_object($row) && is_string($row->name ?? null) ? $row->name : '';
            if (preg_match('/^log_(\d{4})_(\d{2})$/', $name, $m) === 1 && (new CarbonImmutable("{$m[1]}-{$m[2]}-01 00:00:00", 'UTC'))->addMonth()->lte($cutoff)) {
                $old[] = $name;
            }
        }
        $this->info('Partizioni da cancellare: '.count($old).($old === [] ? '' : ' ('.implode(', ', $old).')'));
        if ($old === [] || $this->option('dry-run')) {
            return self::SUCCESS;
        }

        // Il limite di conservazione (anno corrente e precedente) non si accorcia nemmeno a mano.
        $legal = CarbonImmutable::now('UTC')->subYear()->startOfYear();
        if ($cutoff->gt($legal)) {
            $this->error('La data è più recente del limite di conservazione ('.$legal->format('d/m/Y').'): non si cancella.');

            return self::FAILURE;
        }

        // Con una data scelta a mano si può cancellare tutto il registro: serve una conferma esplicita (o --force).
        // Il lavoro pianificato usa la data di legge, senza opzioni, e non la chiede.
        if (is_string($before) && $before !== '' && $this->option('force') !== true) {
            if (! $this->input->isInteractive()) {
                $this->error('Con --before la cancellazione chiede conferma: per l\'uso non interattivo aggiungi --force.');

                return self::FAILURE;
            }
            $this->warn('Si cancellano per sempre le partizioni del registro delle azioni prima del '.$cutoff->format('d/m/Y').': '.count($old).' ('.implode(', ', $old).'). Restano solo ultimo numero e sigillo di ogni associazione.');
            if (! $this->confirm('Cancellare queste partizioni?', false)) {
                $this->line('Niente cancellato.');

                return self::FAILURE;
            }
        }

        // Prima le ancore, una associazione per volta (la RLS vale anche per il proprietario), poi le partizioni.
        $orgIds = $db->connection('pgsql')->table('core.orgs')->whereNull('database')->pluck('id')->all();
        foreach ($orgIds as $orgId) {
            if (! is_string($orgId)) {
                continue;
            }
            $owner->transaction(function () use ($owner, $orgId, $old): void {
                $owner->select("SELECT set_config('app.org_id', ?, true)", [$orgId]);
                $union = implode(' UNION ALL ', array_map(static fn (string $p): string => "SELECT org_id, seq, hash FROM audit.{$p} WHERE org_id = ?", $old));
                $owner->statement(
                    "INSERT INTO audit.anchors (org_id, seq, hash) SELECT org_id, seq, hash FROM ({$union}) AS t ORDER BY seq DESC LIMIT 1 ON CONFLICT (org_id) DO UPDATE SET seq = EXCLUDED.seq, hash = EXCLUDED.hash, anchored_at = now() WHERE audit.anchors.seq < EXCLUDED.seq",
                    array_fill(0, count($old), $orgId),
                );
            });
        }
        foreach ($old as $partition) {
            $owner->statement("DROP TABLE audit.{$partition}");
            $this->line("cancellata {$partition}");
        }

        return self::SUCCESS;
    }
}
