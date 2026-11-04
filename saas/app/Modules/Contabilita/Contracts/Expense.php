<?php

declare(strict_types=1);

namespace App\Modules\Contabilita\Contracts;

final readonly class Expense
{
    public function __construct(
        public string $date,
        public string $accountId,
        public string $categoryId,
        public int $amountCents,
        public ?PaymentMethod $method = null,
        public string $description = '',
        public ?string $personId = null,
        public ?string $documentRef = null,
        public ?int $vatRate = null,
    ) {}
}
