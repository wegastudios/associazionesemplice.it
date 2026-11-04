<?php

declare(strict_types=1);

namespace App\Modules\Audit\Contracts;

/** Eventi di sicurezza degli utenti, nel database centrale (core.security_events). */
interface SecurityLog
{
    /** @param  array<string, scalar|null>  $details */
    public function record(string $event, ?string $userId, array $details = []): void;
}
