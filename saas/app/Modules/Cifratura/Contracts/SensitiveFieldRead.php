<?php

declare(strict_types=1);

namespace App\Modules\Cifratura\Contracts;

/**
 * Evento: è stato decifrato un campo marcato come sanitario (Encrypted::class.':health'). Il registro delle azioni
 * lo annota: per i dati sanitari si registrano anche le letture, non solo le modifiche.
 */
final readonly class SensitiveFieldRead
{
    public function __construct(
        public string $orgId,
        public string $table,
        public string $field,
        public ?string $subjectId,
    ) {}
}
