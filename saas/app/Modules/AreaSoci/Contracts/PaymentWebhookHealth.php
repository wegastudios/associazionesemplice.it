<?php

declare(strict_types=1);

namespace App\Modules\AreaSoci\Contracts;

use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Eventi dei webhook dei pagamenti rimasti in coda (H14): la passata ogni 5 minuti (saas:payments:requeue-webhooks)
 * scrive quanti sono in attesa da più di STALE_MINUTES in tutte le associazioni; saas:health lo legge e avvisa.
 */
final class PaymentWebhookHealth
{
    /** Dopo quanti minuti un evento ancora in coda senza lavoro torna in coda. */
    public const REQUEUE_MINUTES = 5;

    /** Oltre questi minuti in coda saas:health avvisa. */
    public const STALE_MINUTES = 30;

    private const CACHE_KEY = 'saas:payments:webhook-stale';

    public static function record(int $stale): void
    {
        Cache::put(self::CACHE_KEY, $stale, 3600);
    }

    /** Per saas:health: errore se l'ultima passata ha trovato eventi in coda da troppo tempo. */
    public static function check(): void
    {
        $stale = Cache::get(self::CACHE_KEY);
        if (is_int($stale) && $stale > 0) {
            throw new RuntimeException(sprintf('eventi dei pagamenti online in coda da più di %d minuti: %d', self::STALE_MINUTES, $stale));
        }
    }
}
