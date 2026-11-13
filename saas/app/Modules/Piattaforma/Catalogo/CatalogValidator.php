<?php

declare(strict_types=1);

namespace App\Modules\Piattaforma\Catalogo;

use InvalidArgumentException;

/**
 * Controlla un catalogo prima di pubblicarlo (e quando si legge dal file): nessun campo sconosciuto, importi interi,
 * piano senza caratteristiche non pubblicabile, promo con date incoerenti (ARCHITETTURA_DOMINI §4.2). Restituisce il
 * documento `catalogo/v1` completo, con `schema` e `product` fissati qui.
 */
final class CatalogValidator
{
    private const SUPPORT = ['free', 'paid', 'top'];

    private const STATES = ['included', 'limit', 'excluded'];

    private const PRICE_KINDS = ['flat', 'members_tiers', 'step_tiers'];

    private const ADDON_KINDS = ['package', 'operators', 'members', 'storage', 'domain', 'dedicated_db', 'recharge'];

    /**
     * @param  array<mixed>  $input
     * @return array<string, mixed>
     */
    public static function validate(array $input): array
    {
        self::only($input, ['name', 'description', 'rev', 'published_at', 'currency', 'features', 'plans', 'addons', 'promos', 'terms'], 'catalogo');

        $features = self::features($input['features'] ?? null);
        $plans = self::plans($input['plans'] ?? null, array_keys($features));
        if (! array_filter($plans, static fn (array $plan): bool => $plan['visible'] === true)) {
            throw new InvalidArgumentException('almeno un piano deve essere visibile');
        }
        $addons = self::addons($input['addons'] ?? [], $plans);
        $planCodes = array_column($plans, 'code');

        return [
            'schema' => 'catalogo/v1',
            'product' => 'saas',
            'name' => self::text($input['name'] ?? null, 'name', 120),
            'description' => self::text($input['description'] ?? null, 'description', 400),
            'rev' => self::int($input['rev'] ?? null, 'rev', 1, PHP_INT_MAX),
            'published_at' => self::date($input['published_at'] ?? null, 'published_at'),
            'currency' => ($input['currency'] ?? null) === 'EUR' ? 'EUR' : throw new InvalidArgumentException('currency deve essere EUR'),
            'features' => array_values($features),
            'plans' => $plans,
            'addons' => $addons,
            'promos' => self::promos($input['promos'] ?? [], $planCodes),
            'terms' => self::terms($input['terms'] ?? []),
        ];
    }

