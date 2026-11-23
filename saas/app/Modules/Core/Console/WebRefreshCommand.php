<?php

declare(strict_types=1);

namespace App\Modules\Core\Console;

use App\Modules\Portale\Contracts\WebRuntimeReset;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Dopo il cambio di versione (H22): azzera OPcache e la cache dei percorsi del PHP web con la rotta firmata, poi
 * qualche richiesta di riscaldamento. Non fallisce mai: è un aiuto per i primi secondi dopo il rilascio, non una condizione.
 */
#[AsCommand(name: 'saas:web:refresh')]
final class WebRefreshCommand extends Command
{
    protected $signature = 'saas:web:refresh {--no-warm : Solo l\'azzeramento, senza richieste di riscaldamento}';

    protected $description = 'Azzera OPcache e la cache dei percorsi del PHP web e riscalda le pagine principali';

    public function handle(WebRuntimeReset $reset): int
    {
        $result = $reset->request();
        if ($result === null) {
            $this->warn('PHP web non raggiungibile con la rotta firmata: OPcache non azzerato (il codice nuovo arriva comunque entro pochi secondi).');
        } else {
            $this->line('OPcache '.($result['opcache_reset'] ? 'azzerato' : 'non azzerato (assente o non permesso)').'; cache dei percorsi azzerata.');
        }

        if (! $this->option('no-warm')) {
            foreach ($reset->warm() as $path => $status) {
                $this->line('riscaldamento '.$path.': '.($status ?? 'nessuna risposta'));
            }
        }

        return self::SUCCESS;
    }
}
