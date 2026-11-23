<?php

declare(strict_types=1);

namespace App\Modules\Core\Contracts;

use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * Istruzioni che chiedono un blocco forte (partizioni, migrazioni, tabelle cancellate): aspettano il blocco al massimo
 * pochi secondi (saas.db.lock_timeout) e riprovano, tre volte in tutto. In fila dietro di loro si fermerebbero tutte le
 * query sulla stessa tabella; il caso tipico è il backup orario, che tiene un blocco leggero su ogni tabella finché dura.
 */
final class ShortLocks
{
    public const ATTEMPTS = 3;

    /**
     * Il lavoro in una transazione con il limite di attesa del blocco: se scade, tutto annullato e si riprova.
     *
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    public static function run(ConnectionInterface $connection, Closure $work): mixed
    {
        return self::retry(static fn (): mixed => $connection->transaction(static function () use ($connection, $work): mixed {
            $connection->select("SELECT set_config('lock_timeout', ?, true)", [self::timeout()]);

            return $work();
        }));
    }

    /**
     * Ripete il tentativo solo se è scaduta l'attesa di un blocco, con una pausa che cresce; ogni altro errore passa.
     *
     * @template T
     *
     * @param  Closure(): T  $attempt
     * @return T
     */
    public static function retry(Closure $attempt): mixed
    {
        for ($i = 1; ; $i++) {
            try {
                return $attempt();
            } catch (QueryException $e) {
                if (! self::isLockTimeout($e) || $i >= self::ATTEMPTS) {
                    throw $e;
                }
                Sleep::for($i * 2)->seconds();
            }
        }
    }

    public static function isLockTimeout(Throwable $e): bool
    {
        return $e instanceof QueryException && (string) $e->getCode() === '55P03';
    }

    public static function timeout(): string
    {
        $value = config('saas.db.lock_timeout');

        return is_string($value) && $value !== '' ? $value : '3s';
    }
}
