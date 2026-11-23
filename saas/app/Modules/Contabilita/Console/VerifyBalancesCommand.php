<?php

declare(strict_types=1);

namespace App\Modules\Contabilita\Console;

use App\Modules\Contabilita\Ledger\BalanceVerifier;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Controllo notturno dei saldi dei conti contro i movimenti, per tutte le associazioni attive. Non corregge nulla: le
 * differenze vanno nel file con permessi 600 nella cartella condivisa; qui e nel log solo i conteggi. L'esito lo legge
 * saas:health, che con una differenza dà errore.
 */
#[AsCommand(name: 'saas:balances:verify')]
final class VerifyBalancesCommand extends Command
{
    protected $signature = 'saas:balances:verify';

    protected $description = 'Controlla che i saldi dei conti siano il saldo di partenza più i movimenti non stornati';

    public function handle(BalanceVerifier $verifier): int
    {
        $r = $verifier->verify();
        $this->line("Associazioni controllate: {$r['orgs']}, conti con saldo diverso: {$r['mismatches']}, controlli non riusciti: {$r['failed']}");
        if ($r['file'] !== null) {
            $this->line('Dettaglio in '.$r['file']);
        }

        return $r['mismatches'] === 0 && $r['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
