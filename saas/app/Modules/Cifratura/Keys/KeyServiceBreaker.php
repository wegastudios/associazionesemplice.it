<?php

declare(strict_types=1);

namespace App\Modules\Cifratura\Keys;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Date;
use Throwable;

/**
 * Interruttore «servizio chiavi giù»: dopo un errore di connessione o una risposta 5xx, per 30 secondi le richieste al
 * servizio falliscono subito invece di aspettare i tempi di attesa. Lo stato è solo un istante (nessun segreto): sta
 * nella cache dell'applicazione, condivisa tra i processi anche senza APCu (Redis o database), e nella memoria del
 * processo. Se la cache non risponde vale la sola memoria del processo. Le chiavi dati non passano mai di qui.
 */
final class KeyServiceBreaker
{
    public const SECONDS = 30;

    public const CACHE_KEY = 'saas:keys:service-down-until';

    private int $until = 0;

    public function __construct(private readonly Cache $cache) {}

    public function isOpen(): bool
    {
        $now = Date::now()->getTimestamp();
        if ($this->until > $now) {
            return true;
        }

        try {
            $shared = $this->cache->get(self::CACHE_KEY);
        } catch (Throwable) {
            return false;
        }
        // Redis restituisce i numeri come stringhe.
        if (is_numeric($shared) && (int) $shared > $now) {
            $this->until = (int) $shared;

            return true;
        }

        return false;
    }

    public function trip(): void
    {
        $this->until = Date::now()->getTimestamp() + self::SECONDS;

        try {
            $this->cache->put(self::CACHE_KEY, $this->until, self::SECONDS);
        } catch (Throwable) {
            // resta l'interruttore del processo
        }
    }
}
