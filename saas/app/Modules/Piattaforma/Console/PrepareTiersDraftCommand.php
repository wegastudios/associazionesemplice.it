<?php

declare(strict_types=1);

namespace App\Modules\Piattaforma\Console;

use App\Modules\Piattaforma\Catalogo\CatalogPublisher;
use App\Modules\Piattaforma\Models\PlatformStaff;
use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Prepara nella bozza del catalogo il listino a tre piani fissi (Piccola, Media, Grande), partendo dal catalogo in vigore:
 * aggiunge i tre piani, toglie dalla vendita il piano a scaglioni con una nuova versione nascosta e rende non acquistabili
 * a parte i pacchetti ora inclusi. Non pubblica: la pubblicazione resta un gesto in /saas/admin/catalogo.
 */
final class PrepareTiersDraftCommand extends Command
{
    protected $signature = 'saas:catalog:prepare-tiers {email : email di chi risulta autore della bozza}';

    protected $description = 'Prepara nella bozza del catalogo i tre piani fissi (Piccola, Media, Grande), senza pubblicare';

    public function handle(CatalogPublisher $publisher, DatabaseManager $db): int
    {
        $staff = PlatformStaff::query()->where('email', Str::lower((string) $this->argument('email')))->first();
        if ($staff === null) {
            $this->error('Nessuna persona con questa email.');

            return self::FAILURE;
        }

        /** @var array<string, mixed> $seed */
        $seed = require config_path('catalogo.php');
        $draft = $publisher->draft();

        $draft['features'] = $seed['features'] ?? [];
        $draft['terms'] ??= $seed['terms'] ?? [];
        $draft['plans'] = self::plans(self::rows($draft['plans'] ?? null), self::rows($seed['plans'] ?? null), $db);
        $draft['addons'] = self::addons(self::rows($draft['addons'] ?? null), self::rows($seed['addons'] ?? null));

        try {
            $publisher->saveDraft($draft, $staff->id);
        } catch (InvalidArgumentException $e) {
            $this->error('La bozza non è valida: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Bozza pronta: controllala in /saas/admin/catalogo e pubblicala da lì.');

        return self::SUCCESS;
    }

    /**
     * @param  list<array<string, mixed>>  $current
     * @param  list<array<string, mixed>>  $seed
     * @return list<array<string, mixed>>
     */
    private static function plans(array $current, array $seed, DatabaseManager $db): array
    {
        $known = [];
        $latest = null;
        foreach ($current as $plan) {
            $known[self::key($plan)] = true;
            if (($plan['code'] ?? null) === 'gestionale' && is_int($plan['plan_version'] ?? null) && ($latest === null || $plan['plan_version'] > $latest['plan_version'])) {
                $latest = $plan;
            }
        }

        $plans = $current;
        // Il piano a scaglioni: la versione già venduta resta com'è, la successiva non è più in vendita.
        if ($latest !== null && ($latest['visible'] ?? null) === true && is_int($latest['plan_version'])) {
            $hidden = array_replace($latest, ['plan_version' => $latest['plan_version'] + 1, 'visible' => false]);
            $plans[] = $hidden;
            $known[self::key($hidden)] = true;
        }
        foreach ($seed as $plan) {
            if (($plan['code'] ?? null) === 'gestionale') {
                continue;
            }
            $key = self::key($plan);
            if (! isset($known[$key])) {
                $plans[] = $plan;
            } elseif (is_string($plan['code'] ?? null) && is_int($plan['plan_version'] ?? null) && ! self::wasPublished($db, $plan['code'], $plan['plan_version'])) {
                // Già in bozza ma mai pubblicato: si aggiorna ai testi del file (una versione pubblicata non si tocca).
                foreach ($plans as $i => $existing) {
                    if (self::key($existing) === $key) {
                        $plans[$i] = $plan;
                    }
                }
            }
        }

        return $plans;
    }

    /**
     * @param  list<array<string, mixed>>  $current
     * @param  list<array<string, mixed>>  $seed
     * @return list<array<string, mixed>>
     */
    private static function addons(array $current, array $seed): array
    {
        $byCode = [];
        foreach ($seed as $addon) {
            if (is_string($addon['code'] ?? null)) {
                $byCode[$addon['code']] = $addon;
            }
        }

        $out = [];
        foreach ($current as $addon) {
            $code = $addon['code'] ?? null;
            if (is_string($code) && isset($byCode[$code])) {
                $out[] = $byCode[$code];
                unset($byCode[$code]);
            } else {
                $out[] = $addon;
            }
        }

        return array_merge($out, array_values($byCode));
    }

    /** Una versione è «pubblicata» solo se sta in una revisione salvata: il listino di partenza non conta. */
    private static function wasPublished(DatabaseManager $db, string $code, int $version): bool
    {
        $found = $db->connection('pgsql')->selectOne(
            'SELECT 1 AS ok FROM core.catalog_revisions r, jsonb_array_elements(r.document->\'plans\') AS p(value) WHERE p.value->>\'code\' = ? AND (p.value->>\'plan_version\')::int = ? LIMIT 1',
            [$code, $version],
        );

        return $found !== null;
    }

    /** @param  array<string, mixed>  $plan */
    private static function key(array $plan): string
    {
        $code = is_string($plan['code'] ?? null) ? $plan['code'] : '';
        $version = is_int($plan['plan_version'] ?? null) ? $plan['plan_version'] : 0;

        return "{$code}@{$version}";
    }

    /** @return list<array<string, mixed>> */
    private static function rows(mixed $value): array
    {
        $rows = [];
        foreach (is_array($value) ? $value : [] as $row) {
            if (is_array($row)) {
                /** @var array<string, mixed> $row */
                $rows[] = $row;
            }
        }

        return $rows;
    }
}
