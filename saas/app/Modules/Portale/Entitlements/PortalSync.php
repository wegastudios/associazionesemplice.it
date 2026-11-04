<?php

declare(strict_types=1);

namespace App\Modules\Portale\Entitlements;

use App\Modules\Audit\Contracts\AuditLog;
use App\Modules\Core\Contracts\OrgContext;
use App\Modules\Core\Contracts\OrgDirectory;
use App\Modules\Portale\Jwt\EdDsaJwt;
use App\Modules\Portale\PortalConfig;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;

/**
 * Ciò che arriva dal portale: creazione dell'associazione e diritti. Ogni token si verifica (firma, emittente,
 * destinatario, tipo, vita breve) e poi si controllano i dati uno per uno.
 */
final readonly class PortalSync
{
    public function __construct(
        private DatabaseManager $db,
        private PortalConfig $config,
        private OrgDirectory $orgs,
        private OrgContext $context,
        private AuditLog $audit,
    ) {}

    /**
     * Idempotente sul riferimento dell'abbonamento: ripetere la stessa richiesta restituisce la stessa associazione.
     *
     * @return array{org_id: string, created: bool}
     */
    public function provision(string $token): array
    {
        $claims = ClaimsValidator::provision($this->verify($token, 'provision'));

        $existing = $this->orgs->findByPortalRef($claims['ref']);
        if ($existing !== null) {
            return ['org_id' => $existing, 'created' => false];
        }
        if ($this->orgs->slugTaken($claims['org']['slug'])) {
            throw new SlugTaken;
        }

        $id = $this->orgs->createWithOwner($claims['org']['name'], $claims['org']['slug'], $claims['owner']['email'], $claims['owner']['name'], $claims['ref']);

        return ['org_id' => $id, 'created' => true];
    }

    /**
     * Applica i diritti firmati; una revisione vecchia o uguale non cambia nulla. $orgId è l'associazione
     * dell'indirizzo chiamato: deve coincidere con quella firmata.
     */
    public function apply(string $token, ?string $orgId = null): int
    {
        $raw = $this->verify($token, 'entitlements');
        $claims = ClaimsValidator::entitlements($raw);

        if (($orgId !== null && $orgId !== $claims['sub']) || $this->orgs->portalRef($claims['sub']) !== $claims['ref']) {
            throw new UnknownOrg;
        }

        $connection = $this->db->connection('pgsql');
        $connection->transaction(function () use ($connection, $claims, $raw, $token): void {
            $current = $connection->table('core.org_entitlements')->where('org_id', $claims['sub'])->lockForUpdate()->value('rev');
            $current = is_numeric($current) ? (int) $current : 0;
            if ($claims['rev'] <= $current) {
                throw new StaleEntitlements($current);
            }

            $connection->table('core.org_entitlements')->upsert([[
                'org_id' => $claims['sub'],
                'rev' => $claims['rev'],
                'status' => $claims['status'],
                'until' => $claims['until'],
                'claims' => json_encode($raw, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'token' => $token,
                'received_at' => now(),
            ]], ['org_id'], ['rev', 'status', 'until', 'claims', 'token', 'received_at']);

            $this->orgs->setStatus($claims['sub'], self::orgStatus($claims['status'], $claims['until'], $this->config->graceDays()));

            $packages = array_keys($claims['packages']);
            sort($packages);
            $this->context->run($claims['sub'], fn () => $this->audit->record('subscription.updated', null, null, [
                'rev' => $claims['rev'],
                'status' => $claims['status'],
                'until' => $claims['until'],
                'operators' => $claims['operators'],
                'packages' => $packages,
            ]));
        });

        return $claims['rev'];
    }

    /**
     * Sospende le associazioni con l'abbonamento scaduto da più dei giorni di tolleranza, anche se il portale non lo
     * ha comunicato (per esempio perché non raggiungibile).
     *
     * @return list<string> associazioni sospese
     */
    public function suspendExpired(CarbonImmutable $today): array
    {
        $limit = $today->subDays($this->config->graceDays())->toDateString();
        $ids = $this->db->connection('pgsql')->table('core.org_entitlements as e')
            ->join('core.orgs as o', 'o.id', '=', 'e.org_id')
            ->where('o.status', 'active')
            ->where('e.until', '<', $limit)
            ->pluck('e.org_id')->all();

        $suspended = [];
        foreach ($ids as $id) {
            if (is_string($id)) {
                $this->orgs->setStatus($id, 'suspended');
                $suspended[] = $id;
            }
        }

        return $suspended;
    }

    /** @return 'active'|'suspended'|'closed' */
    public static function orgStatus(string $status, string $until, int $graceDays, ?CarbonImmutable $today = null): string
    {
        $today ??= CarbonImmutable::today();

        return match ($status) {
            'cancelled' => 'closed',
            'suspended' => 'suspended',
            default => CarbonImmutable::parse($until)->addDays($graceDays)->lt($today) ? 'suspended' : 'active',
        };
    }

    /** @return array<string, mixed> */
    private function verify(string $token, string $type): array
    {
        return EdDsaJwt::verify($token, $this->config->portalKeys(), $this->config->portalUrl(), PortalConfig::AUDIENCE, $type, time());
    }
}
