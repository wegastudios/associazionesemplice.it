<?php

declare(strict_types=1);

namespace App\Modules\Core\Console;

use App\Modules\AreaSoci\Contracts\PaymentWebhookHealth;
use App\Modules\Backup\Contracts\BackupCompression;
use App\Modules\Cifratura\Contracts\KeyVault;
use App\Modules\Contabilita\Contracts\BalanceCheck;
use App\Modules\Core\Contracts\DatabaseNames;
use App\Modules\Core\Contracts\JobQueues;
use App\Modules\Core\Contracts\MailQueue;
use App\Modules\Core\Mail\PrimaryMailer;
use App\Modules\Core\Platform\RoleObjectAudit;
use App\Modules\Portale\Contracts\UsageHealth;
use App\Modules\Portale\Contracts\WebRuntimeProbe;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
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
    /** Posta in coda: soglie di attesa del messaggio più vecchio (secondi) e quota dell'ora o del giorno oltre la quale si avvisa. */
    private const URGENT_MAX_WAIT = 5 * 60;

    private const BULK_MAX_WAIT = 6 * 3600;

    private const USAGE_WARNING_SHARE = 0.9;

    /** Altri lavori: da quanto (secondi) un lavoro pronto aspetta un lavoratore. I lunghi aspettano anche un altro lungo. */
    private const DEFAULT_MAX_WAIT = 15 * 60;

    private const LONG_MAX_WAIT = 60 * 60;

    /** Voce dei saldi dei conti: l'unica che --ignore-balances rende un avviso. */
    private const BALANCES = 'saldi dei conti';

    /** @var list<string> «Limiti noti» dei ruoli (informazione, non errore): si stampano come note dopo i controlli. */
    private array $roleNotes = [];

    /** @var array{problems: list<string>, critical: list<string>}|null */
    private ?array $roleAudit = null;

    protected $signature = 'saas:health {--skip-migrations : Non controllare le migrazioni (prima di eseguirle)}
        {--mail-warnings-only : Problemi della posta in coda come avvisi (rilascio: non dipendono dalla versione)}
        {--ignore-balances : Saldi dei conti diversi dai movimenti come avviso: solo per il rilascio che corregge il difetto (RILASCIO.md)}';

    protected $description = 'Controlla database, migrazioni, servizio chiavi e spazi dei file';

    public function handle(DatabaseManager $db, DatabaseNames $databases, KeyVault $vault, WebRuntimeProbe $probe, BalanceCheck $balances, BackupCompression $compression): int
    {
        $this->roleAudit = null;
        $this->roleNotes = [];
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
            'oggetti e ruoli di gruppo' => fn () => $this->roleObjects($db, $databases, false),
            'separazione dal ruolo proprietario' => fn () => $this->roleObjects($db, $databases, true),
            'connessione senza pooler' => fn () => $this->sessionSettings($db->connection('pgsql')),
            'memoria di Redis' => $this->redisMemory(...),
            'partizioni del registro' => fn () => $this->auditPartitions($db, $databases),
            'rifiuto del TRUNCATE' => fn () => $this->truncateGuards($db, $databases),
            self::BALANCES => fn () => $this->balances($balances),
        ];
        // Posta e altri lavori in coda: problemi di esercizio, non della versione. Al rilascio (--mail-warnings-only) sono
        // avvisi, così non riportano indietro il rilascio.
        $queueChecks = [
            'coda della posta urgente' => fn () => $this->mailWait($db, MailQueue::URGENT, self::URGENT_MAX_WAIT),
            'coda della posta massiva' => fn () => $this->mailWait($db, MailQueue::BULK, self::BULK_MAX_WAIT),
            'invio della posta' => $this->mailBlocked(...),
            'servizio di invio' => $this->mailPrimaryOff(...),
            'posta non partita' => fn () => $this->mailFailed($db),
            'coda dei lavori' => fn () => $this->jobWait($db, JobQueues::DEFAULT, self::DEFAULT_MAX_WAIT),
            'coda dei lavori lunghi' => fn () => $this->jobWait($db, JobQueues::LONG, self::LONG_MAX_WAIT),
        ];
        // Sempre avvisi: gli invii vicini ai tetti, il ripiego della posta che dura, i lavori non riusciti (li vede chi li
        // ha chiesti), gli incassi online fermi in coda (H14: li rimette in coda la passata ogni 5 minuti).
        $alwaysWarn = [
            'invii della posta nell\'ora' => $this->mailHourUsage(...),
            'invii del giorno' => $this->mailDayUsage(...),
            'ripiego della posta' => $this->mailFallback(...),
            'lavori non riusciti' => fn () => $this->jobsFailed($db),
            'incassi online in coda' => PaymentWebhookHealth::check(...),
            'invio dei consumi al portale' => $this->usageSent(...),
            'controllo notturno dei saldi' => fn () => $this->balancesRun($balances),
            'copia dei dati' => function () use ($compression): void {
                if ($compression->fellBackToGzip()) {
                    throw new RuntimeException('compressione zstd non disponibile, uso gzip');
                }
            },
        ];
        $warnOnly = array_keys($alwaysWarn);
        if ($this->option('mail-warnings-only') === true) {
            $warnOnly = [...$warnOnly, ...array_keys($queueChecks)];
        }
        // Solo questa voce: gli altri errori fermano il rilascio anche con l'opzione.
        if ($this->option('ignore-balances') === true) {
            $warnOnly[] = self::BALANCES;
        }
        $checks += $queueChecks + $alwaysWarn;

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
        $soft = ['chiave principale in produzione', 'permessi dell\'applicazione', 'oggetti e ruoli di gruppo', 'memoria di Redis', 'partizioni del registro'];
        $strict = config('saas.health_strict') === true;
        $failed = 0;
        foreach ($checks as $name => $check) {
            try {
                $check();
                $this->line("ok    {$name}");
            } catch (Throwable $e) {
                if ((! $strict && in_array($name, $soft, true)) || in_array($name, $warnOnly, true)) {
                    $this->warn("AVVISO {$name}: ".mb_substr($e->getMessage(), 0, 900));

                    continue;
                }
                $failed++;
                $this->error("ERRORE {$name}: ".mb_substr($e->getMessage(), 0, 900));
            }
        }

        foreach ($this->roleNotes as $note) {
            $this->line("nota  {$note}");
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

    /**
     * Su ogni database il mese corrente e il prossimo hanno la loro partizione del registro, e quella predefinita è vuota:
     * agganciare un mese nuovo la rilegge tutta tenendo il blocco forte. Letto con il ruolo dei backup, l'unico che vede
     * tutte le righe (la RLS vale anche per il proprietario).
     */
    private function auditPartitions(DatabaseManager $db, DatabaseNames $databases): void
    {
        $month = now()->utc()->startOfMonth();
        $expected = ['audit.log_'.$month->format('Y_m'), 'audit.log_'.$month->copy()->addMonth()->format('Y_m')];
        $problems = [];
        foreach ($databases->names() as $name) {
            $row = $db->connection($databases->backupConnection($name))->selectOne(
                'SELECT to_regclass(?) IS NULL AS this_missing, to_regclass(?) IS NULL AS next_missing, EXISTS (SELECT 1 FROM audit.log_default) AS default_used',
                $expected,
            );
            $row = is_object($row) ? $row : (object) [];
            foreach ([$expected[0] => $row->this_missing ?? true, $expected[1] => $row->next_missing ?? true] as $table => $missing) {
                if ($missing === true) {
                    $problems[] = "{$name}: manca {$table} (saas:audit:partitions)";
                }
            }
            if (($row->default_used ?? false) === true) {
                $problems[] = "{$name}: righe nella partizione predefinita";
            }
        }
        if ($problems !== []) {
            throw new RuntimeException(implode('; ', $problems));
        }
    }

    /**
     * Su ogni database audit.log, tutte le sue partizioni e app.transactions hanno il trigger attivo che rifiuta il
     * TRUNCATE (log_no_truncate, transactions_no_truncate). Sempre un errore, mai un avviso: senza, il registro e la prima
     * nota si svuotano con un'istruzione sola. Legge solo il catalogo (pg_class, pg_inherits, pg_trigger, leggibili da
     * ogni ruolo) con il ruolo dei backup; se il catalogo non fosse leggibile, l'errore della query è comunque un errore.
     */
    private function truncateGuards(DatabaseManager $db, DatabaseNames $databases): void
    {
        $problems = [];
        foreach ($databases->names() as $name) {
            $rows = $db->connection($databases->backupConnection($name))->select(<<<'SQL'
                WITH expected AS (
                    SELECT to_regclass('audit.log') AS rel, 'log_no_truncate'::text AS trg, 'audit.log'::text AS label
                    UNION ALL
                    SELECT i.inhrelid::regclass, 'log_no_truncate', i.inhrelid::regclass::text
                    FROM pg_inherits i WHERE i.inhparent = to_regclass('audit.log')
                    UNION ALL
                    SELECT to_regclass('app.transactions'), 'transactions_no_truncate', 'app.transactions'
                )
                SELECT e.label FROM expected e
                WHERE e.rel IS NULL OR NOT EXISTS (
                    SELECT 1 FROM pg_trigger t
                    WHERE t.tgrelid = e.rel AND t.tgname = e.trg AND t.tgenabled <> 'D' AND (t.tgtype & 32) <> 0
                )
                ORDER BY 1
                SQL);
            $missing = array_map(static fn (mixed $row): string => is_object($row) && is_string($row->label ?? null) ? $row->label : '?', $rows);
            if ($missing !== []) {
                $problems[] = "{$name}: senza rifiuto del TRUNCATE ".implode(', ', $missing);
            }
        }
        if ($problems !== []) {
            throw new RuntimeException(implode('; ', $problems));
        }
    }

    /**
     * Il messaggio più vecchio di una coda di posta aspetta da più della soglia. Conta la nascita scritta nel lavoro
     * (createdAt): un rilascio lo riscrive in coda con una data nuova. Solo con la coda sul database.
     */
    private function mailWait(DatabaseManager $db, string $queue, int $maxSeconds): void
    {
        if (config('queue.default') !== 'database') {
            return;
        }
        $oldest = $this->jobs($db)
            ->where('queue', $queue)
            ->selectRaw("min(coalesce((payload::json->>'createdAt')::bigint, created_at)) AS created")
            ->value('created');
        if (! is_numeric($oldest)) {
            return;
        }
        $waited = now()->getTimestamp() - (int) $oldest;
        if ($waited > $maxSeconds) {
            throw new RuntimeException($waited < 7200
                ? sprintf('il messaggio più vecchio aspetta da %d minuti (soglia %d)', intdiv($waited, 60), intdiv($maxSeconds, 60))
                : sprintf('il messaggio più vecchio aspetta da %d ore (soglia %d)', intdiv($waited, 3600), intdiv($maxSeconds, 3600)));
        }
    }

    /**
     * Il lavoro pronto più vecchio di una coda, non preso da nessun lavoratore, aspetta da più della soglia: il cron non
     * gira o i lavoratori sono sempre occupati. Conta da quando è pronto (available_at), non i lavori rimandati.
     */
    private function jobWait(DatabaseManager $db, string $queue, int $maxSeconds): void
    {
        if (config('queue.default') !== 'database') {
            return;
        }
        $oldest = $this->jobs($db)->where('queue', $queue)->whereNull('reserved_at')->min('available_at');
        $waited = is_numeric($oldest) ? now()->getTimestamp() - (int) $oldest : 0;
        if ($waited > $maxSeconds) {
            throw new RuntimeException(sprintf('il lavoro più vecchio aspetta un lavoratore da %d minuti (soglia %d)', intdiv($waited, 60), intdiv($maxSeconds, 60)));
        }
    }

    /** Lavori fuori dalla posta (copie, import) finiti tra i falliti nelle ultime 24 ore. */
    private function jobsFailed(DatabaseManager $db): void
    {
        $count = $this->failedLastDay($db)->whereNotIn('queue', [MailQueue::URGENT, MailQueue::BULK])->count();
        if ($count > 0) {
            throw new RuntimeException("{$count} nelle ultime 24 ore (php artisan queue:failed)");
        }
    }

    private function jobs(DatabaseManager $db): Builder
    {
        return $this->queueTable($db, 'queue.connections.database.connection', 'queue.connections.database.table', 'core.jobs');
    }

    /** Lavori falliti nelle ultime 24 ore. */
    private function failedLastDay(DatabaseManager $db): Builder
    {
        return $this->queueTable($db, 'queue.failed.database', 'queue.failed.table', 'core.failed_jobs')->where('failed_at', '>=', now()->subDay());
    }

    /** Una tabella delle code dalla configurazione (connessione e nome), con i ripieghi. */
    private function queueTable(DatabaseManager $db, string $connectionKey, string $tableKey, string $default): Builder
    {
        $connection = config($connectionKey);
        $table = config($tableKey);

        return $db->connection(is_string($connection) && $connection !== '' ? $connection : null)
            ->table(is_string($table) && $table !== '' ? $table : $default);
    }

    /**
     * Ultimo controllo notturno dei saldi (saas:balances:verify): un conto il cui saldo non è il saldo di partenza più i
     * movimenti è un errore. Qui solo il numero: associazione e conto sono nel file 600 della cartella condivisa.
     */
    private function balances(BalanceCheck $check): void
    {
        $r = $check->lastResult();
        if ($r !== null && $r['mismatches'] > 0) {
            throw new RuntimeException("{$r['mismatches']} conti con il saldo diverso dai movimenti (dettaglio nel file saldi-*.txt della cartella condivisa)");
        }
    }

    /** Il controllo dei saldi non è girato da più di due giorni, o non è riuscito per qualche associazione: avviso. */
    /** L'invio notturno dei consumi al portale non riesce da più di due giorni. */
    private function usageSent(): void
    {
        UsageHealth::check();
    }

    private function balancesRun(BalanceCheck $check): void
    {
        $r = $check->lastResult();
        if ($r === null) {
            throw new RuntimeException('mai eseguito');
        }
        if (now()->getTimestamp() - $r['at'] > 2 * 86400) {
            throw new RuntimeException('ultimo giro più vecchio di due giorni');
        }
        if ($r['failed'] > 0) {
            throw new RuntimeException("non riuscito per {$r['failed']} associazioni");
        }
    }

    /** La posta in coda è ferma dopo un rifiuto del server di posta. */
    private function mailBlocked(): void
    {
        $mailer = MailQueue::mailer();
        $until = MailQueue::blockedUntil($mailer);
        if ($until !== null) {
            throw new RuntimeException(sprintf('il server di posta ha rifiutato un invio: posta in coda ferma fino alle %s (%s)', now()->setTimestamp($until)->format('H:i'), $mailer));
        }
    }

    /** Messaggi delle code di posta finiti tra i lavori falliti nelle ultime 24 ore. */
    private function mailFailed(DatabaseManager $db): void
    {
        $count = $this->failedLastDay($db)
            ->whereIn('queue', [MailQueue::URGENT, MailQueue::BULK])
            ->count();
        if ($count > 0) {
            throw new RuntimeException("{$count} messaggi non partiti nelle ultime 24 ore (lavori falliti delle code ".MailQueue::URGENT.' e '.MailQueue::BULK.')');
        }
    }

    /** Invii dell'ora in corso oltre il 90% del tetto totale del mailer. */
    private function mailHourUsage(): void
    {
        $usage = MailQueue::hourUsage();
        if ($usage !== null && $usage[0] > $usage[1] * self::USAGE_WARNING_SHARE) {
            throw new RuntimeException(sprintf('%d su %d nell\'ora in corso (%s)', $usage[0], $usage[1], MailQueue::mailer()));
        }
    }

    /** Il servizio di invio principale è spento per errori di fila: la posta parte dal ripiego. */
    private function mailPrimaryOff(): void
    {
        $until = PrimaryMailer::enabled() ? MailQueue::blockedUntil(PrimaryMailer::NAME) : null;
        if ($until !== null) {
            throw new RuntimeException(sprintf('spento per errori fino alle %s, posta sul ripiego (%s)', now()->setTimestamp($until)->format('H:i'), PrimaryMailer::NAME));
        }
    }

    /** Invii del giorno UTC oltre il 90% del tetto del servizio di invio principale. */
    private function mailDayUsage(): void
    {
        $usage = PrimaryMailer::enabled() ? MailQueue::usage(86400, PrimaryMailer::NAME) : null;
        if ($usage !== null && $usage[0] > $usage[1] * self::USAGE_WARNING_SHARE) {
            throw new RuntimeException(sprintf('%d su %d nel giorno UTC (%s)', $usage[0], $usage[1], PrimaryMailer::NAME));
        }
    }

    /** La posta parte dal ripiego da più di un'ora e il servizio principale non è ancora disponibile. */
    private function mailFallback(): void
    {
        if (! PrimaryMailer::enabled()) {
            return;
        }
        $since = PrimaryMailer::fallbackSince();
        if ($since !== null && PrimaryMailer::peek() !== PrimaryMailer::READY && now()->getTimestamp() - $since > 3600) {
            throw new RuntimeException(sprintf('posta sul ripiego (%s) da %d minuti', MailQueue::mailer(), intdiv(now()->getTimestamp() - $since, 60)));
        }
    }

    /**
     * In produzione niente chiave locale, niente posta nel log; con SFTP l'impronta dell'host è obbligatoria. Ovunque:
     * il servizio di invio principale scelto deve avere la sua chiave.
     */
    private function productionConfig(): void
    {
        $problems = [];
        $primary = config('saas.mail_queue.primary');
        if (! in_array($primary, ['cpanel', PrimaryMailer::NAME], true)) {
            $problems[] = 'SAAS_MAIL_PRIMARY sconosciuto: vale cpanel o '.PrimaryMailer::NAME;
        } elseif ($primary === PrimaryMailer::NAME && ! PrimaryMailer::enabled()) {
            $problems[] = 'SAAS_MAIL_PRIMARY='.PrimaryMailer::NAME.' senza chiave (EMAILIT_PASSWORD): la posta resta sul mailer di sempre';
        }
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

    /**
     * Su ogni database: schemi attesi, oggetti di proprietà del solo ruolo proprietario (non dei ruoli di gruppo di cui
     * applicazione e backup sono membri, come su cPanel), nessun permesso dei ruoli di gruppo oltre l'atteso, nessun CREATE
     * sul database né sugli schemi per applicazione e backup. Il resto (SET ROLE verso il ruolo che possiede il database) è
     * un limite noto: una nota, non un errore. Solo catalogo, letto con il ruolo dei backup.
     */
    private function roleObjects(DatabaseManager $db, DatabaseNames $databases, bool $critical): void
    {
        $audit = $this->roleAudit($db, $databases);
        $found = $critical ? $audit['critical'] : $audit['problems'];
        if ($found !== []) {
            throw new RuntimeException(implode('; ', $found));
        }
    }

    /**
     * Interroga una volta sola il catalogo di ogni database. Le note («limite noto») restano solo se non c'è alcun problema.
     *
     * @return array{problems: list<string>, critical: list<string>}
     */
    private function roleAudit(DatabaseManager $db, DatabaseNames $databases): array
    {
        if ($this->roleAudit !== null) {
            return $this->roleAudit;
        }
        $this->roleNotes = [];
        $problems = [];
        $critical = [];
        foreach ($databases->names() as $name) {
            $owner = $db->connection($databases->ownerConnection($name));
            $role = static function (string $key) use ($owner): string {
                $value = $owner->getConfig($key);
                if (! is_string($value) || $value === '') {
                    throw new RuntimeException("ruolo {$key} non configurato");
                }

                return $value;
            };
            $result = RoleObjectAudit::evaluate(RoleObjectAudit::inspect(
                $db->connection($databases->backupConnection($name)),
                $role('app_role'),
                $role('backup_role'),
                $role('username'),
            ));
            foreach ($result['problems'] as $problem) {
                $problems[] = "{$name}: {$problem}";
            }
            foreach ($result['critical'] as $problem) {
                $critical[] = "{$name}: {$problem}";
            }
            foreach ($result['notes'] as $note) {
                $this->roleNotes[] = "{$name}: {$note}";
            }
        }
        if ($problems !== [] || $critical !== []) {
            $this->roleNotes = [];
        }

        return $this->roleAudit = ['problems' => $problems, 'critical' => $critical];
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
