<?php

declare(strict_types=1);

namespace App\Modules\Contabilita\Contracts;

/** Evento: è stata emessa una ricevuta (dentro l'associazione, a incasso registrato): si fissa il suo PDF. */
final readonly class ReceiptIssued
{
    public function __construct(public string $receiptId) {}
}
