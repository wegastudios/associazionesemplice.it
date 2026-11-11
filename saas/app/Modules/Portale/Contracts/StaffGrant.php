<?php

declare(strict_types=1);

namespace App\Modules\Portale\Contracts;

/** Contenuto verificato di un token `staff_sso`. */
final readonly class StaffGrant
{
    public function __construct(
        public string $email,
        public string $level,
        public string $sid,
        public string $jti,
        public int $expiresAt,
        /** Quando la persona ha fatto il secondo fattore al portale; null = non l'ha usato (è facoltativo). */
        public ?int $mfaAt,
    ) {}
}
