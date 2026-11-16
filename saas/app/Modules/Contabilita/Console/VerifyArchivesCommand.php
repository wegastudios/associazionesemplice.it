<?php

declare(strict_types=1);

namespace App\Modules\Contabilita\Console;

use App\Modules\Core\Contracts\OrgContext;
use App\Modules\Core\Contracts\YearArchives;
use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

/**
 * Controllo periodico degli archivi delle ricevute: ogni archivio si apre e le impronte coincidono. Un archivio che non
 * si apre va nel log come errore, così lo si scopre prima di averne bisogno.
 */
#[AsCommand(name: 'saas:archive:verify')]
final class VerifyArchivesCommand extends Command
{
    protected $signature = 'saas:archive:verify {org? : Identificativo dell\'associazione (vuoto = tutte)}';

    protected $description = 'Controlla che gli archivi delle ricevute si aprano e le impronte coincidano';

    public function handle(OrgContext $context, YearArchives $archives, DatabaseManager $db): int
    {
        $org = $this->argument('org');
        $ids = is_string($org)
            ? [$org]
            : $db->connection('pgsql')->table('core.orgs')->whereNull('deleted_at')->orderBy('id')->pluck('id')->all();

        $bad = 0;
        $checked = 0;
        foreach ($ids as $id) {
            if (! is_string($id)) {
                continue;
            }
            try {
                foreach ($context->run($id, static fn (): array => $archives->years()) as $y) {
                    if ($y['state'] !== 'archived') {
                        continue;
                    }
                    $checked++;
                    if (! $context->run($id, static fn (): bool => $archives->check($y['year']))) {
                        $bad++;
                        Log::error('Archivio ricevute che non si apre', ['org' => $id, 'year' => $y['year']]);
                        $this->error("{$id}: l'archivio dell'anno {$y['year']} non si apre o le impronte non coincidono");
                    }
                }
            } catch (Throwable $e) {
                $bad++;
                $this->error("{$id}: {$e->getMessage()}");
            }
        }
        $this->info("Archivi controllati: {$checked}, con problemi: {$bad}");

        return $bad === 0 ? self::SUCCESS : self::FAILURE;
    }
}
