<?php

declare(strict_types=1);

namespace App\Modules\Cifratura\Console;

use App\Modules\Cifratura\Keys\KeyAudit;
use App\Modules\Cifratura\Keys\KeyMigration;
use App\Modules\Cifratura\Keys\RecoveryFile;
use App\Modules\Cifratura\Keys\SecretFiles;
use App\Modules\Cifratura\Keys\VaultFactory;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

/**
 * La vecchia chiave principale locale dopo il passaggio al servizio chiavi. Senza opzioni dice a che punto si è; con
 * --distruggi registra la distruzione e cancella le copie di recupero indicate, ma solo se sono passati almeno 30
 * giorni dal passaggio (i backup più vecchi, con le chiavi avvolte in locale, sono spariti), il SaaS usa il servizio
 * chiavi, il servizio apre tutte le chiavi dati e SAAS_MASTER_KEY non è più nel file d'ambiente.
 */
#[AsCommand(name: 'saas:keys:local-retire')]
final class RetireLocalKeyCommand extends Command
{
    /** Quanto si tiene la vecchia chiave dopo il passaggio: quanto restano i backup giornalieri. */
    public const DAYS = 30;

    protected $signature = 'saas:keys:local-retire
        {--distruggi : Registra la distruzione della chiave locale (non prima di 30 giorni dal passaggio)}
        {--file=* : Copie di recupero sul server che contengono la vecchia chiave: si ricoprono e si cancellano}
        {--force : Senza conferma}';

    protected $description = 'Stato e distruzione della vecchia chiave principale locale dopo il passaggio al servizio chiavi';

    public function handle(KeyAudit $audit, VaultFactory $vaults, KeyMigration $migration, Repository $config): int
    {
        $switch = $audit->lastSwitch();
        if ($switch === null) {
            $this->line('Nessun passaggio al servizio chiavi registrato.');

            return $this->option('distruggi') === true ? self::FAILURE : self::SUCCESS;
        }
        if ($switch['to'] === 'local-nuova') {
            $this->line('L\'ultimo passaggio registrato ('.$switch['at']->format('d/m/Y').') è il cambio tra due chiavi locali: la chiave vecchia si toglie da SAAS_MASTER_KEY_OLD e si distrugge secondo ROTAZIONE_CHIAVI.md; qui non c\'è nulla da registrare.');

            return $this->option('distruggi') === true ? self::FAILURE : self::SUCCESS;
        }
        if ($switch['to'] !== 'remote') {
            $this->line('L\'ultimo passaggio registrato ('.$switch['at']->format('d/m/Y').') è il ritorno alla chiave locale: non c\'è nulla da distruggere.');

            return $this->option('distruggi') === true ? self::FAILURE : self::SUCCESS;
        }

        $allowedFrom = $switch['at']->addDays(self::DAYS);
        $this->line('Passaggio al servizio chiavi: '.$switch['at']->format('d/m/Y H:i').'.');
        if ($audit->destroyedSince($switch['at'])) {
            $this->info('La distruzione della chiave locale è già registrata.');

            return self::SUCCESS;
        }
        $this->line('La chiave locale si può distruggere dal '.$allowedFrom->format('d/m/Y H:i').'.');

        if ($this->option('distruggi') !== true) {
            return self::SUCCESS;
        }

        if ($allowedFrom->isFuture()) {
            $this->error('Troppo presto: la chiave locale resta almeno '.self::DAYS.' giorni dopo il passaggio (servono per i backup più vecchi).');

            return self::FAILURE;
        }
        if ($config->get('saas.crypto.vault') !== 'remote') {
            $this->error('Il SaaS non usa il servizio chiavi (SAAS_KEY_VAULT non è remote): niente distruzione.');

            return self::FAILURE;
        }
        if ($vaults->hasLocalMasterKey()) {
            $this->error('SAAS_MASTER_KEY è ancora nel file d\'ambiente: toglila, php artisan config:cache, poi ripeti.');

            return self::FAILURE;
        }

        try {
            $check = $migration->verify([$vaults->remote()]);
        } catch (Throwable) {
            $check = null;
        }
        if ($check === null || $check['open'] !== $check['total']) {
            $this->error('Il servizio chiavi non apre tutte le chiavi dati: niente distruzione. Controlla con saas:keys:rewrap remote --dry-run.');

            return self::FAILURE;
        }

        $files = $this->option('file');
        $files = is_array($files) ? array_values(array_filter($files, is_string(...))) : [];
        foreach ($files as $file) {
            try {
                if (! RecoveryFile::looksLike(substr(SecretFiles::read($file), 0, 4))) {
                    $this->error("{$file} non è una copia di recupero delle chiavi: non si tocca.");

                    return self::FAILURE;
                }
            } catch (Throwable) {
                $this->error("{$file} non esiste o non è leggibile.");

                return self::FAILURE;
            }
        }

        if ($this->option('force') !== true && ! $this->confirm('Registrare la distruzione della chiave locale'.($files === [] ? '' : ' e cancellare '.count($files).' copie di recupero').'? Non si torna indietro.')) {
            return self::FAILURE;
        }

        foreach ($files as $file) {
            SecretFiles::shred($file);
            $this->line("Cancellata: {$file}");
        }

        $audit->record(KeyAudit::LOCAL_DESTROYED, [
            'switched_at' => $switch['at']->toIso8601String(),
            'files' => count($files),
            'keys_checked' => $check['total'],
        ]);

        $this->info('Distruzione della chiave locale registrata.');
        $this->line('Ancora a mano: distruggi le copie offline delle vecchie copie di recupero (quelle con la chiave locale).');
        $this->line('Resta solo la copia nuova, fatta e provata prima di questo comando (docs/CHIAVE_REMOTA.md).');

        return self::SUCCESS;
    }
}
