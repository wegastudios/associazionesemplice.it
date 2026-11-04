<?php

declare(strict_types=1);

namespace App\Modules\Contabilita\Contracts;

/** Incasso: una o più voci, un solo conto e una sola modalità; con $receipt riceve il numero di ricevuta. $activityId è il centro di costo. */
final readonly class Income
{
    /** @param  list<IncomeLine>  $lines */
    public function __construct(
        public string $date,
        public string $accountId,
        public array $lines,
        public ?PaymentMethod $method = null,
        public ?string $personId = null,
        public bool $receipt = true,
        public ?string $documentRef = null,
        public ?string $activityId = null,
    ) {}
}
