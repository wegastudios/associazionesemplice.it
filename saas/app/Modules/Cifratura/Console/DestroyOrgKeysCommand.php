<?php

declare(strict_types=1);

namespace App\Modules\Cifratura\Console;

use App\Modules\Cifratura\Keys\KeyRing;
use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Distrugge le chiavi di un'associazione già cancellata: i suoi dati cifrati, anche nei backup, non si aprono più.
 * Irreversibile. Passa dal ruolo proprietario: il ruolo dell'applicazione non può cancellare le chiavi.
 */
#[AsCommand(name: 'saas:keys:destroy')]
final class DestroyOrgKeysCommand extends Command
{
    protected $signature = 'saas:keys:destroy {org : Identificativo dell\'associazione} {--force : Senza conferma}';

    protected $description = 'Distrugge le chiavi di un\'associazione cancellata (irreversibile)';

    public function handle(DatabaseManager $db, KeyRing $keys): int
    {
        $orgId = (string) $this->argument('org');
        $owner = $db->connection('pgsql_owner');

        $org = $owner->table('core.orgs')->where('id', $orgId)->first(['id', 'deleted_at']);
        if ($org === null || ! isset($org->deleted_at)) {
            $this->error('Si distruggono solo le chiavi di un\'associazione già cancellata.');

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm("Distruggere per sempre le chiavi di {$orgId}?")) {
            return self::FAILURE;
        }

        $deleted = $owner->table('core.org_keys')->where('org_id', $orgId)->delete();
        $keys->forget($orgId);

        $this->info("Chiavi distrutte: {$deleted}.");

        return self::SUCCESS;
    }
}
