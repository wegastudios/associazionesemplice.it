<?php

declare(strict_types=1);

namespace App\Modules\Cifratura\Files;

use App\Modules\Cifratura\Contracts\EncryptedFiles;
use App\Modules\Cifratura\Contracts\StorageFull;
use App\Modules\Cifratura\Contracts\StorageQuota;
use App\Modules\Portale\Contracts\Entitlements;

/** Spazio dei file secondo il piano (vedi il contratto). */
final readonly class StorageQuotaService implements StorageQuota
{
    public function __construct(
        private Entitlements $entitlements,
        private EncryptedFiles $files,
    ) {}

    public function assertRoom(string $orgId, int $incomingBytes): void
    {
        $limitGb = $this->entitlements->for($orgId)->storageGb;
        if ($limitGb <= 0) {
            return;
        }
        if ($this->files->usedBytes($orgId) + max(0, $incomingBytes) > $limitGb * 1024 ** 3) {
            throw new StorageFull('Lo spazio del piano è esaurito.');
        }
    }
}
