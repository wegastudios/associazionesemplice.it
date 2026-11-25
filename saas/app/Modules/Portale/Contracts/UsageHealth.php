<?php

declare(strict_types=1);

namespace App\Modules\Portale\Contracts;

use App\Modules\Core\Contracts\DemoScale;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Esito dell'invio notturno dei consumi al portale (saas:portal:usage), per saas:health. Nella cache solo due istanti:
 * l'ultimo invio riuscito e il primo tentativo; mai indirizzi, errori o dati delle associazioni.
 */
final class UsageHealth
{
    /** Oltre questi secondi senza un invio riuscito (due notti di fila) saas:health avvisa. */
    public const MAX_AGE = 2 * 86400;

    private const CACHE_KEY = 'saas:portal:usage:last';

    /** Chiamato dal comando a ogni invio vero (non dal --dry-run). */
    public static function record(bool $ok): void
    {
        $now = now()->getTimestamp();
        $last = self::read();
        Cache::forever(self::CACHE_KEY, [
            'ok' => $ok ? $now : ($last['ok'] ?? null),
            'since' => $last['since'] ?? $now,
        ]);
    }

    /** Per saas:health: avviso se l'ultimo invio riuscito è più vecchio di due giorni; muto se il portale non è collegato. */
    public static function check(): void
    {
        $key = config('saas.portal.signing_key');
        if (! is_string($key) || $key === '' || DemoScale::active()) {
            return;
        }
        $last = self::read();
        if ($last === null) {
            throw new RuntimeException('nessun invio dei consumi al portale registrato dopo la configurazione del collegamento');
        }
        $since = $last['ok'] ?? $last['since'];
        if (now()->getTimestamp() - $since <= self::MAX_AGE) {
            return;
        }
        throw new RuntimeException($last['ok'] === null
            ? 'i consumi non sono mai stati accettati dal portale: provare saas:portal:usage e leggere il log'
            : 'ultimo invio dei consumi al portale riuscito il '.now()->setTimestamp($last['ok'])->format('d/m/Y H:i').', più di due giorni fa: controllare il log');
    }

    /** @return array{ok: int|null, since: int}|null */
    private static function read(): ?array
    {
        $r = Cache::get(self::CACHE_KEY);
        if (! is_array($r) || ! is_int($r['since'] ?? null) || ! (is_int($r['ok'] ?? null) || ($r['ok'] ?? null) === null)) {
            return null;
        }

        return ['ok' => $r['ok'], 'since' => $r['since']];
    }
}
