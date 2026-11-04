<?php

declare(strict_types=1);

namespace App\Modules\Cifratura\Keys;

use SensitiveParameter;

/**
 * Chiave dati di un'associazione, in chiaro solo in memoria. Da lei si derivano sottochiavi separate per i campi,
 * per le impronte di ricerca e per i file: una non serve a ricavare le altre.
 */
final readonly class OrgKey
{
    private const CONTEXT = 'asorgkey';

    public function __construct(
        public int $version,
        #[SensitiveParameter] private string $root,
    ) {}

    public function fieldKey(): string
    {
        return $this->derive(1);
    }

    public function indexKey(): string
    {
        return $this->derive(2);
    }

    public function fileKey(): string
    {
        return $this->derive(3);
    }

    private function derive(int $id): string
    {
        return sodium_crypto_kdf_derive_from_key(32, $id, self::CONTEXT, $this->root);
    }

    /** @return array<string, int> */
    public function __debugInfo(): array
    {
        return ['version' => $this->version];
    }
}
