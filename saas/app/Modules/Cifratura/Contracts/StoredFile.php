<?php

declare(strict_types=1);

namespace App\Modules\Cifratura\Contracts;

/** File cifrato: ciò che resta nel database (nome, dimensione, tipo, impronta). Il contenuto sta fuori. */
final readonly class StoredFile
{
    public function __construct(
        public string $path,
        public string $originalName,
        public string $mime,
        public int $size,
        public string $sha256,
    ) {}
}
