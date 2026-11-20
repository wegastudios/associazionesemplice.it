<?php

declare(strict_types=1);

namespace App\Modules\Cifratura\Console;

use App\Modules\Cifratura\Keys\KeyAudit;
use App\Modules\Cifratura\Keys\KeyMigration;
use App\Modules\Cifratura\Keys\RecoveryFile;
use App\Modules\Cifratura\Keys\SecretFiles;
use App\Modules\Cifratura\Keys\VaultFactory;
use Illuminate\Console\Command;
use KeyService\KeyWrap;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

/**
 * Copia di recupero delle chiavi: un file cifrato con una frase segreta scritta da chi lo crea, con la chiave principale
 * locale (se c'è), indirizzo e chiave di firma del servizio chiavi e, con --con-servizio, la chiave principale del
 * servizio (chiesta con un prompt nascosto e provata sul servizio prima di salvarla). Non stampa nulla di segreto.
 */
#[AsCommand(name: 'saas:keys:recovery')]
final class RecoveryCreateCommand extends Command
{
    use AsksPassphrase;

    /** Nomi delle voci a video: mai i valori. */
    public const LABELS = [
        'saas_master_key' => 'chiave principale locale (SAAS_MASTER_KEY)',
        'service_url' => 'indirizzo del servizio chiavi',
        'service_signing_key' => 'chiave di firma del SaaS (SAAS_KEY_SERVICE_SIGNING_KEY)',
        'service_master_key' => 'chiave principale del servizio chiavi (master_key)',
        'service_client_public_key' => 'chiave pubblica del SaaS (client_public_key)',
        'service_allowed_ips' => 'indirizzi ammessi dal servizio (allowed_ips)',
    ];

    protected $signature = 'saas:keys:recovery
        {file : File da creare, fuori dalla cartella web (per esempio ~/chiavi-recupero-2026-11.askr)}
        {--con-servizio : Chiede anche la chiave principale del servizio chiavi (prompt nascosto) e gli indirizzi ammessi}';

    protected $description = 'Crea la copia di recupero cifrata delle chiavi, con una frase segreta';

    public function handle(VaultFactory $vaults, KeyAudit $audit): int
    {
        try {
            $file = $this->argument('file');
            $target = SecretFiles::target(is_string($file) ? $file : '');
            $items = $this->collect($vaults);

            $passphrase = $this->askPassphrase(true);
            $sealed = RecoveryFile::seal($items, $passphrase);
            // Si riapre subito: un file che non si apre non deve sembrare una copia buona.
            if (RecoveryFile::open($sealed, $passphrase)['items'] !== $items) {
                throw new RuntimeException('La copia appena creata non si riapre uguale: non salvata.');
            }
            $bytes = SecretFiles::write($target, $sealed);
        } catch (Throwable $e) {
            $this->error($e instanceof RuntimeException ? $e->getMessage() : 'Copia di recupero non creata.');

            return self::FAILURE;
        }

        $this->info("Copia di recupero scritta in {$target} ({$bytes} byte, permessi 600).");
        $this->line('Contiene:');
        foreach (array_keys($items) as $name) {
            $this->line('  - '.(self::LABELS[$name] ?? $name));
        }

        try {
            $audit->record(KeyAudit::RECOVERY_CREATED, ['items' => array_keys($items), 'bytes' => $bytes]);
        } catch (Throwable) {
            $this->warn('Riga del registro non scritta (database non raggiungibile?).');
        }

        $this->newLine();
        $this->line('Prossimi passi: provala con «php artisan saas:keys:recovery-check '.$target.'», poi copiala offline');
        $this->line('in due posti diversi e cancellala dal server. La frase segreta si conserva separata dal file.');

        return self::SUCCESS;
    }

    /** @return array<string, string> */
    private function collect(VaultFactory $vaults): array
    {
        $items = [];
        if ($vaults->hasLocalMasterKey()) {
            $items['saas_master_key'] = base64_encode($vaults->localMasterKey());
        }
        if ($vaults->hasRemote()) {
            $items['service_url'] = $vaults->serviceUrl();
            $items['service_signing_key'] = base64_encode($vaults->signingKey());
        }

        if ($this->option('con-servizio') === true) {
            if (! $vaults->hasRemote()) {
                throw new RuntimeException('Con --con-servizio servono SAAS_KEY_SERVICE_URL e SAAS_KEY_SERVICE_SIGNING_KEY nel file d\'ambiente.');
            }
            $master = base64_decode($this->hidden('Chiave principale del servizio chiavi (master_key di config.ini)'), true);
            if ($master === false || strlen($master) !== KeyWrap::KEY_BYTES) {
                throw new RuntimeException('La chiave principale del servizio non è valida (base64 di 32 byte).');
            }
            self::checkServiceMaster($vaults, $master);

            $ips = $this->ask('Indirizzi del SaaS ammessi dal servizio (allowed_ips, separati da virgole)');
            $items['service_master_key'] = base64_encode($master);
            $items['service_client_public_key'] = base64_encode(sodium_crypto_sign_publickey_from_secretkey($vaults->signingKey()));
            $items['service_allowed_ips'] = is_string($ips) ? trim($ips) : '';
            sodium_memzero($master);
        }

        if ($items === []) {
            throw new RuntimeException('Nessuna chiave configurata da salvare.');
        }

        return $items;
    }

    /** Il servizio avvolge una chiave casuale; la chiave scritta deve riaprirla. Così si salva quella vera. */
    public static function checkServiceMaster(VaultFactory $vaults, string $master): void
    {
        $key = random_bytes(32);
        try {
            $wrapped = $vaults->remote()->wrap(KeyMigration::PROBE_ORG, 1, $key);
        } catch (Throwable) {
            throw new RuntimeException('Servizio chiavi non raggiungibile: la chiave principale del servizio non si può provare.');
        }
        try {
            $opened = (new KeyWrap($master))->unwrap(KeyMigration::PROBE_ORG, 1, $wrapped);
        } catch (Throwable) {
            $opened = null;
        }
        if ($opened === null || ! hash_equals($key, $opened)) {
            throw new RuntimeException('La chiave principale scritta non è quella che usa il servizio chiavi.');
        }
    }
}
