<?php

declare(strict_types=1);

namespace App\Modules\Core\Console;

use App\Modules\Cifratura\Contracts\KeyVault;
use App\Modules\Core\Contracts\DatabaseNames;
use App\Modules\Portale\Contracts\WebRuntimeProbe;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Redis;
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

    public function handle(DatabaseManager $db, DatabaseNames $databases, KeyVault $vault, WebRuntimeProbe $probe): int
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
            'chiave principale in produzione' => $this->productionVault(...),
            'ruolo dell\'applicazione' => fn () => $this->appRole($db->connection('pgsql')),
            'tabelle delle associazioni' => fn () => $this->orgTables($db->connection('pgsql')),
            'permessi dell\'applicazione' => fn () => $this->appPrivileges($db->connection('pgsql')),
            'connessione senza pooler' => fn () => $this->sessionSettings($db->connection('pgsql')),
            'memoria di Redis' => $this->redisMemory(...),
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

        // Controlli «morbidi»: finché SAAS_HEALTH_STRICT è spento (sviluppo, dati inventati) un problema è un avviso e non ferma il rilascio.
        $soft = ['chiave principale in produzione', 'permessi dell\'applicazione', 'memoria di Redis'];
        $strict = config('saas.health_strict') === true;
        $failed = 0;
        foreach ($checks as $name => $check) {
            try {
                $check();
                $this->line("ok    {$name}");
            } catch (Throwable $e) {
                if (! $strict && in_array($name, $soft, true)) {
                    $this->warn("AVVISO {$name}: ".mb_substr($e->getMessage(), 0, 300));

                    continue;
                }
                $failed++;
                $this->error("ERRORE {$name}: ".mb_substr($e->getMessage(), 0, 300));
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

        // APCu e OPcache del PHP che serve le pagine (non quelli della riga di comando): li chiediamo al web con una
        // chiamata firmata. Se non risponde è solo un avviso, mai un errore.
        $web = $probe->fetch();
        if ($web === null) {
            $this->warn('nota  PHP web non raggiungibile con la rotta firmata: APCu e OPcache non verificati.');
        } else {
            // APCu: senza, ogni richiesta che cifra chiede la chiave al servizio chiavi (con il servizio remoto costa 50–150 ms).
            if ($web['apcu'] !== true) {
                $remote = config('saas.crypto.vault') !== 'local';
                $this->warn('nota  APCu non attivo sul PHP web'.($remote ? ': le chiavi si chiedono al servizio chiavi a ogni richiesta (più lento)' : '').'.');
            }
            // OPcache: senza, ogni richiesta rilegge e ricompila il codice.
            $opcache = $web['opcache'];
            if (! is_array($opcache) || ($opcache['enabled'] ?? false) !== true) {
                $this->warn('nota  OPcache non attivo sul PHP web: il codice si ricompila a ogni richiesta (più lento).');
            }
        }

        if (config('saas.auth.require_two_factor') === false) {
            $this->warn('nota  Verifica in due passaggi SPENTA (SAAS_REQUIRE_TWO_FACTOR=false): solo sviluppo, dati inventati.');
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Oltre il 70% della memoria di Redis (politica allkeys-lru: toglie le chiavi meno usate, non rifiuta) è un avviso.
     * Senza Redis in uso non c'è nulla da controllare; se non risponde, la cache passa già al database.
     */
    private function redisMemory(): void
    {
        if (! in_array(config('cache.default'), ['redis', 'failover'], true)) {
            return;
        }
        try {
            $info = Redis::connection('cache')->command('info', ['memory']);
        } catch (Throwable) {
            return;
        }
        $info = is_array($info) ? ($info['Memory'] ?? $info) : [];
        $info = is_array($info) ? $info : [];
        $usedRaw = $info['used_memory'] ?? 0;
        $maxRaw = $info['maxmemory'] ?? 0;
        $used = is_numeric($usedRaw) ? (int) $usedRaw : 0;
        $max = is_numeric($maxRaw) ? (int) $maxRaw : 0;
        if ($max > 0 && $used / $max > 0.7) {
            throw new RuntimeException(sprintf('Redis usa il %d%% della memoria (%d di %d byte): le chiavi meno usate vengono tolte', (int) round($used / $max * 100), $used, $max));
        }
    }

    /** In produzione niente chiave locale, niente posta nel log; con SFTP l'impronta dell'host è obbligatoria. */
    private function productionConfig(): void
    {
        $problems = [];
        $disk = config('filesystems.disks.backups');
        if (is_array($disk) && ($disk['driver'] ?? null) === 'sftp' && (! is_string($disk['hostFingerprint'] ?? null) || trim($disk['hostFingerprint']) === '')) {
            $problems[] = 'impronta dell\'host SFTP dei backup mancante (SAAS_BACKUP_SFTP_FINGERPRINT)';
        }

        if (config('app.env') === 'production' && in_array(config('mail.default'), ['log', 'array'], true)) {
            $problems[] = 'posta non configurata: le email finirebbero nel log';
        }

        if ($problems !== []) {
            throw new RuntimeException(implode('; ', $problems));
        }
    }

    /** In produzione la chiave principale non può essere quella locale: serve il servizio chiavi. */
    private function productionVault(): void
    {
        if (config('app.env') === 'production' && config('saas.crypto.vault') !== 'remote') {
            throw new RuntimeException('chiave principale locale: serve il servizio chiavi (SAAS_KEY_VAULT=remote, con indirizzo e chiave del servizio)');
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
            $names = array_map(static fn (mixed $row): string => is_object($row) && is_string($row->name ?? null) ? $row->name : '?', $bad);
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
            $names = static fn (array $rows): string => implode(', ', array_map(static fn (mixed $row): string => is_object($row) && is_string($row->name ?? null) ? $row->name : '?', $rows));
            $found = [];
            if ($schemas !== []) {
                $found[] = 'CREATE sugli schemi '.$names($schemas);
            }
            if ($tables !== []) {
                $found[] = 'TRUNCATE/REFERENCES/TRIGGER sulle tabelle '.$names($tables);
            }
            if ($migrations !== []) {
                $found[] = 'lettura di public.migrations';
            }
            throw new RuntimeException('permessi eccessivi: '.implode('; ', $found));
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
