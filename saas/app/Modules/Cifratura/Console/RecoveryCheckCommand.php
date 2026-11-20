<?php

declare(strict_types=1);

namespace App\Modules\Cifratura\Console;

use App\Modules\Cifratura\Keys\KeyAudit;
use App\Modules\Cifratura\Keys\KeyMigration;
use App\Modules\Cifratura\Keys\LocalKeyVault;
use App\Modules\Cifratura\Keys\RecoveryFile;
use App\Modules\Cifratura\Keys\SecretFiles;
use App\Modules\Cifratura\Keys\VaultFactory;
use Illuminate\Console\Command;
use KeyService\KeyWrap;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

/**
 * Apre una copia di recupero con la sua frase e controlla, senza stampare nulla di segreto, che le chiavi principali
 * contenute riaprano le chiavi dati salvate. Con --ripristina scrive i file per rimettere in piedi SaaS e servizio
 * chiavi (permessi 600, mai sovrascritti).
 */
#[AsCommand(name: 'saas:keys:recovery-check')]
final class RecoveryCheckCommand extends Command
{
    use AsksPassphrase;

    /** Chiavi dati provate per ogni chiave principale. */
    private const SAMPLE = 20;

    protected $signature = 'saas:keys:recovery-check
        {file : Copia di recupero da provare}
        {--ripristina= : Cartella (fuori dal web) dove scrivere config.ini del servizio chiavi e saas.env, permessi 600}';

    protected $description = 'Prova (ed eventualmente ripristina) una copia di recupero delle chiavi';

