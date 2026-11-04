<?php

declare(strict_types=1);

namespace App\Modules\Core\Contracts;

use InvalidArgumentException;

/**
 * Lettura tipizzata dei dati già validati di un modulo ($request->validate() restituisce valori senza tipo). Un valore
 * del tipo sbagliato è un errore di programmazione: la validazione avrebbe dovuto fermarlo.
 */
final class Input
{
    /** @return array<mixed> */
    public static function of(mixed $validated): array
    {
        return is_array($validated) ? $validated : throw new InvalidArgumentException('Dati non validi.');
    }

    /** @param  array<mixed>  $data */
    public static function string(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        return is_string($value) ? $value : throw new InvalidArgumentException("Campo {$key} mancante.");
    }

    /** @param  array<mixed>  $data */
    public static function optionalString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @param  array<mixed>  $data */
    public static function bool(array $data, string $key, bool $default): bool
    {
        $value = $data[$key] ?? $default;

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    /** @param  array<mixed>  $data */
    public static function raw(array $data, string $key): mixed
    {
        return $data[$key] ?? null;
    }

    /**
     * @param  array<mixed>  $data
     * @return list<array<mixed>>
     */
    public static function rows(array $data, string $key): array
    {
        $value = $data[$key] ?? [];
        if (! is_array($value)) {
            throw new InvalidArgumentException("Campo {$key} non valido.");
        }

        return array_values(array_filter($value, is_array(...)));
    }
}
