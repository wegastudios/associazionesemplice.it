<?php

declare(strict_types=1);

namespace App\Modules\Contabilita\Archive;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;

/**
 * Un solo lavoro alla volta sull'archivio delle ricevute di un'associazione: archivia, «Rimetti i file» e «Rifai» non
 * si sovrappongono. Il blocco scade da solo se il processo si interrompe.
 */
final class ArchiveLock
{
    public const BUSY = 'Un\'altra operazione sulle ricevute è in corso: riprova tra qualche minuto. Non è cambiato nulla.';

    public static function take(string $orgId, int $seconds): ?Lock
    {
        $lock = Cache::lock('receipts-archive:'.$orgId, $seconds);

        return $lock->get() ? $lock : null;
    }
}
