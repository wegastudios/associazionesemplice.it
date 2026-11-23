<?php

declare(strict_types=1);

namespace App\Modules\Core\Contracts;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Cache tra una richiesta e l'altra per ciò che cambia di rado (H20): la cache generale, cioè Redis con il ripiego sul
 * database se non risponde. Se la cache generale è il database (Redis assente) non conviene: si legge la tabella.
 *
 * Regole: le chiavi che riguardano un'associazione contengono il suo id; mai dati dei soci, nomi, email o segreti
 * (Redis non è cifrato); solo array e valori semplici (la cache non ricostruisce oggetti).
 */
final class SharedCache
{
    /**
     * Durate massime decise con Audit: stato e sola lettura 60 secondi, catalogo e diritti 5 minuti. La configurazione
     * può accorciarle o spegnerle, mai allungarle.
     */
    public const MAX_SECONDS = ['status' => 60, 'entitlements' => 300, 'catalog' => 300];

    /** @param  key-of<self::MAX_SECONDS>  $setting  chiave di saas.shared_cache con i secondi di validità (0 = spenta) */
    public static function seconds(string $setting): int
    {
        $seconds = config('saas.shared_cache.'.$setting);

        return is_int($seconds) && $seconds > 0 ? min($seconds, self::MAX_SECONDS[$setting]) : 0;
    }

    /**
     * La cache da usare, o null se è spenta, è il database o non si riesce ad aprirla.
     *
     * @param  key-of<self::MAX_SECONDS>  $setting
     */
    public static function store(string $setting): ?Repository
    {
        if (self::seconds($setting) === 0) {
            return null;
        }
        $name = config('cache.default');
        $driver = is_string($name) ? config("cache.stores.{$name}.driver") : null;
        if (! is_string($name) || ! is_string($driver) || in_array($driver, ['database', 'null'], true)) {
            return null;
        }

        try {
            return Cache::store($name);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Le operazioni sulla cache non fermano mai la richiesta: una cache ferma o piena vale come vuota.
     *
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    public static function many(?Repository $cache, array $keys): array
    {
        try {
            /** @var array<string, mixed> */
            return $cache?->many($keys) ?? [];
        } catch (Throwable) {
            return [];
        }
    }

    public static function get(?Repository $cache, string $key): mixed
    {
        return self::many($cache, [$key])[$key] ?? null;
    }

    /** Scrive se la chiave non c'è; true se il valore scritto è il nostro. */
    public static function add(?Repository $cache, string $key, mixed $value, int $seconds): bool
    {
        try {
            return $cache?->add($key, $value, $seconds) ?? false;
        } catch (Throwable) {
            return false;
        }
    }

    public static function put(?Repository $cache, string $key, mixed $value, int $seconds): void
    {
        try {
            $cache?->put($key, $value, $seconds);
        } catch (Throwable) {
            // Cache ferma o piena: la volta dopo si rilegge la tabella.
        }
    }

    public static function forget(?Repository $cache, string $key): void
    {
        try {
            $cache?->forget($key);
        } catch (Throwable) {
            // La copia scade da sola (saas.shared_cache.*).
        }
    }
}
