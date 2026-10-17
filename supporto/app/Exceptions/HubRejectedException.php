<?php

namespace App\Exceptions;

use RuntimeException;

/** L'hub ha rifiutato la richiesta (regole del portale, credenziali): ritentare uguale non serve. */
class HubRejectedException extends RuntimeException
{
    /** @param  list<int>|null  $allowedPriorities  presente quando il rifiuto riguarda la priorita */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status,
        public readonly ?array $allowedPriorities = null,
    ) {
        parent::__construct($message);
    }
}
