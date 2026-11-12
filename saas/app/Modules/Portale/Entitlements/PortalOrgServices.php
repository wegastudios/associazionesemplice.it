<?php

declare(strict_types=1);

namespace App\Modules\Portale\Entitlements;

use App\Modules\Core\Contracts\OrgContext;
use App\Modules\Core\Contracts\OrgDirectory;
use App\Modules\Piattaforma\Contracts\Catalog;
use App\Modules\Piattaforma\Contracts\PlanLimits;
use App\Modules\Piattaforma\Contracts\UnknownAddon;
use App\Modules\Piattaforma\Contracts\UnknownPlan;
use App\Modules\Portale\PortalConfig;
use App\Modules\Soci\Contracts\Members;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;

/**
 * Richieste del portale su una singola associazione (CONTRATTO_PORTALE.md §3.4–§3.6): se un cambio di piano è
 * possibile, dominio personalizzato, stato per l'area clienti. Solo numeri e stati, mai dati dei soci.
 */
final readonly class PortalOrgServices
{
    private const DOMAIN_PATTERN = '/^(?=.{4,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/';

    public function __construct(
        private PortalRequest $request,
        private PortalConfig $config,
        private DatabaseManager $db,
        private OrgContext $context,
        private OrgDirectory $orgs,
        private Members $members,
        private Catalog $catalog,
        private PlanLimits $limits,
    ) {}

    /**
     * @return array{allowed: bool, blockers: list<string>, effects: list<string>}
     *
     * @throws UnknownPlan
     * @throws UnknownAddon
     * @throws UnknownOrg
     */
    public function planChangeCheck(string $token, string $orgId): array
    {
        $claims = $this->request->verify($token, 'plan_change_check', ['ref', 'to_plan', 'to_plan_version']);
        $this->request->assertOrg($claims, $orgId);
        $plan = (string) PortalRequest::string($claims, 'to_plan', '/^[a-z][a-z0-9_]{1,39}$/');
        $version = $claims['to_plan_version'] ?? null;
        if (! is_int($version) || $version < 1 || $version > 100_000) {
            throw new InvalidArgumentException('to_plan_version non valido');
        }

        $stored = $this->stored($orgId);
        $subscription = is_array($stored['subscription'] ?? null) ? $stored['subscription'] : [];
        $addons = [];
        foreach (is_array($subscription['addons'] ?? null) ? $subscription['addons'] : [] as $addon) {
            if (is_array($addon) && is_string($addon['code'] ?? null) && is_int($addon['qty'] ?? null)) {
                $addons[] = ['code' => $addon['code'], 'qty' => $addon['qty']];
            }
        }

        $members = $this->context->run($orgId, fn (): int => $this->members->activeCount());
        $operators = $this->operators($orgId)['active'];
        $target = $this->catalog->plan($plan, $version) ?? throw new UnknownPlan("{$plan}@{$version}");
        $resolved = $this->limits->resolve($plan, $version, $addons, $members);

        $blockers = [];
        $targetLimits = is_array($target['limits'] ?? null) ? $target['limits'] : [];
        if (is_int($targetLimits['members'] ?? null) && $members > $targetLimits['members']) {
            $blockers[] = "Hai {$members} soci e il piano ne ammette {$targetLimits['members']}";
        }
        if ($operators > $resolved['operators']) {
            $blockers[] = "Hai {$operators} operatori attivi e il piano ne ammette {$resolved['operators']}";
        }

        $effects = [];
        $lost = array_diff(array_keys(is_array($stored['packages'] ?? null) ? $stored['packages'] : []), array_keys($resolved['packages']));
        sort($lost);
        foreach ($lost as $package) {
            $effects[] = 'Si perde il pacchetto '.$this->packageName((string) $package);
        }

        return ['allowed' => $blockers === [], 'blockers' => $blockers, 'effects' => $effects];
    }

    /**
     * @return array{ok: bool, reason?: string}
     *
     * @throws UnknownOrg
     */
    public function domainCheck(string $token, string $orgId): array
    {
        $claims = $this->request->verify($token, 'domain_check', ['ref', 'domain']);
        $this->request->assertOrg($claims, $orgId);

        $reason = $this->domainReason(self::domain($claims['domain'] ?? null), $orgId);

        return $reason === null ? ['ok' => true] : ['ok' => false, 'reason' => $reason];
    }

    /**
     * @return array{ok: false, reason: string}|array{domain: string|null, state: string, dns: list<array{type: string, name: string, value: string}>, message: string}
     *
     * @throws UnknownOrg
     */
    public function domainSet(string $token, string $orgId): array
    {
        $claims = $this->request->verify($token, 'domain_set', ['ref', 'domain'], true);
        $this->request->assertOrg($claims, $orgId);
        $connection = $this->db->connection('pgsql');

        if (($claims['domain'] ?? null) === null) {
            $connection->table('core.org_domains')->where('org_id', $orgId)->delete();

            return ['domain' => null, 'state' => 'removed', 'dns' => [], 'message' => 'Dominio personalizzato tolto.'];
        }

        $domain = self::domain($claims['domain']);
        $reason = $this->domainReason($domain, $orgId);
        if ($reason !== null) {
            return ['ok' => false, 'reason' => $reason];
        }

        $connection->table('core.org_domains')->upsert([[
            'org_id' => $orgId, 'domain' => (string) $domain, 'state' => 'pending', 'message' => null, 'requested_at' => now(), 'checked_at' => null,
        ]], ['org_id'], ['domain', 'state', 'message', 'requested_at', 'checked_at']);

        $state = $this->domainState($orgId);
        if ($state === null) {
            throw new UnknownOrg;
        }

        return $state;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws UnknownOrg
     */
    public function status(string $token, string $orgId): array
    {
        $claims = $this->request->verify($token, 'org_status', ['ref']);
        $this->request->assertOrg($claims, $orgId);

        $stored = $this->stored($orgId);
        $subscription = is_array($stored['subscription'] ?? null) ? $stored['subscription'] : [];
        $plan = is_string($subscription['plan'] ?? null) ? $subscription['plan'] : null;
        $version = is_int($subscription['plan_version'] ?? null) ? $subscription['plan_version'] : null;
        $planLimits = $plan !== null && $version !== null ? ($this->catalog->plan($plan, $version)['limits'] ?? null) : null;

        $packages = array_map('strval', array_keys(is_array($stored['packages'] ?? null) ? $stored['packages'] : []));
        sort($packages);
        $domain = $this->domainState($orgId);

        return [
            'org_id' => $orgId,
            'status' => is_string($stored['status'] ?? null) ? $stored['status'] : 'none',
            'plan' => $plan,
            'plan_version' => $version,
            'rev' => is_int($stored['rev'] ?? null) ? $stored['rev'] : null,
            'members' => $this->context->run($orgId, fn (): int => $this->members->activeCount()),
            'members_limit' => is_array($planLimits) && is_int($planLimits['members'] ?? null) ? $planLimits['members'] : null,
            'operators' => ['active' => $this->operators($orgId)['active'], 'limit' => is_int($stored['operators'] ?? null) ? $stored['operators'] : null],
            'storage_limit_gb' => is_int($stored['storage_gb'] ?? null) ? $stored['storage_gb'] : null,
            'packages' => $packages,
            'domain' => $domain === null ? null : ['name' => $domain['domain'], 'state' => $domain['state']],
        ];
    }

    /**
     * Stato e istruzioni DNS del dominio di un'associazione, o null se non ne ha.
     *
     * @return array{domain: string, state: string, dns: list<array{type: string, name: string, value: string}>, message: string}|null
     */
    public function domainState(string $orgId): ?array
    {
        $row = $this->db->connection('pgsql')->table('core.org_domains')->where('org_id', $orgId)->first(['domain', 'state', 'message']);
        if ($row === null || ! is_string($row->domain) || ! is_string($row->state)) {
            return null;
        }
        $slug = $this->orgs->slug($orgId) ?? '';

        return [
            'domain' => $row->domain,
            'state' => $row->state,
            'dns' => [['type' => 'CNAME', 'name' => $row->domain, 'value' => $slug.'.'.$this->rootDomain()]],
            'message' => is_string($row->message) && $row->message !== '' ? $row->message : match ($row->state) {
                'active' => 'Il dominio è attivo.',
                'error' => 'Il record DNS non è corretto.',
                default => 'In attesa che il record DNS sia visibile.',
            },
        ];
    }

    /** Il dominio base del servizio (associazionesemplice.it): lo ricava dall'indirizzo del SaaS. */
    public function rootDomain(): string
    {
        $host = (string) parse_url($this->config->saasUrl(), PHP_URL_HOST);
        $labels = explode('.', $host);

        return implode('.', array_slice($labels, max(0, count($labels) - 2)));
    }

    private function domainReason(?string $domain, string $orgId): ?string
    {
        if ($domain === null) {
            return 'invalid';
        }
        $root = $this->rootDomain();
        if ($domain === $root || str_ends_with($domain, '.'.$root)) {
            return 'reserved';
        }
        $stored = $this->stored($orgId);
        $packages = is_array($stored['packages'] ?? null) ? $stored['packages'] : [];
        if (! array_key_exists('custom_domain', $packages)) {
            return 'no_package';
        }
        $taken = $this->db->connection('pgsql')->table('core.org_domains')->where('domain', $domain)->where('org_id', '!=', $orgId)->exists();

        return $taken ? 'taken' : null;
    }

    private static function domain(mixed $value): ?string
    {
        $domain = is_string($value) ? strtolower(trim($value)) : '';

        return preg_match(self::DOMAIN_PATTERN, $domain) === 1 ? $domain : null;
    }

    /** @return array<string, mixed> */
    private function stored(string $orgId): array
    {
        $claims = $this->db->connection('pgsql')->table('core.org_entitlements')->where('org_id', $orgId)->value('claims');
        $decoded = is_string($claims) ? json_decode($claims, true) : null;
        /** @var array<string, mixed> */
        return is_array($decoded) ? $decoded : [];
    }

    /** @return array{active: int} */
    private function operators(string $orgId): array
    {
        $active = $this->db->connection('pgsql')->table('core.org_users as m')->join('core.users as u', 'u.id', '=', 'm.user_id')
            ->where('m.org_id', $orgId)->whereNull('u.disabled_at')->count();

        return ['active' => $active];
    }

    private function packageName(string $code): string
    {
        foreach (is_array($this->catalog->published()->document['addons'] ?? null) ? $this->catalog->published()->document['addons'] : [] as $addon) {
            $grants = is_array($addon) && is_array($addon['grants'] ?? null) ? $addon['grants'] : [];
            if (is_array($addon) && ($grants['package'] ?? null) === $code && is_string($addon['name'] ?? null)) {
                return $addon['name'];
            }
        }

        return $code;
    }
}
