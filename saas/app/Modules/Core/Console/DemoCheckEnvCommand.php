<?php

declare(strict_types=1);

namespace App\Modules\Core\Console;

use App\Modules\Core\Demo\DemoEnvCheck;
use Dotenv\Dotenv;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

/**
 * Controlla il .env della demo (docs/DEMO.md): dice quali variabili mancano, sono vuote, sono ancora sul valore d'esempio
 * o rompono l'isolamento della demo. Stampa solo nomi e motivi, MAI i valori (l'uscita può finire in un log pubblico).
 * Legge il file, non la configurazione in cache: si può lanciare prima di ogni rilascio, anche con `--file=`.
 */
#[AsCommand(name: 'saas:demo:check-env')]
final class DemoCheckEnvCommand extends Command
{
    protected $signature = 'saas:demo:check-env {--file= : il file .env da controllare (predefinito: quello dell\'applicazione)}';

    protected $description = 'Controlla il .env della demo: variabili mancanti, d\'esempio o che rompono l\'isolamento (mai i valori)';

    public function handle(): int
    {
        $option = $this->option('file');
        $file = is_string($option) && $option !== '' ? $option : base_path('.env');
        $content = is_file($file) ? file_get_contents($file) : false;
        if ($content === false) {
            $this->error('Il file di configurazione non c\'è o non si legge: '.basename($file));

            return self::FAILURE;
        }

        try {
            $env = Dotenv::parse($content);
        } catch (Throwable) {
            $this->error('Il file di configurazione non si legge: c\'è una riga scritta male.');

            return self::FAILURE;
        }

        $problems = DemoEnvCheck::problems($env);
        foreach ($problems as [$name, $why]) {
            $this->line("{$name}: {$why}");
        }
        if ($problems !== []) {
            $this->error(count($problems).' problemi nel .env della demo (nessun valore stampato).');

            return self::FAILURE;
        }

        $this->info('.env della demo: ok ('.count($env).' variabili controllate, nessun valore stampato).');

        return self::SUCCESS;
    }
}
