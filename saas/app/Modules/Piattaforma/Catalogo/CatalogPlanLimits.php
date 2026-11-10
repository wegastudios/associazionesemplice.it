<?php

declare(strict_types=1);

namespace App\Modules\Piattaforma\Catalogo;

use App\Modules\Piattaforma\Contracts\Catalog;
use App\Modules\Piattaforma\Contracts\PlanLimits;
use App\Modules\Piattaforma\Contracts\UnknownAddon;
use App\Modules\Piattaforma\Contracts\UnknownPlan;

/**
 * - operatori = quelli del piano + Σ operatori concessi dagli addon × quantità;
 * - spazio = max(minimo, soci × MB per socio) arrotondato ai GB per eccesso + Σ GB degli addon × quantità;
 * - pacchetti = quelli del piano + quelli degli addon (`package`, `domain`, `dedicated_db`), con i loro limiti.
 */
final readonly class CatalogPlanLimits implements PlanLimits
{
    public function __construct(private Catalog $catalog) {}

    public function resolve(string $plan, int $version, array $addons, int $members): array
    {
        $found = $this->catalog->plan($plan, $version) ?? throw new UnknownPlan("{$plan}@{$version}");
        $limits = is_array($found['limits'] ?? null) ? $found['limits'] : [];

        $operators = self::int($limits['operators'] ?? null);
        $storageMb = max(self::int($limits['storage_mb_min'] ?? null), $members * self::int($limits['storage_mb_per_member'] ?? null));
        $storageGb = intdiv($storageMb + 1023, 1024);
        $packages = [];
        foreach (is_array($limits['packages'] ?? null) ? $limits['packages'] : [] as $code => $packageLimits) {
            $packages[(string) $code] = self::map($packageLimits);
        }

        foreach ($addons as $requested) {
            $addon = $this->catalog->published()->addon($requested['code']) ?? throw new UnknownAddon($requested['code']);
            $grants = is_array($addon['grants'] ?? null) ? $addon['grants'] : [];
            $operators += self::int($grants['operators'] ?? null) * $requested['qty'];
            $storageGb += self::int($grants['storage_gb'] ?? null) * $requested['qty'];

            $package = match ($addon['kind'] ?? null) {
                'package' => is_string($grants['package'] ?? null) ? $grants['package'] : null,
                'domain' => 'custom_domain',
                'dedicated_db' => 'dedicated_db',
                default => null,
            };
            if ($package !== null) {
                $packages[$package] = self::map(array_replace_recursive($packages[$package] ?? [], self::map($grants['limits'] ?? null)));
            }
        }

        return ['operators' => $operators, 'storage_gb' => $storageGb, 'packages' => $packages];
    }

    /** @return array<string, mixed> */
    private static function map(mixed $value): array
    {
        $out = [];
        foreach (is_array($value) ? $value : [] as $key => $item) {
            $out[(string) $key] = $item;
        }

        return $out;
    }

    private static function int(mixed $value): int
    {
        return is_int($value) ? $value : 0;
    }
}
