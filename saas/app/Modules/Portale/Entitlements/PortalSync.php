<?php

declare(strict_types=1);

namespace App\Modules\Portale\Entitlements;

use App\Modules\Audit\Contracts\AuditLog;
use App\Modules\Core\Contracts\OrgContext;
use App\Modules\Core\Contracts\OrgDirectory;
use App\Modules\Piattaforma\Contracts\AddonNotAllowed;
use App\Modules\Piattaforma\Contracts\PlanLimits;
use App\Modules\Piattaforma\Contracts\UnknownAddon;
use App\Modules\Piattaforma\Contracts\UnknownPlan;
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
        private PlanLimits $limits,
        private EntitlementReader $reader,
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
     * Applica diritti firmati nel vecchio formato; una revisione vecchia o uguale non cambia nulla. Nessuna rotta li
     * accetta più (rispondono 410): resta come ingresso dei test del ciclo di vita.
     */
    public function apply(string $token, ?string $orgId = null): int
    {
        $raw = $this->verify($token, 'entitlements');
        $claims = ClaimsValidator::entitlements($raw);
        $this->checkOrg($claims['sub'], $claims['ref'], $orgId);

        $this->store($claims, $raw, $token, []);

        return $claims['rev'];
    }

    /**
     * Applica un abbonamento v2: dal piano e dagli addon ricava operatori, spazio e pacchetti, e li salva come i diritti
     * v1 (stessa tabella, stessa serie di revisioni), così il resto del SaaS non cambia. Piano o addon sconosciuti
     * non applicano nulla.
     *
     * @return array{rev: int, plan: string, plan_version: int}
     *
     * @throws UnknownPlan
     * @throws UnknownAddon
     * @throws AddonNotAllowed
     */
    public function applySubscription(string $token, ?string $orgId = null): array
    {
        $raw = $this->verify($token, 'subscription');
        $c = ClaimsValidator::subscription($raw);
        $this->checkOrg($c['sub'], $c['ref'], $orgId);

        $limits = $this->limits->resolve($c['plan'], $c['plan_version'], $c['addons'], $c['members']);
        $claims = [
            'sub' => $c['sub'],
            'ref' => $c['ref'],
            'rev' => $c['rev'],
            'status' => $c['status'],
            'until' => $c['until'],
            'trial_ends' => $c['trial_ends'],
            'members' => $c['members'],
            'operators' => max(1, $limits['operators']),
            'storage_gb' => $limits['storage_gb'],
            'packages' => $limits['packages'],
            'partner' => $c['partner'],
        ];
        // Controllo finale con le stesse regole dei diritti v1 (pacchetti e limiti ammessi).
        $checked = ClaimsValidator::entitlements($claims);
        $stored = $claims + ['members_limit' => $limits['members_limit'], 'subscription' => ['plan' => $c['plan'], 'plan_version' => $c['plan_version'], 'addons' => $c['addons'], 'promo' => $c['promo'], 'price_cents' => $c['price_cents']]];

        $this->store($checked, $stored, $token, ['plan' => $c['plan'], 'plan_version' => $c['plan_version']]);

        return ['rev' => $c['rev'], 'plan' => $c['plan'], 'plan_version' => $c['plan_version']];
    }

    private function checkOrg(string $sub, string $ref, ?string $orgId): void
    {
        if (($orgId !== null && $orgId !== $sub) || $this->orgs->portalRef($sub) !== $ref) {
            throw new UnknownOrg;
        }
    }

    /** Di quanto una revisione può superare l'ultima accettata: un salto enorme bloccherebbe per sempre gli aggiornamenti. */
    public const MAX_REVISION_JUMP = 1000;

    /** Giorni oltre i quali una scadenza che arretra finisce nel registro con «until_back_days». */
    private const UNTIL_BACK_FLAG_DAYS = 30;

    /**
     * @param  array{sub: string, ref: string, rev: int, status: string, until: string, trial_ends: string|null, members: int, operators: int, storage_gb: int, packages: array<string, array<string, mixed>>, partner: string|null}  $claims
     * @param  array<string, mixed>  $raw  ciò che si conserva
     * @param  array<string, int|string>  $auditExtra
     */
    private function store(array $claims, array $raw, string $token, array $auditExtra): void
    {
        $connection = $this->db->connection('pgsql');
        $connection->transaction(function () use ($connection, $claims, $raw, $token, $auditExtra): void {
            $existing = $connection->table('core.org_entitlements')->where('org_id', $claims['sub'])->lockForUpdate()->first(['rev', 'until']);
            $current = $existing !== null && is_numeric($existing->rev ?? null) ? (int) $existing->rev : 0;
            if ($claims['rev'] <= $current) {
                throw new StaleEntitlements($current);
            }
            if ($claims['rev'] - $current > self::MAX_REVISION_JUMP) {
                throw new RevisionJumpTooLarge;
            }
            // Una scadenza che arretra di molto si segnala nel registro: da sola non basta mai a far cancellare i dati.
            if ($existing !== null && is_string($existing->until ?? null) && $existing->until !== '') {
                $back = (int) CarbonImmutable::parse($claims['until'])->diffInDays(CarbonImmutable::parse($existing->until), false);
                if ($back > self::UNTIL_BACK_FLAG_DAYS) {
                    $auditExtra += ['until_back_days' => $back];
                }
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
            $this->reader->forget($claims['sub']);

            $this->orgs->setStatus($claims['sub'], self::orgStatus($claims['status'], $claims['until'], $this->config->graceDays()));

            $packages = array_keys($claims['packages']);
            sort($packages);
            $this->context->run($claims['sub'], fn () => $this->audit->record('subscription.updated', null, null, [
                'rev' => $claims['rev'],
                'status' => $claims['status'],
                'until' => $claims['until'],
                'operators' => $claims['operators'],
                'packages' => $packages,
            ] + $auditExtra));
        });
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
