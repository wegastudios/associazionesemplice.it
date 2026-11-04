<?php

declare(strict_types=1);

namespace App\Modules\Portale\Contracts;

use Carbon\CarbonImmutable;

/**
 * Diritti di un'associazione, come li ha firmati il portale: stato dell'abbonamento, scadenza, soci e operatori,
 * pacchetti con i loro limiti. Senza diritti ricevuti vale none(): solo la base, un operatore.
 */
final readonly class OrgEntitlements
{
    /** @param  array<string, array<string, mixed>>  $packages */
    public function __construct(
        public int $rev,
        public string $status,
        public ?CarbonImmutable $until,
        public ?CarbonImmutable $trialEnds,
        public int $members,
        public int $operators,
        public int $storageGb,
        public array $packages,
        public ?string $partner,
    ) {}

    public static function none(): self
    {
        return new self(0, 'active', null, null, 0, 1, 1, [], null);
    }

    public function has(Package $package): bool
    {
        return isset($this->packages[$package->value]);
    }

    /** Limiti di un pacchetto (per esempio resources.hourly.tier), o null se il pacchetto non c'è. */
    public function limit(Package $package, string $path): mixed
    {
        $value = $this->packages[$package->value] ?? null;
        foreach (explode('.', $path) as $key) {
            if (! is_array($value) || ! array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }

        return $value;
    }
}
