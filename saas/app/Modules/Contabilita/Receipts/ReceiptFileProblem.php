<?php

declare(strict_types=1);

namespace App\Modules\Contabilita\Receipts;

use RuntimeException;

/** Il PDF salvato di una ricevuta manca, è alterato o non si può rifare. Mai rifatto di nascosto. */
final class ReceiptFileProblem extends RuntimeException
{
    public const MISSING = 'missing';

    public const ALTERED = 'altered';

    public const CHANGED = 'changed';

    public function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }
}
