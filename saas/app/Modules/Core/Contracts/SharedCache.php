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
    /** @param  string  $setting  chiave di config/saas.php con i secondi di validità (0 = spenta) */
    public static function seconds(string $setting): int
    {
        $seconds = config('saas.shared_cache.'.$setting);

        return is_int($seconds) && $seconds > 0 ? $seconds : 0;
    }

    /** La cache da usare, o null se è spenta, è il database o non si riesce ad aprirla. */
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
}
