<?php

declare(strict_types=1);

namespace App\Modules\Core\Console;

use App\Modules\Cifratura\Contracts\KeyVault;
use App\Modules\Core\Contracts\DatabaseNames;
use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

/**
 * Controllo di salute, usato dal rilascio prima e dopo il cambio di versione: database e migrazioni, servizio chiavi,
 * spazio dei file e dei backup. Esce con errore se qualcosa non va; non stampa mai segreti.
 */
#[AsCommand(name: 'saas:health')]
final class HealthCommand extends Command
{
    protected $signature = 'saas:health {--skip-migrations : Non controllare le migrazioni (prima di eseguirle)}';

    protected $description = 'Controlla database, migrazioni, servizio chiavi e spazi dei file';

    public function handle(DatabaseManager $db, DatabaseNames $databases, KeyVault $vault): int
    {
        $checks = [
            'database' => fn () => $db->connection('pgsql')->select('SELECT 1'),
            'servizio chiavi' => function () use ($vault): void {
                $key = random_bytes(32);
                $org = '00000000-0000-7000-8000-000000000000';
                if ($vault->unwrap($org, 1, $vault->wrap($org, 1, $key)) !== $key) {
                    throw new RuntimeException('risposta diversa');
                }
            },
            'file delle associazioni' => fn () => $this->roundTrip('org_files'),
            'spazio dei backup' => fn () => $this->roundTrip('backups'),
        ];

        if (! $this->option('skip-migrations')) {
            foreach ($databases->names() as $name) {
                $checks["migrazioni {$name}"] = function () use ($db, $databases, $name): void {
                    $done = array_filter(
                        $db->connection($databases->ownerConnection($name))->table('migrations')->pluck('migration')->all(),
                        is_string(...),
                    );
                    $kinds = $name === 'shared' ? ['Central', 'Org'] : ['Org'];
                    $files = [];
                    foreach ($kinds as $kind) {
                        foreach (glob(app_path("Modules/*/Database/Migrations/{$kind}/*.php")) ?: [] as $file) {
                            $files[] = basename($file, '.php');
                        }
                    }
                    $missing = array_diff($files, $done);
                    if ($missing !== []) {
                        throw new RuntimeException(count($missing).' da eseguire');
                    }
                };
            }
        }

        $failed = 0;
        foreach ($checks as $name => $check) {
            try {
                $check();
                $this->line("ok    {$name}");
            } catch (Throwable $e) {
                $failed++;
                $this->error("ERRORE {$name}: ".mb_substr($e->getMessage(), 0, 200));
            }
        }

        // I ripieghi in uso (posta nel log, cache sul database, chiave locale…) non sono errori, ma vanno visti.
        $warnings = config('saas.platform_warnings');
        foreach (is_array($warnings) ? $warnings : [] as $warning) {
            $this->warn('nota  '.(is_string($warning) ? $warning : ''));
        }

        if (config('saas.auth.require_two_factor') === false) {
            $this->warn('nota  Verifica in due passaggi SPENTA (SAAS_REQUIRE_TWO_FACTOR=false): solo sviluppo, dati inventati.');
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function roundTrip(string $disk): void
    {
        $path = '.health/'.bin2hex(random_bytes(8));
        Storage::disk($disk)->put($path, 'ok');
        $ok = Storage::disk($disk)->get($path) === 'ok';
        Storage::disk($disk)->delete($path);
        if (! $ok) {
            throw new RuntimeException('lettura diversa dalla scrittura');
        }
    }
}
