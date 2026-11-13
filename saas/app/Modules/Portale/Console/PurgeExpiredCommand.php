<?php

declare(strict_types=1);

namespace App\Modules\Portale\Console;

use App\Modules\Core\Contracts\OrgPurger;
use App\Modules\Portale\PortalConfig;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Cancella i dati delle associazioni scadute da più dei giorni di conservazione (180 dalla scadenza; 7 dopo la fine
 * della prova se non hanno mai scelto un piano). Docs: CANCELLAZIONE_DATI.md. Non parte se i candidati di una notte
 * sono più del massimo: un errore di data non deve cancellare tutto.
 */
#[AsCommand(name: 'saas:portal:purge-expired')]
final class PurgeExpiredCommand extends Command
{
    protected $signature = 'saas:portal:purge-expired {--dry-run : Elenca soltanto, non cancella}';

    protected $description = 'Cancella i dati delle associazioni scadute da più dei giorni di conservazione';

    public function handle(DatabaseManager $db, PortalConfig $config, OrgPurger $purger): int
    {
        $dry = (bool) $this->option('dry-run');
        if (! $dry && ! $config->purgeEnabled()) {
            $this->warn('La cancellazione è spenta (saas.portal.purge_enabled): niente da fare. Con --dry-run si vede l\'elenco.');

            return self::SUCCESS;
        }

        $ids = $this->candidates($db, CarbonImmutable::today(), $config);
        $this->info('Da cancellare: '.count($ids));
        if ($ids === []) {
            return self::SUCCESS;
        }
        if (count($ids) > $config->purgeMaxPerNight()) {
            $this->error('Troppe associazioni in una notte ('.count($ids).', massimo '.$config->purgeMaxPerNight().'): non si cancella niente, controlla le date.');
            $this->audit($db, 'org.purge_stopped', null, null, ['candidates' => count($ids)]);

            return self::FAILURE;
        }
        if ($dry) {
            foreach ($ids as $id) {
                $this->line($id);
            }

            return self::SUCCESS;
        }

        $failed = 0;
        foreach ($ids as $id) {
            try {
                $done = $purger->purge($id);
                $this->audit($db, 'org.purged', null, $id, ['rows' => $done['rows'], 'users' => $done['users']]);
                $this->info("Cancellata {$id}: righe {$done['rows']}, utenti {$done['users']}.");
            } catch (RuntimeException $e) {
                $failed++;
                $this->audit($db, 'org.purge_failed', null, $id, ['reason' => mb_substr($e->getMessage(), 0, 200)]);
                $this->error("Non cancellata {$id}: {$e->getMessage()}");
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Registro dello staff: solo identificatore e passo, mai dati dei soci.
     *
     * @param  array<string, mixed>  $details
     */
    private function audit(DatabaseManager $db, string $action, ?string $staffId, ?string $subject, array $details): void
    {
        $db->connection('pgsql')->table('core.platform_audit')->insert([
            'id' => (string) Str::uuid7(),
            'staff_id' => $staffId,
            'action' => $action,
            'subject' => $subject,
            'details' => json_encode($details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'ip' => null,
            'created_at' => now(),
        ]);
    }

    /** @return list<string> */
    private function candidates(DatabaseManager $db, CarbonImmutable $today, PortalConfig $config): array
    {
        $rows = $db->connection('pgsql')->table('core.org_entitlements as e')
            ->join('core.orgs as o', 'o.id', '=', 'e.org_id')
            ->whereNull('o.deleted_at')
            ->whereIn('o.status', ['suspended', 'closed'])
            ->whereNotNull('e.until')
            ->get(['e.org_id', 'e.status', 'e.until']);

        $ids = [];
        foreach ($rows as $row) {
            if (! is_string($row->org_id) || ! is_string($row->until)) {
                continue;
            }
            $days = $row->status === 'trial' ? $config->trialPurgeDays() : $config->retentionDays();
            if (CarbonImmutable::parse($row->until)->addDays($days)->lt($today)) {
                $ids[] = $row->org_id;
            }
        }

        return $ids;
    }
}
