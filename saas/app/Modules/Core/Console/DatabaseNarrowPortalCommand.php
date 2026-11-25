<?php

declare(strict_types=1);

namespace App\Modules\Core\Console;

use App\Modules\Core\Contracts\DatabaseNames;
use App\Modules\Core\Contracts\PortalGrants;
use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

/**
 * Porta i permessi diretti del ruolo del portale sullo schema `portal` a quelli dell'elenco esplicito (`PortalGrants`).
 * Non parte mai da solo (né con le migrazioni né con il rilascio): si lancia a mano. `--dry-run` elenca per tabella e
 * permesso cosa si concederebbe e cosa si toglierebbe, senza scrivere, e funziona sempre; senza `--dry-run` agisce solo con
 * SAAS_DB_NARROW_PORTAL acceso. L'applicazione è atomica (una transazione, verifica prima delle revoche). Nessun nome di
 * ruolo in uscita; nomi di tabella e permessi sì (l'uscita va su un file riservato).
 *
 * Uscita: 0 fatto o niente da fare (anche senza ruolo del portale); 1 interruttore spento, errore o verifica fallita.
 */
#[AsCommand(name: 'saas:db:narrow-portal')]
final class DatabaseNarrowPortalCommand extends Command
{
    protected $signature = 'saas:db:narrow-portal {--dry-run : Solo l\'elenco di cosa cambierebbe, senza scrivere}';

    protected $description = 'Porta i permessi diretti del ruolo del portale a quelli dell\'elenco (interruttore SAAS_DB_NARROW_PORTAL)';

    public function handle(DatabaseNames $databases, DatabaseManager $db): int
    {
        $dry = (bool) $this->option('dry-run');

        if (! config('saas.db.narrow_portal') && ! $dry) {
            $this->components->error('Interruttore spento (SAAS_DB_NARROW_PORTAL): nessun permesso toccato. Con --dry-run si vede cosa cambierebbe.');

            return self::FAILURE;
        }

        try {
            $connection = $db->connection($databases->ownerConnection($databases->names()[0] ?? null));
            $role = PortalGrants::validRole($connection->getConfig('portal_role'));
            if (! PortalGrants::applicable($connection, $role)) {
                $this->line('ruolo del portale non presente: niente da fare');

                return self::SUCCESS;
            }

            $plan = PortalGrants::plan($connection, $role);
            if ($dry) {
                foreach ($plan as [$op, $object, $verbs]) {
                    $this->line("{$op} {$object}: ".implode(', ', $verbs));
                }
                $this->line($plan === [] ? 'permessi del portale: già a posto' : 'permessi del portale: '.count($plan).' modifiche previste, nessuna scritta');

                return self::SUCCESS;
            }

            $result = PortalGrants::apply($connection, $role);
            $this->line($plan === [] ? 'permessi del portale: già a posto' : "permessi del portale: concessi {$result['granted']}, tolti {$result['revoked']}");

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->components->error('permessi del portale: errore ('.class_basename($e).'), nessuna modifica');

            return self::FAILURE;
        }
    }
}
