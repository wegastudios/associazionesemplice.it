<?php

declare(strict_types=1);

namespace App\Modules\Contabilita\Contracts;

/** Voce di un incasso: una categoria, un importo lordo in centesimi. */
final readonly class IncomeLine
{
    public function __construct(
        public string $categoryId,
        public int $amountCents,
        public string $description = '',
        public ?string $competenceMonth = null,
        public ?int $vatRate = null,
    ) {}
}
