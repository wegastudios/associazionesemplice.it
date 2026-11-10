<?php

declare(strict_types=1);

namespace App\Modules\Portale\Entitlements;

use App\Modules\Portale\Contracts\Package;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Controllo rigoroso dei dati firmati: anche se la firma è valida, si accettano solo campi e valori previsti dal
 * contratto. Un campo sconosciuto o un valore fuori misura fa rifiutare tutto il token.
 */
final class ClaimsValidator
{
    private const STATUSES = ['trial', 'active', 'past_due', 'suspended', 'cancelled'];

    private const RESOURCE_KINDS = ['hourly', 'daily', 'seasonal'];

    /**
     * @param  array<string, mixed>  $claims
     * @return array{ref: string, org: array{name: string, slug: string}, owner: array{email: string, name: string}}
     */
    public static function provision(array $claims): array
    {
        $org = self::object($claims['org'] ?? null, 'org');
        $owner = self::object($claims['owner'] ?? null, 'owner');
        $email = self::text($owner['email'] ?? null, 'owner.email', 254);
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('owner.email non valida');
        }

        return [
            'ref' => self::ref($claims['ref'] ?? null),
            'org' => ['name' => self::text($org['name'] ?? null, 'org.name', 120), 'slug' => self::slug($org['slug'] ?? null)],
            'owner' => ['email' => Str::lower($email), 'name' => self::text($owner['name'] ?? null, 'owner.name', 120)],
        ];
    }

    /**
     * @param  array<string, mixed>  $claims
     * @return array{sub: string, ref: string, rev: int, status: string, until: string, trial_ends: string|null, members: int, operators: int, storage_gb: int, packages: array<string, array<string, mixed>>, partner: string|null}
     */
    public static function entitlements(array $claims): array
    {
        $sub = $claims['sub'] ?? null;
        if (! is_string($sub) || ! Str::isUuid($sub)) {
            throw new InvalidArgumentException('sub non valido');
        }
        $status = $claims['status'] ?? null;
        if (! in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException('status non valido');
        }
        $partner = $claims['partner'] ?? null;
        if ($partner !== null && (! is_string($partner) || preg_match('/^[A-Za-z0-9_-]{1,40}$/', $partner) !== 1)) {
            throw new InvalidArgumentException('partner non valido');
        }

        $packages = [];
        foreach (self::object($claims['packages'] ?? [], 'packages') as $code => $limits) {
            $package = Package::tryFrom((string) $code) ?? throw new InvalidArgumentException("pacchetto sconosciuto: {$code}");
            $packages[$package->value] = self::packageLimits($package, self::object($limits, "packages.{$code}"));
        }

        return [
            'sub' => $sub,
            'ref' => self::ref($claims['ref'] ?? null),
            'rev' => self::int($claims['rev'] ?? null, 'rev', 1, PHP_INT_MAX),
            'status' => $status,
            'until' => self::date($claims['until'] ?? null, 'until'),
            'trial_ends' => ($claims['trial_ends'] ?? null) === null ? null : self::date($claims['trial_ends'], 'trial_ends'),
            'members' => self::int($claims['members'] ?? null, 'members', 0, 1_000_000),
            'operators' => self::int($claims['operators'] ?? null, 'operators', 1, 1000),
            'storage_gb' => self::int($claims['storage_gb'] ?? null, 'storage_gb', 0, 10_000),
            'packages' => $packages,
            'partner' => $partner,
        ];
    }

    /**
     * @param  array<string, mixed>  $limits
     * @return array<string, mixed>
     */
    private static function packageLimits(Package $package, array $limits): array
    {
        $allowed = match ($package) {
            Package::Resources => self::RESOURCE_KINDS,
            Package::EInvoice => ['yearly'],
            Package::CustomDomain => ['domain'],
            default => [],
        };
        foreach (array_keys($limits) as $key) {
            if (! in_array($key, $allowed, true)) {
                throw new InvalidArgumentException("limite sconosciuto: {$package->value}.{$key}");
            }
        }

        return match ($package) {
            Package::Resources => array_map(static fn (mixed $kind): array => [
                'tier' => self::int(self::object($kind, 'resources')['tier'] ?? null, 'resources.tier', 1, 100_000),
                'web' => self::bool(self::object($kind, 'resources')['web'] ?? null, 'resources.web'),
            ], $limits),
            Package::EInvoice => ['yearly' => self::int($limits['yearly'] ?? null, 'einvoice.yearly', 0, 1_000_000)],
            // Nella v2 il dominio si imposta a parte (domain.set): il pacchetto può esserci senza.
            Package::CustomDomain => isset($limits['domain']) ? ['domain' => self::domain($limits['domain'])] : [],
            default => [],
        };
    }

    /**
     * Abbonamento v2 (CONTRATTO_PORTALE.md §3.2): il fatto commerciale; i limiti li ricava il SaaS dal piano.
     *
     * @param  array<string, mixed>  $claims
     * @return array{sub: string, ref: string, rev: int, status: string, until: string, trial_ends: string|null, plan: string, plan_version: int, members: int, addons: list<array{code: string, qty: int}>, promo: string|null, price_cents: int|null, partner: string|null}
     */
    public static function subscription(array $claims): array
    {
        $unknown = array_diff(array_keys($claims), ['iss', 'aud', 'typ', 'iat', 'exp', 'jti', 'sub', 'ref', 'rev', 'status', 'until', 'trial_ends', 'plan', 'plan_version', 'members', 'addons', 'promo', 'price_cents', 'partner']);
        if ($unknown !== []) {
            throw new InvalidArgumentException('campo sconosciuto: '.implode(', ', array_map('strval', $unknown)));
        }
        $base = self::entitlements($claims + ['operators' => 1, 'storage_gb' => 0, 'packages' => []]);
        $plan = $claims['plan'] ?? null;
        if (! is_string($plan) || preg_match('/^[a-z][a-z0-9_]{1,39}$/', $plan) !== 1) {
            throw new InvalidArgumentException('plan non valido');
        }
        $addons = [];
        $list = $claims['addons'] ?? [];
        if (! is_array($list) || ! array_is_list($list) || count($list) > 50) {
            throw new InvalidArgumentException('addons non valido');
        }
        foreach ($list as $item) {
            $addon = self::object($item, 'addons');
            $code = $addon['code'] ?? null;
            if (! is_string($code) || preg_match('/^[a-z][a-z0-9_]{1,39}$/', $code) !== 1) {
                throw new InvalidArgumentException('addons.code non valido');
            }
            if (array_diff(array_keys($addon), ['code', 'qty']) !== []) {
                throw new InvalidArgumentException('addons: campo sconosciuto');
            }
            $addons[] = ['code' => $code, 'qty' => self::int($addon['qty'] ?? null, 'addons.qty', 1, 1000)];
        }
        $promo = $claims['promo'] ?? null;
        if ($promo !== null && (! is_string($promo) || preg_match('/^[A-Z0-9_-]{2,40}$/', $promo) !== 1)) {
            throw new InvalidArgumentException('promo non valida');
        }
        $price = $claims['price_cents'] ?? null;

        return [
            'sub' => $base['sub'],
            'ref' => $base['ref'],
            'rev' => $base['rev'],
            'status' => $base['status'],
            'until' => $base['until'],
            'trial_ends' => $base['trial_ends'],
            'plan' => $plan,
            'plan_version' => self::int($claims['plan_version'] ?? null, 'plan_version', 1, 100_000),
            'members' => $base['members'],
            'addons' => $addons,
            'promo' => $promo,
            'price_cents' => $price === null ? null : self::int($price, 'price_cents', 0, 100_000_000),
            'partner' => $base['partner'],
        ];
    }

    /** @return array<string, mixed> */
    private static function object(mixed $value, string $name): array
    {
        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new InvalidArgumentException("{$name} non valido");
        }

        /** @var array<string, mixed> */
        return $value;
    }

    private static function text(mixed $value, string $name, int $max): string
    {
        if (! is_string($value) || trim($value) === '' || mb_strlen($value) > $max || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new InvalidArgumentException("{$name} non valido");
        }

        return trim($value);
    }

    private static function int(mixed $value, string $name, int $min, int $max): int
    {
        return is_int($value) && $value >= $min && $value <= $max ? $value : throw new InvalidArgumentException("{$name} non valido");
    }

    private static function bool(mixed $value, string $name): bool
    {
        return is_bool($value) ? $value : throw new InvalidArgumentException("{$name} non valido");
    }

    private static function date(mixed $value, string $name): string
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1 || ! checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))) {
            throw new InvalidArgumentException("{$name} non valida");
        }

        return $value;
    }

    private static function ref(mixed $value): string
    {
        return is_string($value) && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $value) === 1 ? $value : throw new InvalidArgumentException('ref non valido');
    }

    private static function slug(mixed $value): string
    {
        return is_string($value) && preg_match('/^[a-z0-9]([a-z0-9-]{1,38})[a-z0-9]$/', $value) === 1 ? $value : throw new InvalidArgumentException('org.slug non valido');
    }

    private static function domain(mixed $value): string
    {
        $domain = is_string($value) ? Str::lower($value) : '';

        return preg_match('/^(?=.{4,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain) === 1
            ? $domain
            : throw new InvalidArgumentException('custom_domain.domain non valido');
    }
}
