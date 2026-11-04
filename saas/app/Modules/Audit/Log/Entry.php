<?php

declare(strict_types=1);

namespace App\Modules\Audit\Log;

use InvalidArgumentException;

/**
 * Una riga del registro e la sua forma canonica, quella che si sigilla. La forma canonica deve uscire identica in
 * scrittura e in verifica: ora in UTC con i microsecondi, dettagli con le chiavi in ordine.
 */
final readonly class Entry
{
    /** @param  array<mixed>  $details */
    public function __construct(
        public string $orgId,
        public int $seq,
        public string $occurredAt,
        public string $actorType,
        public ?string $actorId,
        public string $action,
        public ?string $subjectType,
        public ?string $subjectId,
        public ?string $ip,
        public array $details,
    ) {}

    /** Numero di sequenza letto dal database. */
    public static function seq(mixed $value): int
    {
        return is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : throw new InvalidArgumentException('Numero non valido nel registro.');
    }

    public function canonical(): string
    {
        return json_encode([
            $this->orgId, $this->seq, $this->occurredAt, $this->actorType, $this->actorId,
            $this->action, $this->subjectType, $this->subjectId, $this->ip, self::sorted($this->details),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Solo testi brevi, interi, booleani, null e liste di questi.
     *
     * @param  array<mixed>  $details
     * @return array<mixed>
     */
    public static function validDetails(array $details, int $depth = 0): array
    {
        if ($depth > 2) {
            throw new InvalidArgumentException('Dettagli del registro troppo annidati.');
        }
        foreach ($details as $key => $value) {
            if (is_string($key) && preg_match('/^[a-z_]{1,40}$/', $key) !== 1) {
                throw new InvalidArgumentException("Chiave non valida nel registro: {$key}");
            }
            if (is_array($value)) {
                self::validDetails($value, $depth + 1);
            } elseif (is_string($value) ? mb_strlen($value) > 200 : ! (is_int($value) || is_bool($value) || $value === null)) {
                throw new InvalidArgumentException('Valore non ammesso nel registro.');
            }
        }

        return $details;
    }

    /**
     * @param  array<mixed>  $value
     * @return array<mixed>
     */
    private static function sorted(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::sorted($item);
            }
        }

        return $value;
    }
}
