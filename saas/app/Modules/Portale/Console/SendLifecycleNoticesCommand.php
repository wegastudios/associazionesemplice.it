<?php

declare(strict_types=1);

namespace App\Modules\Portale\Console;

use App\Modules\Core\Models\User;
use App\Modules\Piattaforma\Contracts\Catalog;
use App\Modules\Portale\Notifications\OrgLifecycleNotification;
use App\Modules\Portale\PortalConfig;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Avvisi del ciclo di vita ai titolari e agli amministratori (docs/CANCELLAZIONE_DATI.md §2 e §8): scadenza vicina per
 * chi ha disdetto o non paga, sospensione, promemoria prima della cancellazione, fine della prova. Ogni avviso parte una
 * sola volta per scadenza (core.org_notices); i giorni sono a finestre, così un giorno saltato non perde un avviso.
 */
#[AsCommand(name: 'saas:portal:lifecycle-notices')]
final class SendLifecycleNoticesCommand extends Command
{
    protected $signature = 'saas:portal:lifecycle-notices';

    protected $description = 'Invia gli avvisi su scadenza, sospensione, cancellazione dei dati e fine della prova';

    public function handle(DatabaseManager $db, PortalConfig $config, Catalog $catalog): int
    {
        $today = CarbonImmutable::today();
        $trial = $catalog->published()->document['terms']['trial'] ?? [];
        $discount = is_array($trial) && is_int($trial['end_discount_percent'] ?? null) ? $trial['end_discount_percent'] : 0;
        $sent = 0;

        $rows = $db->connection('pgsql')->table('core.org_entitlements as e')
            ->join('core.orgs as o', 'o.id', '=', 'e.org_id')
            ->whereNull('o.deleted_at')->whereNotNull('e.until')
            ->get(['e.org_id', 'o.name', 'o.status as org_status', 'e.status', 'e.until']);

        foreach ($rows as $row) {
            if (! is_string($row->org_id) || ! is_string($row->name) || ! is_string($row->until)) {
                continue;
            }
            $until = CarbonImmutable::parse($row->until);
            $offset = (int) $until->diffInDays($today, false); // negativo = la scadenza è futura
            $notice = self::notice((string) $row->status, (string) $row->org_status, $offset, $config);
            if ($notice === null) {
                continue;
            }
            [$kind, $days] = $notice;
            $claimed = $db->connection('pgsql')->table('core.org_notices')->insertOrIgnore(['org_id' => $row->org_id, 'kind' => $kind, 'until' => $until->toDateString()]);
            if ($claimed === 0) {
                continue;
            }
            $users = User::query()->whereIn('id', $db->connection('pgsql')->table('core.org_users')
                ->where('org_id', $row->org_id)->whereIn('role', ['owner', 'admin'])->pluck('user_id'))->get();
            Notification::send($users, new OrgLifecycleNotification($kind, $row->name, $days, rtrim($config->portalUrl(), '/').'/account', $discount));
            $sent++;
        }
        $this->info("Avvisi inviati: {$sent}");

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

        $retention = $config->retentionDays();
        if ($orgStatus === 'active' && in_array($entitlement, ['cancelled', 'past_due'], true)) {
            return match (true) {
                $offset >= -7 && $offset <= 0 => ['expiry_7', -$offset],
                $offset >= -30 && $offset < -7 => ['expiry_30', -$offset],
                default => null,
            };
        }
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
