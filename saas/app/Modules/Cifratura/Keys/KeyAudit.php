<?php

declare(strict_types=1);

namespace App\Modules\Cifratura\Keys;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;

/**
 * Righe del registro delle azioni della piattaforma (core.platform_audit) per le operazioni sulle chiavi: numeri,
 * direzioni e date, mai chiavi, frasi o percorsi completi.
 */
final readonly class KeyAudit
{
    public const REWRAP = 'keys.rewrap';

    public const LOCAL_DESTROYED = 'keys.local_destroyed';

    public const RECOVERY_CREATED = 'keys.recovery_created';

    public const RECOVERY_RESTORED = 'keys.recovery_restored';

    public const ORG_KEY_MISSING = 'keys.org_key_missing';

    public function __construct(private DatabaseManager $db) {}

    /** @param  array<string, scalar|list<string>|null>  $details */
    public function record(string $action, array $details = [], string $subject = 'chiave principale'): void
    {
        $this->db->connection('pgsql')->table('core.platform_audit')->insert([
            'id' => (string) Str::uuid7(),
            'staff_id' => null,
            'action' => $action,
            'subject' => $subject,
            'details' => json_encode($details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'ip' => null,
            'created_at' => now(),
        ]);
    }

    /**
     * L'ultimo passaggio che ha davvero riavvolto delle chiavi: direzione e data. I backup fatti prima di questa data
     * possono contenere chiavi avvolte con il custode precedente.
     *
     * @return array{to: string, at: CarbonImmutable}|null
     */
    public function lastSwitch(): ?array
    {
        $rows = $this->db->connection('pgsql')->table('core.platform_audit')
            ->where('action', self::REWRAP)
            ->orderByDesc('created_at')
            ->limit(200)
            ->get(['details', 'created_at']);

        foreach ($rows as $row) {
            $details = is_object($row) && isset($row->details) && is_string($row->details) ? json_decode($row->details, true) : null;
            $at = is_object($row) && isset($row->created_at) && is_string($row->created_at) ? $row->created_at : null;
            $rewrapped = is_array($details) ? ($details['rewrapped'] ?? null) : null;
            $to = is_array($details) ? ($details['to'] ?? null) : null;
            $dryRun = is_array($details) ? ($details['dry_run'] ?? false) : true;
            if ($at !== null && is_int($rewrapped) && $rewrapped > 0 && is_string($to) && $dryRun === false) {
                return ['to' => $to, 'at' => CarbonImmutable::parse($at)];
            }
        }

        return null;
    }

    public function destroyedSince(CarbonImmutable $since): bool
    {
        return $this->db->connection('pgsql')->table('core.platform_audit')
            ->where('action', self::LOCAL_DESTROYED)
            ->where('created_at', '>=', $since)
            ->exists();
    }
}
