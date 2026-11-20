<?php

declare(strict_types=1);

namespace App\Modules\Piattaforma\Console;

use App\Modules\Piattaforma\Contracts\Catalog;
use App\Modules\Portale\Contracts\PortalNotifier;
use Illuminate\Console\Command;

/**
 * Rimanda al portale l'avviso firmato «catalogo cambiato» per la revisione in vigore. Serve quando un avviso non è
 * arrivato: il portale ricarica il catalogo solo da quell'avviso (o aprendo il listino o comprando).
 */
final class NotifyPortalCatalogCommand extends Command
{
    protected $signature = 'saas:catalog:notify-portal';

    protected $description = 'Avvisa il portale che il catalogo in vigore è cambiato (rimanda l\'avviso per la revisione corrente)';

    public function handle(Catalog $catalog, PortalNotifier $notifier): int
    {
        $rev = $catalog->published()->rev;
        $delivered = $notifier->catalogChanged($rev);
        $this->line("Revisione {$rev}: avviso ".($delivered ? 'consegnato al portale.' : 'NON consegnato (il portale non ha risposto con successo).'));

        return $delivered ? self::SUCCESS : self::FAILURE;
    }
}
