<?php

declare(strict_types=1);

namespace App\Modules\Piattaforma\Console;

use App\Modules\Piattaforma\Admin\PlatformAudit;
use Illuminate\Console\Command;

/**
 * Annota nel registro dell'amministrazione che un rilascio è partito con «ignora-saldi»: il controllo di salute non lo
 * ferma per i saldi dei conti diversi dai movimenti (RILASCIO.md). Lo lancia remote.sh prima del controllo di salute;
 * se non riesce, il rilascio si ferma.
 */
final class DeployBalancesOverrideCommand extends Command
{
    /** Nome utente GitHub: lettere, cifre e trattini, al massimo 39 caratteri. */
    private const ACTOR = '/^[A-Za-z0-9](?:[A-Za-z0-9-]{0,38})$/';

    protected $signature = 'saas:deploy:ignore-balances {actor : Utente GitHub che ha lanciato il rilascio} {revision? : Revisione rilasciata}';

    protected $description = 'Annota nel registro dell\'amministrazione un rilascio con il controllo dei saldi ignorato';

    public function handle(PlatformAudit $audit): int
    {
        $actor = (string) $this->argument('actor');
        $revision = $this->argument('revision');
        if (preg_match(self::ACTOR, $actor) !== 1) {
            $this->error('Utente del rilascio non valido.');

            return self::FAILURE;
        }
        $revision = is_string($revision) && preg_match('/^[A-Za-z0-9._-]{1,64}$/', $revision) === 1 ? $revision : null;

        $audit->record('deploy.balances_check_ignored', null, null, ['actor' => $actor, 'revision' => $revision]);
        $this->line("Rilascio con il controllo dei saldi ignorato, lanciato da {$actor}: annotato nel registro dell'amministrazione.");

        return self::SUCCESS;
    }
}