    public function handle(VaultFactory $vaults, KeyMigration $migration, KeyAudit $audit): int
    {
        try {
            $file = $this->argument('file');
            $data = SecretFiles::read(is_string($file) ? $file : '');
            if (! RecoveryFile::looksLike($data)) {
                throw new RuntimeException('Il file non è una copia di recupero delle chiavi.');
            }
            $copy = RecoveryFile::open($data, $this->askPassphrase(false));
        } catch (Throwable $e) {
            $this->error($e instanceof RuntimeException ? $e->getMessage() : 'Copia di recupero non aperta.');

            return self::FAILURE;
        }
        $items = $copy['items'];

        $this->info("Copia aperta (creata il {$copy['created_at']}). Contiene:");
        foreach (array_keys($items) as $name) {
            $this->line('  - '.(RecoveryCreateCommand::LABELS[$name] ?? $name));
        }

        $ok = $this->checks($items, $vaults, $migration);

        $restore = $this->option('ripristina');
        if (is_string($restore) && $restore !== '') {
            try {
                $this->restore($items, $restore);
                $audit->record(KeyAudit::RECOVERY_RESTORED, ['items' => array_keys($items)]);
            } catch (Throwable $e) {
                $this->error($e instanceof RuntimeException ? $e->getMessage() : 'Ripristino non riuscito.');

                return self::FAILURE;
            }
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    /** @param  array<string, string>  $items */
    private function checks(array $items, VaultFactory $vaults, KeyMigration $migration): bool
    {
        $ok = true;
        $masters = [];

        foreach (['saas_master_key' => 'chiave principale locale', 'service_master_key' => 'chiave principale del servizio'] as $name => $label) {
            if (! isset($items[$name])) {
                continue;
            }
            $raw = base64_decode($items[$name], true);
            if ($raw === false || strlen($raw) !== KeyWrap::KEY_BYTES) {
                $this->error("ERRORE {$label}: non valida");
                $ok = false;

                continue;
            }
            $masters[$label] = $raw;
        }

        if (isset($items['saas_master_key'], $masters['chiave principale locale'])) {
            $same = $vaults->hasLocalMasterKey() && $this->sameAsConfigured($vaults, $masters['chiave principale locale']);
            $this->line('nota  chiave principale locale uguale a quella del file d\'ambiente: '.($vaults->hasLocalMasterKey() ? ($same ? 'sì' : 'no') : 'non configurata'));
        }

        if (isset($items['service_signing_key'], $items['service_client_public_key'])) {
            $secret = base64_decode($items['service_signing_key'], true);
            $public = base64_decode($items['service_client_public_key'], true);
            $match = $secret !== false && strlen($secret) === SODIUM_CRYPTO_SIGN_SECRETKEYBYTES && $public !== false
                && hash_equals(sodium_crypto_sign_publickey_from_secretkey($secret), $public);
            if ($match) {
                $this->line('ok    chiave di firma e chiave pubblica del servizio sono una coppia');
            } else {
                $this->error('ERRORE chiave di firma e chiave pubblica non sono una coppia');
                $ok = false;
            }
        }

        if (isset($masters['chiave principale del servizio']) && $vaults->hasRemote()) {
            try {
                RecoveryCreateCommand::checkServiceMaster($vaults, $masters['chiave principale del servizio']);
                $this->line('ok    il servizio chiavi usa la chiave principale della copia');
            } catch (Throwable $e) {
                $this->warn('nota  '.($e instanceof RuntimeException ? $e->getMessage() : 'prova sul servizio chiavi non riuscita'));
            }
        }

        if ($masters === []) {
            $this->warn('nota  la copia non contiene chiavi principali: da sola non basta a riaprire i dati');

            return $ok;
        }

        try {
            $vaultsByLabel = array_map(static fn (string $raw): LocalKeyVault => new LocalKeyVault($raw), $masters);
            $all = $migration->verify(array_values($vaultsByLabel), self::SAMPLE);
            if ($all['total'] === 0) {
                foreach ($vaultsByLabel as $vault) {
                    KeyMigration::probe($vault);
                }
                $this->warn('nota  nessuna chiave dati salvata: provato solo un avvolgimento di prova');

                return $ok;
            }
            foreach ($vaultsByLabel as $label => $vault) {
                $one = $migration->verify([$vault], self::SAMPLE);
                $this->line("nota  {$label}: apre {$one['open']} chiavi dati su {$one['total']} provate");
            }
            if ($all['open'] === $all['total']) {
                $this->line("ok    la copia riapre tutte le {$all['total']} chiavi dati provate");
            } else {
                $missing = $all['total'] - $all['open'];
                $this->error("ERRORE la copia non riapre {$missing} chiavi dati su {$all['total']} provate");
                $ok = false;
            }
        } catch (Throwable) {
            $this->error('ERRORE chiavi dati non provate (database non raggiungibile?)');
            $ok = false;
        }

        return $ok;
    }

    private function sameAsConfigured(VaultFactory $vaults, string $raw): bool
    {
        try {
            return hash_equals($vaults->localMasterKey(), $raw);
        } catch (Throwable) {
            return false;
        }
    }

    /** @param  array<string, string>  $items */
    private function restore(array $items, string $dir): void
    {
        if (isset($items['service_master_key'])) {
            $public = $items['service_client_public_key'] ?? '';
            $ini = "; Servizio chiavi, ripristinato da una copia di recupero. Permessi 600, fuori dalla cartella pubblica.\n"
                .'master_key = "'.$items['service_master_key']."\"\n"
                .'client_public_key = "'.$public."\"\n"
                .'allowed_ips = "'.str_replace('"', '', $items['service_allowed_ips'] ?? '')."\"\n";
            $target = SecretFiles::target(rtrim($dir, '/\\').'/config.ini');
            $bytes = SecretFiles::write($target, $ini);
            $this->info("Configurazione del servizio chiavi scritta in {$target} ({$bytes} byte, permessi 600).");
        }

        $env = '';
        foreach (['saas_master_key' => 'SAAS_MASTER_KEY', 'service_url' => 'SAAS_KEY_SERVICE_URL', 'service_signing_key' => 'SAAS_KEY_SERVICE_SIGNING_KEY'] as $name => $variable) {
            if (isset($items[$name])) {
                $env .= "{$variable}=".str_replace(["\n", "\r"], '', $items[$name])."\n";
            }
        }
        if ($env !== '') {
            $target = SecretFiles::target(rtrim($dir, '/\\').'/saas.env');
            $bytes = SecretFiles::write($target, "# Variabili del SaaS ripristinate da una copia di recupero: da riportare in shared/.env, poi cancellare questo file.\n".$env);
            $this->info("Variabili del SaaS scritte in {$target} ({$bytes} byte, permessi 600).");
        }
    }
}
