<?php

declare(strict_types=1);

namespace App\Modules\Portale\Console;

use App\Modules\Portale\Entitlements\PortalSync;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'saas:portal:suspend-expired')]
final class SuspendExpiredCommand extends Command
{
    protected $signature = 'saas:portal:suspend-expired';

    protected $description = 'Sospende le associazioni con l\'abbonamento scaduto oltre la tolleranza';

    public function handle(PortalSync $sync): int
    {
        $ids = $sync->suspendExpired(CarbonImmutable::today());
        $this->info('Associazioni sospese: '.count($ids));

        return self::SUCCESS;
    }
}
