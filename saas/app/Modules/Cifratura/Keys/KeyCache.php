<?php

declare(strict_types=1);

namespace App\Modules\Cifratura\Keys;

use APCUIterator;
use SensitiveParameter;

/**
 * Chiavi dati sbloccate, solo in memoria: nel processo e, se c'è, in APCu per pochi minuti. Mai su disco, mai in
 * Redis o nella cache di Laravel.
 */
final class KeyCache
{
    /** @var array<string, string> */
    private array $local = [];

    public function __construct(private readonly int $ttl) {}

    public function get(string $orgId, int $version): ?string
    {
        $key = self::key($orgId, $version);
        if (isset($this->local[$key])) {
            return $this->local[$key];
        }
        if ($this->apcu()) {
            $value = apcu_fetch($key, $found);
            if ($found && is_string($value)) {
                return $this->local[$key] = $value;
            }
        }

        return null;
    }

    public function put(string $orgId, int $version, #[SensitiveParameter] string $root): void
    {
        $key = self::key($orgId, $version);
        $this->local[$key] = $root;
        if ($this->apcu()) {
            apcu_store($key, $root, $this->ttl);
        }
    }

    public function forget(string $orgId): void
    {
        foreach (array_keys($this->local) as $key) {
            if (str_starts_with($key, "as:orgkey:{$orgId}:")) {
                unset($this->local[$key]);
                if ($this->apcu()) {
                    apcu_delete($key);
                }
            }
        }
        if ($this->apcu()) {
            apcu_delete(new APCUIterator('/^as:orgkey:'.preg_quote($orgId, '/').':/'));
        }
    }

    private function apcu(): bool
    {
        return function_exists('apcu_enabled') && apcu_enabled();
    }

    private static function key(string $orgId, int $version): string
    {
        return "as:orgkey:{$orgId}:{$version}";
    }
}
