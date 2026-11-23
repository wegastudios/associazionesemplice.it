<?php

declare(strict_types=1);

namespace App\Modules\Backup\Contracts;

/**
 * Compressione dei dump (H35): zstd se il pg_dump del server la sa fare, altrimenti gzip da solo. Qui l'esito per
 * saas:health: è solo un avviso, mai un errore.
 */
interface BackupCompression
{
    /** Vero se si voleva zstd ma questo pg_dump non lo supporta e il backup usa gzip. */
    public function fellBackToGzip(): bool;
}
