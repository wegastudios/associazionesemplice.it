<?php

declare(strict_types=1);

namespace App\Modules\Core\Contracts;

/** Nomi di file e cartelle sicuri per un archivio ZIP: niente barre, due punti o caratteri di controllo. */
final class ZipName
{
    public static function clean(string $name, string $fallback = 'file'): string
    {
        $name = (string) preg_replace('/[\\\\\/:*?"<>|\x00-\x1F]+/u', ' ', $name);
        $name = trim((string) preg_replace('/\s+/u', ' ', $name), " .\t");

        return $name === '' ? $fallback : mb_substr($name, 0, 120);
    }
}
