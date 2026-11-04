<?php

declare(strict_types=1);

namespace App\Modules\Soci\Contracts;

use InvalidArgumentException;

/** Dati della scheda o del tesseramento non accettati; i messaggi sono per l'operatore. */
final class PeopleError extends InvalidArgumentException
{
    /** @param  list<string>  $messages */
    public function __construct(public readonly array $messages)
    {
        parent::__construct(implode(' ', $messages));
    }
}
