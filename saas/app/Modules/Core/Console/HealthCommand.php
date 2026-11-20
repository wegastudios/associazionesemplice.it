<?php

declare(strict_types=1);

namespace App\Modules\Core\Console;

use App\Modules\Cifratura\Contracts\KeyVault;
use App\Modules\Core\Contracts\DatabaseNames;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

/**
 * Controllo di salute, usato dal rilascio prima e dopo il cambio di versione: database e migrazioni, servizio chiavi,
 * spazio dei file e dei backup. Esce con errore se qualcosa non va; non stampa mai segreti.
 */
#[AsCommand(name: 'saas:health')]
final class HealthCommand extends Command
{
    protected $signature = 'saas:health {--skip-migrations : Non controllare le migrazioni (prima di eseguirle)}';

    protected $description = 'Controlla database, migrazioni, servizio chiavi e spazi dei file';

    public function handle(DatabaseManager $db, DatabaseNames $databases, KeyVault $vault): int
    {
        $checks = [
            'database' => fn () => $db->connection('pgsql')->select('SELECT 1'),
            'servizio chiavi' => function () use ($vault): void {
                $key = random_bytes(32);
                $org = '00000000-0000-7000-8000-000000000000';
                if ($vault->unwrap($org, 1, $vault->wrap($org, 1, $key)) !== $key) {
                    throw new RuntimeException('risposta diversa');
                }
            },
            'file delle associazioni' => fn () => $this->roundTrip('org_files'),
            'spazio dei backup' => fn () => $this->roundTrip('backups'),
            'configurazione di produzione' => $this->productionConfig(...),
            'ruolo dell\'applicazione' => fn () => $this->appRole($db->connection('pgsql')),
            'tabelle delle associazioni' => fn () => $this->orgTables($db->connection('pgsql')),
            'permessi dell\'applicazione' => fn () => $this->appPrivileges($db->connection('pgsql')),
            'connessione senza pooler' => fn () => $this->sessionSettings($db->connection('pgsql')),
        ];

        if (! $this->option('skip-migrations')) {
            foreach ($databases->names() as $name) {
                $checks["migrazioni {$name}"] = function () use ($db, $databases, $name): void {
                    $done = array_filter(
                        $db->connection($databases->ownerConnection($name))->table('migrations')->pluck('migration')->all(),
                        is_string(...),
                    );
                    $kinds = $name === 'shared' ? ['Central', 'Org'] : ['Org'];
                    $files = [];
                    foreach ($kinds as $kind) {
                        foreach (glob(app_path("Modules/*/Database/Migrations/{$kind}/*.php")) ?: [] as $file) {
                            $files[] = basename($file, '.php');
                        }
                    }
                    $missing = array_diff($files, $done);
                    if ($missing !== []) {
                        throw new RuntimeException(count($missing).' da eseguire');
                    }
                };
            }
        }

        $failed = 0;
        foreach ($checks as $name => $check) {
            try {
                $check();
                $this->line("ok    {$name}");
            } catch (Throwable $e) {
                $failed++;
                $this->error("ERRORE {$name}: ".mb_substr($e->getMessage(), 0, 200));
            }
        }

        // File di lavoro rimasti in storage/app/tmp (non è un errore: li toglie il lavoro pianificato).
        [$tmpFiles, $tmpBytes, $tmpOld] = array_slice(PurgeTmpCommand::scan(storage_path('app/tmp'), 120), 0, 3);
        $this->line("nota  file temporanei: {$tmpFiles} ({$tmpBytes} byte), di cui {$tmpOld} più vecchi di 2 ore");

        // I ripieghi in uso (posta nel log, cache sul database, chiave locale…) non sono errori, ma vanno visti.
        $warnings = config('saas.platform_warnings');
        foreach (is_array($warnings) ? $warnings : [] as $warning) {
            $this->warn('nota  '.(is_string($warning) ? $warning : ''));
        }

        // APCu: senza, ogni richiesta che cifra chiede la chiave al servizio chiavi (con il servizio remoto costa 50–150 ms).
        if (! (extension_loaded('apcu') && filter_var(ini_get('apc.enabled'), FILTER_VALIDATE_BOOLEAN))) {
            $remote = config('saas.crypto.vault') !== 'local';
            $this->warn('nota  APCu non attivo'.($remote ? ': le chiavi si chiedono al servizio chiavi a ogni richiesta (più lento)' : '').'.');
        }
        // OPcache: senza, ogni richiesta rilegge e ricompila il codice.
        $opcache = function_exists('opcache_get_status') ? @opcache_get_status(false) : false;
        if (! is_array($opcache) || ($opcache['opcache_enabled'] ?? false) !== true) {
            $this->warn('nota  OPcache non attivo: il codice si ricompila a ogni richiesta (più lento).');
        }

        if (config('saas.auth.require_two_factor') === false) {
            $this->warn('nota  Verifica in due passaggi SPENTA (SAAS_REQUIRE_TWO_FACTOR=false): solo sviluppo, dati inventati.');
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** In produzione niente chiave locale, niente posta nel log; con SFTP l'impronta dell'host è obbligatoria. */
    private function productionConfig(): void
    {
        $problems = [];
        $disk = config('filesystems.disks.backups');
        if (is_array($disk) && ($disk['driver'] ?? null) === 'sftp' && (! is_string($disk['hostFingerprint'] ?? null) || trim($disk['hostFingerprint']) === '')) {
            $problems[] = 'impronta dell\'host SFTP dei backup mancante (SAAS_BACKUP_SFTP_FINGERPRINT)';
        }

        if (config('app.env') === 'production') {
            if (config('saas.crypto.vault') !== 'remote') {
                $problems[] = 'chiave principale locale: serve il servizio chiavi (SAAS_KEY_VAULT=remote)';
            }
            if (in_array(config('mail.default'), ['log', 'array'], true)) {
                $problems[] = 'posta non configurata: le email finirebbero nel log';
            }
        }

        if ($problems !== []) {
            throw new RuntimeException(implode('; ', $problems));
        }
    }

    /** Il ruolo dell'applicazione non ha poteri speciali né fa parte di ruoli che li hanno. */
    private function appRole(Connection $connection): void
    {
        $special = $connection->select(<<<'SQL'
            SELECT r.rolname FROM pg_roles r
            WHERE r.rolname = current_user AND (r.rolsuper OR r.rolbypassrls OR r.rolcreaterole OR r.rolcreatedb)
            UNION ALL
            SELECT p.rolname FROM pg_auth_members m
            JOIN pg_roles r ON r.oid = m.member JOIN pg_roles p ON p.oid = m.roleid
            WHERE r.rolname = current_user AND (p.rolsuper OR p.rolbypassrls OR p.rolcreaterole OR p.rolcreatedb)
        SQL);
        if ($special !== []) {
            throw new RuntimeException('il ruolo ha poteri speciali (superuser, BYPASSRLS, creazione di ruoli o database) o li eredita');
        }
    }

    /** Su ogni tabella di app, audit e sites: RLS attiva e forzata, almeno una policy, proprietario diverso dal ruolo. */
    private function orgTables(Connection $connection): void
    {
        $bad = $connection->select(<<<'SQL'
            SELECT n.nspname || '.' || c.relname AS name
            FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname IN ('app', 'audit', 'sites') AND c.relkind IN ('r', 'p')
              AND (NOT c.relrowsecurity OR NOT c.relforcerowsecurity
                   OR NOT EXISTS (SELECT 1 FROM pg_policy p WHERE p.polrelid = c.oid)
                   OR pg_get_userbyid(c.relowner) = current_user)
            ORDER BY 1 LIMIT 5
        SQL);
        if ($bad !== []) {
            $names = array_map(static fn (object $row): string => is_string($row->name ?? null) ? $row->name : '?', $bad);
            throw new RuntimeException('RLS non attiva o non forzata, senza policy o proprietario sbagliato: '.implode(', ', $names));
        }
    }

    /** Nessuna creazione di oggetti negli schemi, nessun TRUNCATE/REFERENCES/TRIGGER, nessuna lettura delle migrazioni. */
    private function appPrivileges(Connection $connection): void
    {
        $schemas = $connection->select(<<<'SQL'
            SELECT n.nspname AS name FROM pg_namespace n
            WHERE n.nspname IN ('app', 'audit', 'sites', 'core', 'public') AND has_schema_privilege(current_user, n.oid, 'CREATE')
        SQL);
        $tables = $connection->select(<<<'SQL'
            SELECT n.nspname || '.' || c.relname AS name
            FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname IN ('core', 'app', 'audit', 'sites') AND c.relkind IN ('r', 'p')
              AND (has_table_privilege(current_user, c.oid, 'TRUNCATE') OR has_table_privilege(current_user, c.oid, 'REFERENCES')
                   OR has_table_privilege(current_user, c.oid, 'TRIGGER'))
            ORDER BY 1 LIMIT 5
        SQL);
        $migrations = $connection->select(<<<'SQL'
            SELECT 1 AS found WHERE to_regclass('public.migrations') IS NOT NULL
              AND has_table_privilege(current_user, to_regclass('public.migrations'), 'SELECT')
        SQL);
        if ($schemas !== [] || $tables !== [] || $migrations !== []) {
            throw new RuntimeException('permessi eccessivi (CREATE su schemi, TRUNCATE/REFERENCES/TRIGGER su tabelle o lettura di public.migrations)');
        }
    }

    /**
     * L'associazione corrente è un'impostazione di sessione (set_config senza «local»): con un pooler in modalità
     * transazione due istruzioni di fila possono cadere su connessioni diverse e l'isolamento salta.
     */
    private function sessionSettings(Connection $connection): void
    {
        try {
            for ($i = 0; $i < 3; $i++) {
                $value = '00000000-0000-7000-8000-'.bin2hex(random_bytes(6));
                $connection->select("SELECT set_config('app.org_id', ?, false)", [$value]);
                $read = $connection->selectOne("SELECT current_setting('app.org_id', true) AS value");
                if (! is_object($read) || ($read->value ?? null) !== $value) {
                    throw new RuntimeException('l\'impostazione di sessione non resta tra due istruzioni: pooler in modalità transazione? Non è ammesso (docs/DATABASE.md)');
                }
            }
        } finally {
            $connection->select("SELECT set_config('app.org_id', '', false)");
        }
    }

    private function roundTrip(string $disk): void
    {
        $path = '.health/'.bin2hex(random_bytes(8));
        Storage::disk($disk)->put($path, 'ok');
        $ok = Storage::disk($disk)->get($path) === 'ok';
        Storage::disk($disk)->delete($path);
        if (! $ok) {
            throw new RuntimeException('lettura diversa dalla scrittura');
        }
    }
}
