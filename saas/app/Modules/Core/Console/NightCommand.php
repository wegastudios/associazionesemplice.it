<?php

declare(strict_types=1);

namespace App\Modules\Core\Console;

use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

/**
 * I controlli notturni, uno dopo l'altro (H36): prima giravano ognuno col suo orario e potevano accavallarsi (la
 * domenica due verifiche del registro e quella degli archivi insieme, sopra il dump). Un controllo che fallisce non
 * ferma i successivi: il comando esce con errore alla fine, come prima per lo scheduler.
 *
 * Ordine: ricevute, copia delle teste del registro, verifica del registro (dal punto di controllo, più un settimo delle
 * associazioni per intero a rotazione), saldi dei conti, e la domenica gli archivi delle ricevute.
 */
#[AsCommand(name: 'saas:night')]
final class NightCommand extends Command
{
    protected $signature = 'saas:night';

    protected $description = 'Controlli notturni in sequenza: ricevute, registro, saldi, archivi';

    public function handle(): int
    {
        $steps = [
            ['saas:receipts:verify', []],
            ['saas:audit:heads:copy', []],
            ['saas:audit:verify', ['--rotate' => true]],
            ['saas:balances:verify', []],
        ];
        if (now()->isSunday()) {
            $steps[] = ['saas:archive:verify', []];
        }

        $failed = [];
        foreach ($steps as [$command, $arguments]) {
            $this->line("== {$command}");
            try {
                $status = $this->call($command, $arguments);
            } catch (Throwable $e) {
                report($e);
                $this->error("{$command}: {$e->getMessage()}");
                $status = self::FAILURE;
            }
            if ($status !== self::SUCCESS) {
                $failed[] = $command;
            }
        }

        if ($failed !== []) {
            $this->error('Controlli notturni con errori: '.implode(', ', $failed));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