    /**
     * Condizioni generali di vendita, uguali per tutti i piani: IVA, commissione sui pagamenti online e cosa non è
     * disponibile durante la prova. I cataloghi che non le dicono hanno i valori di partenza.
     *
     * @return array{vat: array{mode: string, note: string}, payments: array{own_gateway_percent: int, collected_by_us_percent: int}, trial: array{exports: bool, accountant_access: bool, end_discount_percent: int, purge_days: int}, members: array{warn_percent: int, full_percent: int, tolerance_percent: int, tolerance_days: int, guests_free_percent: int}}
     */
    private static function terms(mixed $value): array
    {
        $terms = self::object($value, 'terms', ['vat', 'payments', 'trial', 'members']);
        $vat = self::object($terms['vat'] ?? [], 'terms.vat', ['mode', 'note']);
        $payments = self::object($terms['payments'] ?? [], 'terms.payments', ['own_gateway_percent', 'collected_by_us_percent']);
        $trial = self::object($terms['trial'] ?? [], 'terms.trial', ['exports', 'accountant_access', 'end_discount_percent', 'purge_days']);

        $members = self::object($terms['members'] ?? [], 'terms.members', ['warn_percent', 'full_percent', 'tolerance_percent', 'tolerance_days', 'guests_free_percent']);
        $warn = self::int($members['warn_percent'] ?? 80, 'terms.members.warn_percent', 1, 100);
        $full = self::int($members['full_percent'] ?? 100, 'terms.members.full_percent', 1, 100);
        if ($full < $warn) {
            throw new InvalidArgumentException("terms.members: l'avviso al limite non può venire prima di quello di preavviso");
        }

        $exempt = ($vat['mode'] ?? 'standard') === 'exempt';
        if (! $exempt && ($vat['mode'] ?? 'standard') !== 'standard') {
            throw new InvalidArgumentException('terms.vat.mode deve essere standard o exempt');
        }

        return [
            'vat' => ['mode' => $exempt ? 'exempt' : 'standard', 'note' => $exempt ? self::text($vat['note'] ?? null, 'terms.vat.note', 300) : ''],
            'payments' => [
                'own_gateway_percent' => self::int($payments['own_gateway_percent'] ?? 0, 'terms.payments.own_gateway_percent', 0, 30),
                'collected_by_us_percent' => self::int($payments['collected_by_us_percent'] ?? 3, 'terms.payments.collected_by_us_percent', 0, 30),
            ],
            'trial' => [
                'exports' => self::bool($trial['exports'] ?? false, 'terms.trial.exports'),
                'accountant_access' => self::bool($trial['accountant_access'] ?? false, 'terms.trial.accountant_access'),
                'end_discount_percent' => self::int($trial['end_discount_percent'] ?? 0, 'terms.trial.end_discount_percent', 0, 100),
                'purge_days' => self::int($trial['purge_days'] ?? 7, 'terms.trial.purge_days', 1, 90),
            ],
            'members' => [
                'warn_percent' => $warn,
                'full_percent' => $full,
                'tolerance_percent' => self::int($members['tolerance_percent'] ?? 10, 'terms.members.tolerance_percent', 0, 50),
                'tolerance_days' => self::int($members['tolerance_days'] ?? 15, 'terms.members.tolerance_days', 0, 90),
                'guests_free_percent' => self::int($members['guests_free_percent'] ?? 10, 'terms.members.guests_free_percent', 0, 100),
            ],
        ];
    }

    /**
     * Il documento pubblicato senza i campi che mette il sistema (`schema`, `product`): la forma che si modifica e si
     * rimanda al validatore.
     *
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    public static function input(array $document): array
    {
        unset($document['schema'], $document['product']);

        return $document;
    }

    /**
     * @return array<string, array{id: string, text: string, group: string, compare?: string}>
     */
    private static function features(mixed $value): array
    {
        $list = self::list($value, 'features', 1);
        $out = [];
        $compare = [];
        foreach ($list as $i => $item) {
            $f = self::object($item, "features.{$i}", ['id', 'text', 'group', 'compare']);
            $id = self::code($f['id'] ?? null, "features.{$i}.id", 40);
            if (isset($out[$id])) {
                throw new InvalidArgumentException("caratteristica duplicata: {$id}");
            }
            $out[$id] = ['id' => $id, 'text' => self::text($f['text'] ?? null, "features.{$id}.text", 160), 'group' => self::text($f['group'] ?? null, "features.{$id}.group", 60)];
            // `compare`: chiave stabile per allineare la riga con il catalogo del plugin nelle tabelle di confronto
            // (facoltativa, unica nel catalogo; chi non ha corrispondenza la omette).
            if (array_key_exists('compare', $f)) {
                $key = self::code($f['compare'], "features.{$id}.compare", 40);
                if (isset($compare[$key])) {
                    throw new InvalidArgumentException("features.{$id}.compare duplicato: {$key}");
                }
                $compare[$key] = true;
                $out[$id]['compare'] = $key;
            }
        }

        return $out;
    }

