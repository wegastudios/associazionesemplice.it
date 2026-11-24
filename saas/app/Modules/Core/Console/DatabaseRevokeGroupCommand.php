<?php

declare(strict_types=1);

namespace App\Modules\Core\Console;

use App\Modules\Core\Contracts\DatabaseNames;
use App\Modules\Core\Platform\GroupPrivileges;
use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

/**
 * Riapplica il REVOKE al ruolo di gruppo che possiede il database (cPanel, `update_privileges ... ALL`, glieli rimette tutti).
 * Con la connessione del proprietario. Agisce solo con SAAS_DB_REVOKE_GROUP acceso; `--dry-run` conta senza scrivere e
 * funziona sempre. Nessun nome di ruolo o di oggetto in uscita: solo conteggi (i log dei workflow sono pubblici).
 *
 * Uscita: 0 fatto o niente da fare (anche senza ruolo di gruppo); 1 interruttore spento, errore o permessi rimasti.
 */
#[AsCommand(name: 'saas:db:revoke-group')]
final class DatabaseRevokeGroupCommand extends Command
{
    protected $signature = 'saas:db:revoke-group
        {--dry-run : Solo conteggi per tipo, senza scrivere}
        {--if-enabled : Con l\'interruttore spento esce subito, in silenzio (per il rilascio)}';

    protected $description = 'Toglie al ruolo di gruppo del database i permessi che cPanel rimette (interruttore SAAS_DB_REVOKE_GROUP)';

    public function handle(DatabaseNames $databases, DatabaseManager $db): int
    {
        $dry = (bool) $this->option('dry-run');

        if (! config('saas.db.revoke_group') && ! $dry) {
            if ($this->option('if-enabled')) {
                return self::SUCCESS;
            }
            $this->components->error('Interruttore spento (SAAS_DB_REVOKE_GROUP): nessun permesso toccato. Con --dry-run si vedono solo i conteggi.');

            return self::FAILURE;
        }

        $removed = 0;
        $failed = false;
        foreach ($databases->names() as $index => $name) {
            $label = $index === 0 ? 'database condiviso' : 'database dedicato '.$index;
            try {
                $connection = $db->connection($databases->ownerConnection($name));
                $group = GroupPrivileges::groupRole($connection);
                if ($group === null) {
                    $this->line("{$label}: nessun ruolo di gruppo, niente da fare");

                    continue;
                }
                if ($dry) {
                    $counts = GroupPrivileges::counts(GroupPrivileges::inventory($connection, $group));
                    $this->line("{$label}: toglierebbe ".implode(', ', array_map(static fn (string $k, int $v): string => "{$k} {$v}", array_keys($counts), $counts)));

                    continue;
                }
                $result = GroupPrivileges::revoke($connection, $group);
                $removed += $result['removed'];
                if ($result['remaining'] > 0) {
                    $failed = true;
                    $this->components->warn("{$label}: {$result['remaining']} permessi del gruppo non si possono togliere con il ruolo proprietario");
                }
            } catch (Throwable $e) {
                $failed = true;
                $this->components->error("{$label}: errore (".class_basename($e).')');
            }
        }

        if (! $dry && ! $failed) {
            $this->line($removed === 0 ? 'permessi del gruppo: ok' : "tolti {$removed} permessi al gruppo");
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
