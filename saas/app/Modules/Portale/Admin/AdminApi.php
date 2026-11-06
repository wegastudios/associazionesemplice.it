<?php

declare(strict_types=1);

namespace App\Modules\Portale\Admin;

use App\Modules\Audit\Contracts\SecurityLog;
use App\Modules\Core\Contracts\OrgContext;
use App\Modules\Portale\Entitlements\UnknownOrg;
use App\Modules\Portale\Jwt\EdDsaJwt;
use App\Modules\Portale\PortalConfig;
use App\Modules\Soci\Contracts\Members;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;

/**
 * Amministrazione della piattaforma, chiamata dal portale (CONTRATTO_PORTALE.md §2.3). Ogni richiesta porta un token
 * firmato con `act` (chi l'ha chiesta) e `scope` (`read` o `write`). Si leggono solo numeri, stati e, per gli operatori,
 * nome, email, ruolo e stato dell'accesso: nessun dato dei soci.
 */
final readonly class AdminApi
{
    public function __construct(
        private DatabaseManager $db,
        private PortalConfig $config,
        private OrgContext $context,
        private Members $members,
        private SecurityLog $log,
        private Cache $cache,
    ) {}

    /** @return array{page: int, per_page: int, total: int, orgs: list<array<string, mixed>>} */
    public function orgs(string $token): array
    {
        $claims = $this->verify($token, 'admin_read', 'read');
        $page = self::intClaim($claims, 'page', 1, 1, 100000);
        $perPage = self::intClaim($claims, 'per_page', 50, 1, 100);
        $q = self::optString($claims, 'q', 100);
        $status = self::optString($claims, 'status', 20);
        if ($status !== null && ! in_array($status, ['active', 'suspended', 'closed'], true)) {
            throw new InvalidArgumentException('status');
        }

        $query = $this->db->connection('pgsql')->table('core.orgs')->whereNull('deleted_at');
        if ($q !== null && $q !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($q)).'%';
            $query->where(static function (Builder $w) use ($like): void {
                $w->whereRaw('lower(name) like ?', [$like])->orWhereRaw('lower(slug) like ?', [$like]);
            });
        }
        if ($status !== null) {
            $query->where('status', $status);
        }
        $total = (clone $query)->count();
        $rows = $query->orderBy('name')->orderBy('id')->forPage($page, $perPage)->get(['id']);

        $orgs = [];
        foreach ($rows as $row) {
            $orgs[] = $this->summary(self::str($row->id));
        }

        return ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'orgs' => $orgs];
    }

    /** @return array<string, mixed> */
    public function org(string $token, string $orgId): array
    {
        $this->verify($token, 'admin_read', 'read');
        $summary = $this->summary($orgId);

        $ent = $this->db->connection('pgsql')->table('core.org_entitlements')->where('org_id', $orgId)->first(['claims', 'until', 'received_at']);
        $claims = $ent === null ? [] : json_decode(self::str($ent->claims), true);
        $claims = is_array($claims) ? $claims : [];

        return $summary + [
            'packages' => $claims['packages'] ?? (object) [],
            'until' => $ent === null ? null : self::str($ent->until),
            'partner' => is_string($claims['partner'] ?? null) ? $claims['partner'] : null,
            'rev_received_at' => $ent === null ? null : substr(self::str($ent->received_at), 0, 19),
        ];
    }

    /** @return array{operators: list<array<string, mixed>>} */
    public function operators(string $token, string $orgId): array
    {
        $this->verify($token, 'admin_read', 'read');
        $this->assertOrg($orgId);

        $rows = $this->db->connection('pgsql')->table('core.org_users as m')
            ->join('core.users as u', 'u.id', '=', 'm.user_id')
            ->where('m.org_id', $orgId)->orderBy('u.name')->orderBy('u.id')
            ->get(['u.id', 'u.name', 'u.email', 'm.role', 'u.disabled_at', 'u.two_factor_confirmed_at', 'u.created_at']);

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'user_id' => self::str($r->id),
                'name' => self::str($r->name),
                'email' => self::str($r->email),
                'role' => self::str($r->role),
                'disabled' => $r->disabled_at !== null,
                'two_factor' => $r->two_factor_confirmed_at !== null,
                'created_at' => substr(self::str($r->created_at), 0, 10),
            ];
        }

        return ['operators' => $out];
    }

    /** @return array{user_id: string, disabled: bool} */
    public function setUserState(string $token, string $userId): array
    {
        $claims = $this->verify($token, 'admin_write', 'write');
        $disabled = $claims['disabled'] ?? null;
        if (! is_bool($disabled)) {
            throw new InvalidArgumentException('disabled');
        }
        $reason = self::optString($claims, 'reason', 200);

        $updated = $this->db->connection('pgsql')->table('core.users')->where('id', $userId)
            ->update(['disabled_at' => $disabled ? now() : null]);
        if ($updated === 0) {
            throw new UnknownUser;
        }

        $this->log->record($disabled ? 'platform.user_disabled' : 'platform.user_enabled', null, array_filter([
            'user' => $userId, 'act' => self::str($claims['act'] ?? ''), 'reason' => $reason,
        ], static fn (?string $v): bool => $v !== null));

        return ['user_id' => $userId, 'disabled' => $disabled];
    }

    /** @return array<string, mixed> */
    private function summary(string $orgId): array
    {
        $db = $this->db->connection('pgsql');
        $org = $db->table('core.orgs')->where('id', $orgId)->whereNull('deleted_at')->first(['id', 'portal_ref', 'name', 'slug', 'status', 'created_at']);
        if ($org === null) {
            throw new UnknownOrg;
        }

        $counts = $db->table('core.org_users as m')->join('core.users as u', 'u.id', '=', 'm.user_id')->where('m.org_id', $orgId)
            ->selectRaw('count(*) filter (where u.disabled_at is null) as active, count(*) filter (where u.disabled_at is not null) as disabled')->first();

        $ent = $db->table('core.org_entitlements')->where('org_id', $orgId)->first(['rev', 'claims']);
        $claims = $ent === null ? [] : json_decode(self::str($ent->claims), true);
        $claims = is_array($claims) ? $claims : [];

        return [
            'org_id' => $orgId,
            'ref' => $org->portal_ref === null ? null : self::str($org->portal_ref),
            'name' => self::str($org->name),
            'slug' => self::str($org->slug),
            'status' => self::str($org->status),
            'rev' => $ent === null ? null : self::num($ent->rev),
            'created_at' => substr(self::str($org->created_at), 0, 10),
            'operators' => [
                'active' => $counts === null ? 0 : self::num($counts->active),
                'disabled' => $counts === null ? 0 : self::num($counts->disabled),
                'limit' => is_int($claims['operators'] ?? null) ? $claims['operators'] : null,
            ],
            'members' => $this->context->run($orgId, fn (): int => $this->members->activeCount()),
            'members_limit' => is_int($claims['members'] ?? null) ? $claims['members'] : null,
            'storage_limit_gb' => is_int($claims['storage_gb'] ?? null) ? $claims['storage_gb'] : null,
        ];
    }

    private function assertOrg(string $orgId): void
    {
        if (! $this->db->connection('pgsql')->table('core.orgs')->where('id', $orgId)->whereNull('deleted_at')->exists()) {
            throw new UnknownOrg;
        }
    }

    /**
     * Firma, emittente, destinatario, tipo e vita breve (EdDsaJwt), poi `act` e `scope`; un token di scrittura vale una
     * volta sola (jti), per 15 minuti.
     *
     * @return array<string, mixed>
     */
    private function verify(string $token, string $type, string $scope): array
    {
        $claims = EdDsaJwt::verify($token, $this->config->portalKeys(), $this->config->portalUrl(), PortalConfig::AUDIENCE, $type, time());

        $act = $claims['act'] ?? null;
        if (! is_string($act) || $act === '' || mb_strlen($act) > 120) {
            throw new InvalidArgumentException('act');
        }
        if (($claims['scope'] ?? null) !== $scope) {
            throw new WrongScope;
        }
        if ($scope === 'write') {
            $jti = $claims['jti'] ?? null;
            if (! is_string($jti) || $jti === '' || ! $this->cache->add('portal-admin-jti:'.$jti, 1, 900)) {
                throw new ReplayedToken;
            }
        }

        return $claims;
    }

    /** @param  array<string, mixed>  $claims */
    private static function intClaim(array $claims, string $key, int $default, int $min, int $max): int
    {
        if (! array_key_exists($key, $claims)) {
            return $default;
        }
        $v = $claims[$key];
        if (! is_int($v) || $v < $min || $v > $max) {
            throw new InvalidArgumentException($key);
        }

        return $v;
    }

    /** @param  array<string, mixed>  $claims */
    private static function optString(array $claims, string $key, int $max): ?string
    {
        if (! array_key_exists($key, $claims) || $claims[$key] === null) {
            return null;
        }
        $v = $claims[$key];
        if (! is_string($v) || mb_strlen($v) > $max) {
            throw new InvalidArgumentException($key);
        }

        return $v;
    }

    private static function str(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private static function num(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
