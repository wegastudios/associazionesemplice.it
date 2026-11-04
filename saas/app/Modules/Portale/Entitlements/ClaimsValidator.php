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
            Package::CustomDomain => ['domain' => self::domain($limits['domain'] ?? null)],
            default => [],
        };
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
