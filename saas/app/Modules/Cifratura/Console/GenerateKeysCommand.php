<?php

declare(strict_types=1);

namespace App\Modules\Cifratura\Console;

use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Genera le chiavi per una nuova installazione. Stampa a video e non salva nulla: le chiavi vanno copiate a mano
 * nel posto giusto (mai nel repository, mai in chat).
 */
#[AsCommand(name: 'saas:keys:generate')]
final class GenerateKeysCommand extends Command
{
    protected $signature = 'saas:keys:generate';

    protected $description = 'Genera chiave principale e coppia di chiavi per il servizio chiavi';

    public function handle(): int
    {
        $pair = sodium_crypto_sign_keypair();

        $this->line('Chiave principale (servizio chiavi: master_key; ripiego locale: SAAS_MASTER_KEY):');
        $this->line('  '.base64_encode(random_bytes(32)));
        $this->newLine();
        $this->line('Chiave pubblica del SaaS (servizio chiavi: client_public_key):');
        $this->line('  '.base64_encode(sodium_crypto_sign_publickey($pair)));
        $this->newLine();
        $this->line('Chiave privata del SaaS (ambiente del SaaS: SAAS_KEY_SERVICE_SIGNING_KEY):');
        $this->line('  '.base64_encode(sodium_crypto_sign_secretkey($pair)));
        $this->newLine();
        $seed = random_bytes(SODIUM_CRYPTO_SIGN_SEEDBYTES);
        $public = sodium_crypto_sign_publickey(sodium_crypto_sign_seed_keypair($seed));
        $x = rtrim(strtr(base64_encode($public), '+/', '-_'), '=');
        $this->line('Chiave del SaaS verso il portale (ambiente del SaaS: SAAS_PORTAL_SIGNING_KEY):');
        $this->line('  '.base64_encode($seed));
        $this->line('Da fissare nel portale (kid e chiave pubblica x):');
        $this->line('  saas-'.substr(hash('sha256', $x), 0, 8).'='.$x);
        $this->newLine();
        $box = sodium_crypto_box_keypair();
        $this->line('Backup, chiave pubblica (server: SAAS_BACKUP_PUBLIC_KEY):');
        $this->line('  '.base64_encode(sodium_crypto_box_publickey($box)));
        $this->line('Backup, coppia completa (solo staging e copia offline: SAAS_BACKUP_SECRET_KEY):');
        $this->line('  '.base64_encode($box));
        $this->newLine();
        $this->warn('Conserva una copia offline della chiave principale e della coppia dei backup: senza, dati cifrati e backup non si aprono più.');

        return self::SUCCESS;
    }
}
