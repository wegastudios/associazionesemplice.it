<?php

declare(strict_types=1);

namespace App\Modules\Contabilita\Listeners;

use App\Modules\Contabilita\Contracts\ReceiptIssued;
use App\Modules\Contabilita\Receipts\ReceiptFiles;
use Throwable;

/** Alla emissione fissa il PDF della ricevuta. Se non riesce l'incasso resta valido: la ricevuta si fissa dopo, da «Verifica delle ricevute». */
final readonly class FixReceiptFile
{
    public function __construct(private ReceiptFiles $files) {}

    public function handle(ReceiptIssued $event): void
    {
        try {
            $this->files->fix($event->receiptId, 'emissione');
        } catch (Throwable $e) {
            report($e);
        }
    }
}
