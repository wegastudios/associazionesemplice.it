<?php

declare(strict_types=1);

namespace App\Modules\Cifratura\Console;

use App\Modules\Cifratura\Contracts\Bytea;
use App\Modules\Cifratura\Keys\KeyMigration;
use App\Modules\Cifratura\Keys\RemoteKeyVault;
use App\Modules\Cifratura\Keys\VaultFactory;
use App\Modules\Cifratura\Monitor\KeyServiceAlertNotification;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

/**
 * Controllo del servizio chiavi, ogni 5 minuti dallo scheduler: risponde, accetta la firma del SaaS, avvolge e svolge
 * una chiave di prova e apre una chiave dati vera (così si vede anche una chiave principale sbagliata). Se non va,
 * un'email all'amministrazione, una sola per guasto; quando torna, un'email di ritorno alla normalità. Con la chiave
 * principale locale non controlla nulla.
 */
#[AsCommand(name: 'saas:keys:monitor')]
final class MonitorKeyServiceCommand extends Command
{
    /** Inizio del guasto in corso (per non mandare un avviso a ogni controllo). */
    public const STATE_KEY = 'saas:keys:monitor:guasto';

    protected $signature = 'saas:keys:monitor';

    protected $description = 'Controlla il servizio chiavi e avvisa l\'amministrazione se non va';

    public function handle(Config $config, VaultFactory $vaults, DatabaseManager $db, Cache $cache): int
    {
        if ($config->get('saas.crypto.vault') !== 'remote') {
            $this->line('Chiave principale locale: nessun servizio chiavi da controllare.');

            return self::SUCCESS;
        }

        $reason = null;
        try {
            $vault = $vaults->remote(breaker: false);
            KeyMigration::probe($vault);
            $this->checkRealKey($vault, $db);
        } catch (Throwable $e) {
            $reason = self::reason($e);
        }

        $since = $this->state($cache);

        if ($reason !== null) {
            $this->error("ERRORE servizio chiavi: {$reason}");
            Log::warning('Servizio chiavi: controllo non riuscito', ['motivo' => $reason]);
            if ($since === null) {
                $now = now()->format('d/m/Y H:i');
                if ($this->notify($config, $db, new KeyServiceAlertNotification(true, $now, $reason))) {
                    $this->remember($cache, $now);
                }
            }

            return self::FAILURE;
        }

        $this->line('ok    servizio chiavi');
        if ($since !== null && $this->notify($config, $db, new KeyServiceAlertNotification(false, $since))) {
            $this->forgetState($cache);
        }

        return self::SUCCESS;
    }

    /** Una chiave dati vera: se il servizio usa una chiave principale diversa, la prova casuale passa ma questa no. */
    private function checkRealKey(RemoteKeyVault $vault, DatabaseManager $db): void
    {
        try {
            $row = $db->connection('pgsql')->table('core.org_keys')->orderBy('org_id')->orderBy('version')->first(['org_id', 'version', 'wrapped_key']);
        } catch (Throwable) {
            return; // database non raggiungibile: non è un guasto del servizio chiavi
        }
        if (! is_object($row) || ! isset($row->org_id, $row->version, $row->wrapped_key) || ! is_string($row->org_id) || ! is_numeric($row->version)) {
            return;
        }

        try {
            $vault->unwrap($row->org_id, (int) $row->version, Bytea::decode($row->wrapped_key));
        } catch (ConnectionException $e) {
            throw $e;
        } catch (Throwable) {
            throw new RuntimeException('il servizio non apre le chiavi delle associazioni (chiave principale diversa?)');
        }
    }

    private function notify(Config $config, DatabaseManager $db, KeyServiceAlertNotification $notification): bool
    {
        $recipients = self::recipients($config, $db);
        if ($recipients === []) {
            $this->warn('Nessun indirizzo di amministrazione configurato (SAAS_STAFF_SUPER_ADMINS o super amministratori dello staff).');
            Log::error('Servizio chiavi: nessun indirizzo a cui mandare l\'avviso');

            return true;
        }

        try {
            Notification::route('mail', $recipients)->notifyNow($notification);
        } catch (Throwable) {
            // Si riprova al prossimo controllo.
            $this->warn('Avviso non mandato: la posta non va. Si riprova al prossimo controllo.');
            Log::error('Servizio chiavi: avviso per email non mandato');

            return false;
        }
        $this->line('Avviso mandato a '.count($recipients).' indirizzi.');

        return true;
    }

    /** @return list<string> */
    private static function recipients(Config $config, DatabaseManager $db): array
    {
        $configured = $config->get('saas.staff_issuer.super_admins', '');
        $emails = explode(',', is_string($configured) ? $configured : '');
        try {
            foreach ($db->connection('pgsql')->table('core.staff_super_admins as a')->join('core.staff_people as p', 'p.id', '=', 'a.person_id')->where('p.active', true)->pluck('p.email') as $email) {
                if (is_string($email)) {
                    $emails[] = $email;
                }
            }
        } catch (Throwable) {
            // database non raggiungibile: restano gli indirizzi della configurazione
        }

        $clean = [];
        foreach ($emails as $email) {
            $email = mb_strtolower(trim($email));
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false && ! in_array($email, $clean, true)) {
                $clean[] = $email;
            }
        }

        return $clean;
    }

    private static function reason(Throwable $e): string
    {
        if ($e instanceof ConnectionException) {
            return 'servizio non raggiungibile (connessione, DNS o certificato)';
        }

        // I messaggi del SaaS su questo percorso non contengono mai chiavi né corpi di risposta.
        return $e instanceof RuntimeException ? mb_substr($e->getMessage(), 0, 200) : 'errore imprevisto';
    }

    private function state(Cache $cache): ?string
    {
        try {
            $value = $cache->get(self::STATE_KEY);
        } catch (Throwable) {
            return null;
        }

        return is_string($value) ? $value : null;
    }

    private function remember(Cache $cache, string $since): void
    {
        try {
            $cache->forever(self::STATE_KEY, $since);
        } catch (Throwable) {
            // senza cache si rischia un avviso in più, non uno in meno
        }
    }

    private function forgetState(Cache $cache): void
    {
        try {
            $cache->forget(self::STATE_KEY);
        } catch (Throwable) {
            // al prossimo controllo riuscito si riprova
        }
    }
}
