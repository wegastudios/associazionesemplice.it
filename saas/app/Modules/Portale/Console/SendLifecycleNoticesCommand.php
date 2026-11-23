<?php

declare(strict_types=1);

namespace App\Modules\Portale\Console;

use App\Modules\Portale\Jobs\SendLifecycleNotice;
use App\Modules\Portale\Notifications\OrgLifecycleNotification;
use App\Modules\Portale\PortalConfig;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Avvisi su dati e accesso ai titolari e agli amministratori (docs/CANCELLAZIONE_DATI.md §2 e §8): fine della prova,
 * sospensione, promemoria prima dell'eliminazione dei dati. Ogni avviso parte una
 * sola volta per scadenza (core.org_notices); i giorni sono a finestre, così un giorno saltato non perde un avviso.
 */
#[AsCommand(name: 'saas:portal:lifecycle-notices')]
final class SendLifecycleNoticesCommand extends Command
{
    protected $signature = 'saas:portal:lifecycle-notices';

    protected $description = 'Invia gli avvisi su fine della prova, sospensione ed eliminazione dei dati';

    /**
     * Avvisi che manda il gestionale (dati e accesso). Quelli sui soldi (scadenza vicina, rinnovo, pagamento non riuscito,
     * disdetta) li manda il portale: ogni evento ha un solo mittente, così nessun avviso parte due volte.
     */
    public const KINDS = ['trial_7', 'trial_2', 'trial_0', 'trial_3', 'trial_6', 'suspended', 'purge_30', 'purge_90', 'purge_150', 'purge_170', 'purge_1'];

    public function handle(DatabaseManager $db, PortalConfig $config): int
    {
        $today = CarbonImmutable::today();
        $sent = 0;

        $rows = $db->connection('pgsql')->table('core.org_entitlements as e')
            ->join('core.orgs as o', 'o.id', '=', 'e.org_id')
            ->whereNull('o.deleted_at')->whereNotNull('e.until')
            ->get(['e.org_id', 'o.name', 'o.status as org_status', 'e.status', 'e.until']);

        foreach ($rows as $row) {
            if (! is_string($row->org_id) || ! is_string($row->name) || ! is_string($row->until) || ! is_string($row->status) || ! is_string($row->org_status)) {
                continue;
            }
            $until = CarbonImmutable::parse($row->until);
            $offset = (int) $until->diffInDays($today, false); // negativo = la scadenza è futura
            $notice = self::notice($row->status, $row->org_status, $offset, $config);
            if ($notice === null) {
                continue;
            }
            [$kind, $days] = $notice;
            // La prenotazione la prende il lavoro all'invio (SendLifecycleNotice): qui si salta solo l'avviso già mandato.
            if ($db->connection('pgsql')->table('core.org_notices')->where(['org_id' => $row->org_id, 'kind' => $kind, 'until' => $until->toDateString()])->exists()) {
                continue;
            }
            $keepDays = str_starts_with($kind, 'trial') ? $config->trialPurgeDays() : $config->retentionDays();
            SendLifecycleNotice::dispatch($row->org_id, $until->toDateString(),
                new OrgLifecycleNotification($kind, $row->name, $days, rtrim($config->portalUrl(), '/').'/account', $keepDays, $until->addDays($keepDays)));
            $sent++;
        }
        $this->info("Avvisi in coda: {$sent}");

        return self::SUCCESS;
    }

    /**
     * Quale avviso spetta oggi, se spetta.
     *
     * @param  int  $offset  giorni dalla scadenza (negativo prima, positivo dopo)
     * @return array{0: string, 1: int}|null tipo e giorni (alla scadenza o alla cancellazione)
     */
    public static function notice(string $entitlement, string $orgStatus, int $offset, PortalConfig $config): ?array
    {
        if ($entitlement === 'trial') {
            $purge = $config->trialPurgeDays();
            if ($offset > $purge) {
                return null;
            }

            return match (true) {
                $offset <= -3 && $offset >= -7 => ['trial_7', -$offset],
                $offset <= -1 && $offset > -3 => ['trial_2', -$offset],
                $offset >= 0 && $offset < 3 => ['trial_0', -$offset],
                $offset >= 3 && $offset < 6 => ['trial_3', -$offset],
                $offset >= 6 => ['trial_6', -$offset],
                default => null,
            };
        }

        // Prima della scadenza non scrive il gestionale: «il piano sta per scadere» riguarda il pagamento ed è del portale.
        $retention = $config->retentionDays();
        if ($orgStatus !== 'suspended' || $offset < 1 || $offset >= $retention) {
            return null;
        }

        return match (true) {
            $offset >= $retention - 1 => ['purge_1', $retention - $offset],
            $offset >= 170 => ['purge_170', $retention - $offset],
            $offset >= 150 => ['purge_150', $retention - $offset],
            $offset >= 90 => ['purge_90', $retention - $offset],
            $offset >= 30 => ['purge_30', $retention - $offset],
            default => ['suspended', $retention - $offset],
        };
    }
}
