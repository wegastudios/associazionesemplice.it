<?php

declare(strict_types=1);

namespace App\Modules\Portale\Entitlements;

use App\Modules\Core\Contracts\OrgDirectory;
use App\Modules\Piattaforma\Contracts\Catalog;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;

/**
 * Promozioni (CONTRATTO_PORTALE.md §3.3): le definisce solo /saas/admin, qui si controllano e si contano i riscatti.
 * «Controllare» non consuma nulla; «riscattare» consuma un uso, una volta sola per ordine del portale.
 */
final readonly class PortalPromos
{
    public function __construct(
        private PortalRequest $request,
        private Catalog $catalog,
        private DatabaseManager $db,
        private OrgDirectory $orgs,
    ) {}

    /** @return array<string, mixed> */
    public function check(string $token, ?CarbonImmutable $today = null): array
    {
        $claims = $this->request->verify($token, 'promo_check', ['code', 'plan', 'plan_version', 'period', 'ref']);
        $code = self::code($claims);
        $plan = PortalRequest::string($claims, 'plan', '/^[a-z][a-z0-9_]{1,39}$/');
        PortalRequest::string($claims, 'period', '/^(month|year)$/');
        if (isset($claims['plan_version']) && (! is_int($claims['plan_version']) || $claims['plan_version'] < 1)) {
            throw new InvalidArgumentException('plan_version non valido');
        }
        if (($claims['ref'] ?? null) !== null) {
            PortalRequest::string($claims, 'ref', '/^[A-Za-z0-9_-]{1,64}$/');
        }

        $promo = $this->find($code);
        $reason = $promo === null ? 'unknown' : $this->reason($promo, $plan, $today ?? CarbonImmutable::today());
        if ($promo === null || $reason !== null) {
            return ['valid' => false, 'reason' => $reason];
        }

        return [
            'valid' => true,
            'discount' => $promo['discount'] ?? null,
            'valid_to' => $promo['valid_to'] ?? null,
            'applies_to' => $promo['plans'] ?? null,
            'stackable' => ($promo['stackable'] ?? false) === true,
        ];
    }

    /**
     * Idempotente sull'ordine: ripetuta restituisce lo stesso esito.
     *
     * @return array{redeemed: true, uses_left: int|null}|null null se esaurita
     */
    public function redeem(string $token, ?CarbonImmutable $today = null): ?array
    {
        $claims = $this->request->verify($token, 'promo_redeem', ['code', 'order', 'ref'], true);
        $code = self::code($claims);
        $order = (string) PortalRequest::string($claims, 'order', '/^[A-Za-z0-9_-]{1,64}$/');
        $ref = (string) PortalRequest::string($claims, 'ref', '/^[A-Za-z0-9_-]{1,64}$/');

        $promo = $this->find($code);
        $connection = $this->db->connection('pgsql');

        return $connection->transaction(function () use ($connection, $promo, $code, $order, $ref, $today): ?array {
            $connection->statement('SELECT pg_advisory_xact_lock(hashtext(?))', ['promo:'.$code]);

            $done = $connection->table('core.promo_redemptions')->where('order_ref', $order)->first(['code', 'uses_left']);
            if ($done !== null) {
                return is_string($done->code) && $done->code === $code
                    ? ['redeemed' => true, 'uses_left' => is_numeric($done->uses_left) ? (int) $done->uses_left : null]
                    : throw new InvalidArgumentException('order già usato con un altro codice');
            }
            if ($promo === null || $this->reason($promo, null, $today ?? CarbonImmutable::today()) !== null) {
                return null;
            }

            $max = is_int($promo['max_uses'] ?? null) ? $promo['max_uses'] : null;
            $left = $max === null ? null : $max - $this->used($code) - 1;
            $connection->table('core.promo_redemptions')->insert([
                'order_ref' => $order,
                'code' => $code,
                'org_id' => $this->orgs->findByPortalRef($ref),
                'uses_left' => $left,
            ]);

            return ['redeemed' => true, 'uses_left' => $left];
        });
    }

    /** @param  array<string, mixed>  $promo */
    private function reason(array $promo, ?string $plan, CarbonImmutable $today): ?string
    {
        $from = is_string($promo['valid_from'] ?? null) ? $promo['valid_from'] : null;
        $to = is_string($promo['valid_to'] ?? null) ? $promo['valid_to'] : null;
        if (($from !== null && $today->toDateString() < $from) || ($to !== null && $today->toDateString() > $to)) {
            return 'expired';
        }
        $plans = $promo['plans'] ?? null;
        if ($plan !== null && is_array($plans) && ! in_array($plan, $plans, true)) {
            return 'wrong_plan';
        }
        $max = $promo['max_uses'] ?? null;
        if (is_int($max) && is_string($promo['code'] ?? null) && $this->used($promo['code']) >= $max) {
            return 'exhausted';
        }

        return null;
    }

    private function used(string $code): int
    {
        return $this->db->connection('pgsql')->table('core.promo_redemptions')->where('code', $code)->count();
    }

    /** @return array<string, mixed>|null */
    private function find(string $code): ?array
    {
        $promos = $this->catalog->published()->document['promos'] ?? [];
        foreach (is_array($promos) ? $promos : [] as $promo) {
            if (is_array($promo) && is_string($promo['code'] ?? null) && strtoupper($promo['code']) === $code) {
                /** @var array<string, mixed> $promo */
                return $promo;
            }
        }

        return null;
    }

    /** @param  array<string, mixed>  $claims */
    private static function code(array $claims): string
    {
        return strtoupper((string) PortalRequest::string($claims, 'code', '/^[A-Za-z0-9_-]{2,40}$/'));
    }
}
