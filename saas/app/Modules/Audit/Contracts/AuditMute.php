<?php

declare(strict_types=1);

namespace App\Modules\Audit\Contracts;

/** Stato di Audited::withoutAudit(), valido solo nel processo corrente. */
final class AuditMute
{
    public static bool $muted = false;
}