    /**
     * @param  list<string|int>  $featureIds
     * @return list<array<string, mixed>>
     */
    private static function plans(mixed $value, array $featureIds): array
    {
        $list = self::list($value, 'plans', 1);
        $seen = [];
        $out = [];
        foreach ($list as $i => $item) {
            $p = self::object($item, "plans.{$i}", ['code', 'plan_version', 'name', 'audience', 'order', 'visible', 'support_level', 'trial_days', 'features', 'limits', 'prices']);
            $code = self::code($p['code'] ?? null, "plans.{$i}.code", 40);
            $version = self::int($p['plan_version'] ?? null, "plans.{$code}.plan_version", 1, 100_000);
            if (isset($seen["{$code}@{$version}"])) {
                throw new InvalidArgumentException("piano duplicato: {$code} versione {$version}");
            }
            $seen["{$code}@{$version}"] = true;

            $planFeatures = self::object($p['features'] ?? null, "plans.{$code}.features");
            if ($planFeatures === []) {
                throw new InvalidArgumentException("il piano {$code} non ha caratteristiche: non è pubblicabile");
            }
            $states = [];
            foreach ($planFeatures as $id => $state) {
                if (! in_array($id, $featureIds, true)) {
                    throw new InvalidArgumentException("il piano {$code} usa una caratteristica sconosciuta: {$id}");
                }
                $s = self::object($state, "plans.{$code}.features.{$id}", ['state', 'text']);
                $st = $s['state'] ?? null;
                if (! is_string($st) || ! in_array($st, self::STATES, true)) {
                    throw new InvalidArgumentException("stato non valido in plans.{$code}.features.{$id}");
                }
                $states[$id] = $st === 'limit'
                    ? ['state' => 'limit', 'text' => self::text($s['text'] ?? null, "plans.{$code}.features.{$id}.text", 160)]
                    : ['state' => $st];
            }

            $support = $p['support_level'] ?? null;
            if (! is_string($support) || ! in_array($support, self::SUPPORT, true)) {
                throw new InvalidArgumentException("plans.{$code}.support_level non valido");
            }

            $out[] = [
                'code' => $code,
                'plan_version' => $version,
                'name' => self::text($p['name'] ?? null, "plans.{$code}.name", 80),
                'audience' => self::text($p['audience'] ?? null, "plans.{$code}.audience", 160),
                'order' => self::int($p['order'] ?? null, "plans.{$code}.order", 0, 1000),
                'visible' => self::bool($p['visible'] ?? null, "plans.{$code}.visible"),
                'support_level' => $support,
                'trial_days' => self::int($p['trial_days'] ?? null, "plans.{$code}.trial_days", 0, 365),
                'features' => $states,
                'limits' => self::planLimits($p['limits'] ?? null, $code),
                'prices' => self::prices($p['prices'] ?? null, "plans.{$code}.prices"),
            ];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private static function planLimits(mixed $value, string $code): array
    {
        $l = self::object($value, "plans.{$code}.limits", ['members', 'operators', 'storage_mb_per_member', 'storage_mb_min', 'packages']);
        $members = $l['members'] ?? null;
        $packages = [];
        foreach (self::object($l['packages'] ?? [], "plans.{$code}.limits.packages") as $package => $limits) {
            $packages[self::code($package, "plans.{$code}.limits.packages", 40)] = self::object($limits, "plans.{$code}.limits.packages.{$package}");
        }

        return [
            'members' => $members === null ? null : self::int($members, "plans.{$code}.limits.members", 1, 1_000_000),
            'operators' => self::int($l['operators'] ?? null, "plans.{$code}.limits.operators", 1, 1000),
            'storage_mb_per_member' => self::int($l['storage_mb_per_member'] ?? null, "plans.{$code}.limits.storage_mb_per_member", 0, 10_000),
            'storage_mb_min' => self::int($l['storage_mb_min'] ?? null, "plans.{$code}.limits.storage_mb_min", 0, 10_000_000),
            'packages' => $packages,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $plans
     * @return list<array<string, mixed>>
     */
    private static function addons(mixed $value, array $plans): array
    {
        $out = [];
        $seen = [];
        foreach (self::list($value, 'addons', 0) as $i => $item) {
            $a = self::object($item, "addons.{$i}", ['code', 'name', 'kind', 'visible', 'plans', 'grants', 'prices']);
            $code = self::code($a['code'] ?? null, "addons.{$i}.code", 40);
            if (isset($seen[$code])) {
                throw new InvalidArgumentException("addon duplicato: {$code}");
            }
            $seen[$code] = true;
            $kind = $a['kind'] ?? null;
            if (! is_string($kind) || ! in_array($kind, self::ADDON_KINDS, true)) {
                throw new InvalidArgumentException("addons.{$code}.kind non valido");
            }
            $grants = self::object($a['grants'] ?? null, "addons.{$code}.grants", ['operators', 'members', 'storage_gb', 'package', 'limits']);
            $grantsOut = self::grants($grants, $kind, $code);
            $allowed = self::addonPlans($a['plans'] ?? null, $code, $plans, $grantsOut);
            $out[] = [
                'code' => $code,
                'name' => self::text($a['name'] ?? null, "addons.{$code}.name", 80),
                'kind' => $kind,
                'visible' => self::bool($a['visible'] ?? null, "addons.{$code}.visible"),
                'plans' => $allowed,
                'grants' => $grantsOut,
                'prices' => self::prices($a['prices'] ?? null, "addons.{$code}.prices"),
            ];
        }

        return $out;
    }

    /**
     * Su quali piani si compra un pacchetto: `null` = su tutti, altrimenti l'elenco dei codici piano. Un pacchetto di
     * funzioni già compreso in un piano non si può vendere su quel piano (si pagherebbe due volte).
     *
     * @param  list<array<string, mixed>>  $plans
     * @param  array<string, mixed>  $grants
     * @return list<string>|null
     */
    private static function addonPlans(mixed $value, string $code, array $plans, array $grants): ?array
    {
        $codes = array_values(array_unique(array_map(static fn (array $p): string => is_string($p['code'] ?? null) ? $p['code'] : '', $plans)));
        $allowed = null;
        if ($value !== null) {
            $allowed = [];
            foreach (self::list($value, "addons.{$code}.plans", 1) as $plan) {
                if (! is_string($plan) || ! in_array($plan, $codes, true)) {
                    throw new InvalidArgumentException("addons.{$code}.plans: piano sconosciuto");
                }
                $allowed[$plan] = $plan;
            }
            $allowed = array_values($allowed);
        }

        $package = $grants['package'] ?? null;
        // Con `plans` assente («su tutti») il portale riconosce da sé i piani che lo comprendono (limits.packages): qui si
        // controlla solo l'elenco scritto a mano.
        if (is_string($package) && $allowed !== null) {
            foreach ($plans as $plan) {
                $limits = is_array($plan['limits'] ?? null) ? $plan['limits'] : [];
                $included = is_array($limits['packages'] ?? null) && array_key_exists($package, $limits['packages']);
                if ($included && in_array($plan['code'], $allowed, true)) {
                    throw new InvalidArgumentException("addons.{$code}: il pacchetto è già compreso nel piano {$plan['code']}, toglilo dai piani su cui si vende");
                }
            }
        }

        return $allowed;
    }

    /**
     * @param  array<string, mixed>  $g
     * @return array<string, mixed>
     */
    private static function grants(array $g, string $kind, string $code): array
    {
        return match ($kind) {
            'operators' => ['operators' => self::int($g['operators'] ?? null, "addons.{$code}.grants.operators", 1, 1000)],
            'members' => ['members' => self::int($g['members'] ?? null, "addons.{$code}.grants.members", 1, 100_000)],
            'storage' => ['storage_gb' => self::int($g['storage_gb'] ?? null, "addons.{$code}.grants.storage_gb", 1, 10_000)],
            'package' => [
                'package' => self::code($g['package'] ?? null, "addons.{$code}.grants.package", 40),
                'limits' => self::object($g['limits'] ?? [], "addons.{$code}.grants.limits"),
            ],
            default => [],
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function prices(mixed $value, string $name): array
    {
        $out = [];
        foreach (self::list($value, $name, 1) as $i => $item) {
            $p = self::object($item, "{$name}.{$i}", ['period', 'kind', 'vat_percent', 'amount_cents', 'base_members', 'base_cents', 'tiers', 'months_billed', 'steps']);
            $period = $p['period'] ?? null;
            $kind = $p['kind'] ?? null;
            if (! in_array($period, ['month', 'year'], true)) {
                throw new InvalidArgumentException("{$name}.{$i}.period non valido");
            }
            if (! is_string($kind) || ! in_array($kind, self::PRICE_KINDS, true)) {
                throw new InvalidArgumentException("{$name}.{$i}.kind non valido");
            }
            if ($kind === 'members_tiers' && is_array($p['tiers'] ?? null) && is_array($p['tiers'][0] ?? null)
                && is_int($p['tiers'][0]['up_to'] ?? null) && is_int($p['base_members'] ?? null) && $p['tiers'][0]['up_to'] <= $p['base_members']) {
                throw new InvalidArgumentException("{$name}.{$i}: la prima fascia deve finire oltre i soci compresi nella base");
            }
            $base = ['period' => $period, 'kind' => $kind, 'vat_percent' => self::int($p['vat_percent'] ?? null, "{$name}.{$i}.vat_percent", 0, 100)];
            $out[] = $base + match ($kind) {
                'flat' => ['amount_cents' => self::cents($p['amount_cents'] ?? null, "{$name}.{$i}.amount_cents")],
                'members_tiers' => [
                    'base_members' => self::int($p['base_members'] ?? null, "{$name}.{$i}.base_members", 0, 1_000_000),
                    'base_cents' => self::cents($p['base_cents'] ?? null, "{$name}.{$i}.base_cents"),
                    'tiers' => self::tiers($p['tiers'] ?? null, "{$name}.{$i}.tiers", 'unit_cents'),
                    'months_billed' => self::int($p['months_billed'] ?? ($period === 'year' ? 12 : 1), "{$name}.{$i}.months_billed", 1, 12),
                ],
                default => ['steps' => self::tiers($p['steps'] ?? null, "{$name}.{$i}.steps", 'amount_cents')],
            };
        }

        return $out;
    }

    /**
     * Fasce con `up_to` strettamente crescente; solo l'ultima può essere `null` (= «oltre»).
     *
     * @return list<array<string, int|null>>
     */
    private static function tiers(mixed $value, string $name, string $amountKey): array
    {
        $out = [];
        $previous = 0;
        $list = self::list($value, $name, 1);
        foreach ($list as $i => $item) {
            $t = self::object($item, "{$name}.{$i}", ['up_to', $amountKey]);
            $upTo = $t['up_to'] ?? null;
            if ($upTo === null) {
                if ($i !== array_key_last($list)) {
                    throw new InvalidArgumentException("{$name}: solo l'ultima fascia può essere aperta");
                }
            } elseif (! is_int($upTo) || $upTo <= $previous) {
                throw new InvalidArgumentException("{$name}.{$i}.up_to deve crescere");
            } else {
                $previous = $upTo;
            }
            $out[] = ['up_to' => $upTo, $amountKey => self::cents($t[$amountKey] ?? null, "{$name}.{$i}.{$amountKey}")];
        }

        return $out;
    }

    /**
     * @param  list<mixed>  $planCodes
     * @return list<array<string, mixed>>
     */
    private static function promos(mixed $value, array $planCodes): array
    {
        $out = [];
        foreach (self::list($value, 'promos', 0) as $i => $item) {
            $p = self::object($item, "promos.{$i}", ['code', 'name', 'discount', 'plans', 'valid_from', 'valid_to', 'max_uses', 'stackable']);
            $code = $p['code'] ?? null;
            $discount = self::object($p['discount'] ?? null, "promos.{$i}.discount", ['type', 'value']);
            $type = $discount['type'] ?? null;
            $amount = match ($type) {
                'percent' => self::int($discount['value'] ?? null, "promos.{$i}.discount.value", 1, 100),
                'amount_cents' => self::cents($discount['value'] ?? null, "promos.{$i}.discount.value"),
                default => throw new InvalidArgumentException("promos.{$i}.discount.type non valido"),
            };
            $plans = $p['plans'] ?? null;
            if ($plans !== null) {
                foreach (self::list($plans, "promos.{$i}.plans", 1) as $plan) {
                    if (! in_array($plan, $planCodes, true)) {
                        throw new InvalidArgumentException("promos.{$i} vale su un piano sconosciuto");
                    }
                }
            }
            $from = self::date($p['valid_from'] ?? null, "promos.{$i}.valid_from");
            $to = self::date($p['valid_to'] ?? null, "promos.{$i}.valid_to");
            if ($to < $from) {
                throw new InvalidArgumentException("promos.{$i}: la fine è prima dell'inizio");
            }
            $out[] = [
                'code' => $code === null ? null : self::code($code, "promos.{$i}.code", 40, upper: true),
                'name' => self::text($p['name'] ?? null, "promos.{$i}.name", 80),
                'discount' => ['type' => (string) $type, 'value' => $amount],
                'plans' => $plans,
                'valid_from' => $from,
                'valid_to' => $to,
                'max_uses' => ($p['max_uses'] ?? null) === null ? null : self::int($p['max_uses'], "promos.{$i}.max_uses", 1, 10_000_000),
                'stackable' => self::bool($p['stackable'] ?? null, "promos.{$i}.stackable"),
            ];
        }

        return $out;
    }

    /**
     * @param  array<mixed>  $value
     * @param  list<string>  $allowed
     */
    private static function only(array $value, array $allowed, string $name): void
    {
        foreach (array_keys($value) as $key) {
            if (! in_array($key, $allowed, true)) {
                throw new InvalidArgumentException("campo sconosciuto in {$name}: {$key}");
            }
        }
    }

    /**
     * @param  list<string>|null  $allowed  null = qualunque chiave
     * @return array<string, mixed>
     */
    private static function object(mixed $value, string $name, ?array $allowed = null): array
    {
        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new InvalidArgumentException("{$name} non valido");
        }
        if ($allowed !== null) {
            self::only($value, $allowed, $name);
        }

        /** @var array<string, mixed> */
        return $value;
    }

    /**
     * @return list<mixed>
     */
    private static function list(mixed $value, string $name, int $min): array
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) < $min) {
            throw new InvalidArgumentException("{$name} non valido");
        }

        return $value;
    }

    private static function text(mixed $value, string $name, int $max): string
    {
        if (! is_string($value) || trim($value) === '' || mb_strlen($value) > $max
            || preg_match('/[\x00-\x1F\x7F<>]/', $value) === 1) {
            throw new InvalidArgumentException("{$name} non valido (testo semplice, senza HTML)");
        }

        return trim($value);
    }

    private static function code(mixed $value, string $name, int $max, bool $upper = false): string
    {
        $pattern = $upper ? '/^[A-Z0-9_-]{2,%d}$/' : '/^[a-z][a-z0-9_]{1,%d}$/';
        if (! is_string($value) || preg_match(sprintf($pattern, $max - 1), $value) !== 1) {
            throw new InvalidArgumentException("{$name} non valido");
        }

        return $value;
    }

    private static function int(mixed $value, string $name, int $min, int $max): int
    {
        return is_int($value) && $value >= $min && $value <= $max ? $value : throw new InvalidArgumentException("{$name} non valido");
    }

    /** Importi sempre in centesimi interi, mai negativi. */
    private static function cents(mixed $value, string $name): int
    {
        return self::int($value, $name, 0, 100_000_000);
    }

    private static function bool(mixed $value, string $name): bool
    {
        return is_bool($value) ? $value : throw new InvalidArgumentException("{$name} non valido");
    }

    private static function date(mixed $value, string $name): string
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1
            || ! checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))) {
            throw new InvalidArgumentException("{$name} non valida");
        }

        return $value;
    }
}
