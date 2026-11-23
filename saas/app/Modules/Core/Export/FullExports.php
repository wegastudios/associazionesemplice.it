<?php

declare(strict_types=1);

namespace App\Modules\Core\Export;

use App\Modules\Audit\Contracts\AuditLog;
use App\Modules\Cifratura\Contracts\EncryptedFiles;
use App\Modules\Cifratura\Contracts\StoredFile;
use App\Modules\Core\Access\Memberships;
use App\Modules\Core\Contracts\Ability;
use App\Modules\Core\Contracts\JobQueues;
use App\Modules\Core\Contracts\Operator;
use App\Modules\Core\Contracts\OrgContext;
use App\Modules\Core\Jobs\BuildFullExport;
use App\Modules\Core\Models\User;
use Closure;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Auth\Factory as Auth;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Copie di sicurezza complete chieste dagli operatori (tabella app.full_exports). La richiesta mette in coda il lavoro e
 * risponde subito; il lavoro (BuildFullExport) costruisce l'archivio su disco con i permessi di chi l'ha chiesta, lo
 * cifra con la chiave dell'associazione (come gli allegati) e aggiorna l'avanzamento. Pronta, la copia la scarica solo
 * chi l'ha chiesta, decifrata da PHP mentre la manda, fino alla scadenza (keepHours()); poi il file si cancella
 * (saas:tmp:purge). Una copia in preparazione per associazione alla volta.
 */
final readonly class FullExports
{
    /** Secondi massimi del lavoro in coda: quelli di un lavoro lungo. */
    public const TIMEOUT = JobQueues::LONG_TIMEOUT;

    /** Oltre questo tempo una copia «in preparazione» non finirà più (lavoro interrotto): risulta non riuscita. */
    public const STALE_AFTER = self::TIMEOUT + 300;

    public const BUSY = 'C\'è già una copia di sicurezza in preparazione per questa associazione: aspetta che sia pronta.';

    public const GONE = 'Questa copia non è più disponibile: preparane una nuova.';

    public const FAILED = 'La copia non è riuscita. Riprova; se succede ancora, scrivi all\'assistenza di Associazione Semplice.';

    public function __construct(
        private DatabaseManager $db,
        private OrgContext $context,
        private FullExport $export,
        private Memberships $memberships,
        private AuditLog $audit,
        private Auth $auth,
        private EncryptedFiles $files,
    ) {}

    /** Ore in cui una copia pronta resta scaricabile (config saas.export.keep_hours, almeno una). */
    public static function keepHours(): int
    {
        return max(1, config()->integer('saas.export.keep_hours', 6));
    }

    /**
     * Mette in coda una copia per l'operatore. I file delle copie pronte che aveva chiesto prima si cancellano.
     *
     * @throws ExportTooLarge
     * @throws ExportUnavailable
     */
    public function request(Operator $operator, bool $withCertificates, ?int $year): string
    {
        if ($year === null) {
            $this->export->assertFits($operator, $withCertificates);
        }
        $this->fail($this->table()->where('status', 'running')->where('started_at', '<', now()->subSeconds(self::STALE_AFTER)));
        if ($this->table()->whereIn('status', ['queued', 'running'])->exists()) {
            throw new ExportUnavailable(self::BUSY);
        }
        $orgId = $this->context->require()->id;
        foreach ($this->table()->where('user_id', $operator->userId)->where('status', 'ready')->whereNotNull('file_path')->get(['id', 'file_path']) as $old) {
            if (is_string($old->id ?? null) && is_string($old->file_path ?? null)) {
                $this->forget($orgId, $old->id, $old->file_path);
            }
        }
        $this->table()->where('user_id', $operator->userId)->where('status', 'ready')->update(['file_path' => null]);

        $id = (string) Str::uuid7();
        $this->table()->insert(['id' => $id, 'org_id' => $orgId, 'user_id' => $operator->userId, 'status' => 'queued', 'year' => $year, 'with_certificates' => $withCertificates]);
        BuildFullExport::dispatch($orgId, $id);

        return $id;
    }

    /**
     * L'ultima copia chiesta dall'utente, per la pagina.
     *
     * @return array{id: string, status: string, year: int|null, with_certificates: bool, done: int, total: int, files: int, error: string|null, created_at: string, expires_at: string|null, available: bool}|null
     */
    public function latest(string $userId): ?array
    {
        $row = $this->table()->where('user_id', $userId)->orderByDesc('created_at')->orderByDesc('id')->first();
        if ($row === null) {
            return null;
        }
        $id = self::str($row->id ?? null) ?? '';
        $status = self::str($row->status ?? null) ?? 'failed';
        $started = self::str($row->started_at ?? null);
        if ($status === 'running' && $started !== null && Carbon::parse($started)->lt(now()->subSeconds(self::STALE_AFTER))) {
            $this->fail($this->table()->where('id', $id));
            $status = 'failed';
        }
        $expires = self::iso($row->expires_at ?? null);

        return [
            'id' => $id,
            'status' => $status,
            'year' => self::int($row->year ?? null),
            'with_certificates' => ($row->with_certificates ?? false) === true,
            'done' => self::int($row->done ?? null) ?? 0,
            'total' => self::int($row->total ?? null) ?? 0,
            'files' => self::int($row->files ?? null) ?? 0,
            'error' => $status === 'failed' ? self::FAILED : null,
            'created_at' => self::iso($row->created_at ?? null) ?? '',
            'expires_at' => $expires,
            'available' => $status === 'ready' && $expires !== null && now()->lt($expires) && ($row->file_path ?? null) !== null,
        ];
    }

    /** Il lavoro in coda: prende la copia (una volta sola), la costruisce e ne segna l'esito. */
    public function run(string $id): void
    {
        if ($this->table()->where('id', $id)->where('status', 'queued')->update(['status' => 'running', 'started_at' => now()]) !== 1) {
            return;
        }
        $row = $this->table()->where('id', $id)->first();
        $userId = self::str($row->user_id ?? null) ?? '';
        $orgId = $this->context->require()->id;
        $operator = $this->memberships->find($userId, $orgId);
        $path = self::path($orgId, $id);
        if ($row === null || $operator === null || ! $operator->can(Ability::ManageSettings)
            || (! is_dir(dirname($path)) && ! @mkdir(dirname($path), 0700, true) && ! is_dir(dirname($path)))) {
            $this->fail($this->table()->where('id', $id));

            return;
        }
        $last = 0;
        $progress = function (int $done, int $total) use ($id, &$last): void {
            // Al massimo un aggiornamento ogni due secondi: la pagina lo rilegge ogni pochi secondi.
            if ($done < $total && hrtime(true) - $last < 2_000_000_000) {
                return;
            }
            $last = hrtime(true);
            $this->table()->where('id', $id)->update(['done' => $done, 'total' => $total]);
        };
        try {
            $result = $this->asUser($userId, fn (): array => $this->context->withLongTimeouts(
                fn (): array => $this->export->build($operator, $operator->orgName, $path, ($row->with_certificates ?? false) === true, self::int($row->year ?? null), $progress),
            ));
            // A riposo la copia è cifrata con la chiave dell'associazione, come gli allegati; l'archivio in chiaro si toglie.
            $stored = $this->files->store($orgId, $path, 'copia-di-sicurezza.zip', 'application/zip');
            if (file_put_contents(self::marker($orgId, $id), $stored->path) === false) {
                $this->files->delete($orgId, $stored);

                throw new RuntimeException('Promemoria della scadenza non scritto.');
            }
        } catch (Throwable $e) {
            report($e);
            $this->fail($this->table()->where('id', $id));

            return;
        } finally {
            @unlink($path);
        }
        $this->table()->where('id', $id)->update([
            'status' => 'ready', 'files' => $result['files'], 'certificates' => $result['certificates'],
            'file_path' => $stored->path, 'file_size' => $stored->size, 'file_sha256' => $stored->sha256,
            'finished_at' => now(), 'expires_at' => now()->addHours(self::keepHours()),
        ]);
    }

    /**
     * Toglie le copie cifrate scadute: ognuna ha accanto, nella cartella privata, un promemoria con il percorso del file
     * cifrato; scaduto il promemoria (keepHours()), via il file e il promemoria. Nessuna query. Ritorna quante ne ha tolte.
     */
    public function purgeExpired(): int
    {
        $limit = time() - self::keepHours() * 3600;
        $removed = 0;
        foreach (glob(self::directory().'/*/*.cifrata') ?: [] as $marker) {
            if ((int) @filemtime($marker) >= $limit) {
                continue;
            }
            $this->forget(basename(dirname($marker)), basename($marker, '.cifrata'), (string) @file_get_contents($marker));
            $removed++;
        }
        // Archivi in chiaro e file di lavoro di un lavoro interrotto: oltre il tempo massimo del lavoro non servono più.
        $stale = time() - self::STALE_AFTER;
        foreach ([...(glob(self::directory().'/*/*.zip') ?: []), ...(glob(self::directory().'/*/*.zip.parti/*') ?: [])] as $file) {
            if ((int) @filemtime($file) < $stale) {
                @unlink($file);
                @rmdir(dirname($file));
            }
        }

        return $removed;
    }

    /** Il lavoro è uscito dalla coda con un errore: la copia risulta non riuscita (se non è già pronta). */
    public function failedInQueue(string $orgId, string $id): void
    {
        $this->context->run($orgId, fn () => $this->fail($this->table()->where('id', $id)));
    }

    /**
     * Lo scarico, per chi ha chiesto la copia: PHP decifra a blocchi mentre manda (il file in chiaro non esiste su disco).
     * Resta traccia di ogni scarico.
     *
     * @throws ExportUnavailable
     */
    public function download(string $id, Operator $operator): StreamedResponse
    {
        $row = Str::isUuid($id) ? $this->table()->where('id', $id)->where('user_id', $operator->userId)->where('status', 'ready')->first() : null;
        $expires = self::str($row->expires_at ?? null);
        $stored = self::str($row->file_path ?? null);
        if ($row === null || $expires === null || $stored === null || now()->gte($expires) || ! is_file(self::marker($operator->orgId, $id))) {
            throw new ExportUnavailable(self::GONE);
        }
        $year = self::int($row->year ?? null);
        $name = 'copia-di-sicurezza-'.preg_replace('/[^a-z0-9]+/', '-', strtolower($operator->orgName)).($year !== null ? '-'.$year : '').'-'.substr(self::str($row->created_at ?? null) ?? '', 0, 10).'.zip';
        $response = $this->files->download($operator->orgId, new StoredFile($stored, $name, 'application/zip', self::int($row->file_size ?? null) ?? 0, self::str($row->file_sha256 ?? null) ?? ''));
        $this->audit->record('backup.downloaded', null, null, ['files' => self::int($row->files ?? null) ?? 0, 'certificates' => self::int($row->certificates ?? null) ?? 0]);

        return $response;
    }

    /** Cartella privata di lavoro (archivio in chiaro mentre si costruisce) e dei promemoria delle scadenze. */
    public static function directory(): string
    {
        return storage_path('app/exports');
    }

    /** L'archivio in chiaro mentre il lavoro lo costruisce; finito, si cifra e si toglie. */
    public static function path(string $orgId, string $id): string
    {
        return self::directory().'/'.$orgId.'/'.$id.'.zip';
    }

    /** Promemoria della copia cifrata pronta: contiene il percorso del file cifrato; la sua età decide la scadenza. */
    public static function marker(string $orgId, string $id): string
    {
        return self::directory().'/'.$orgId.'/'.$id.'.cifrata';
    }

    /** Cancella la copia cifrata e il suo promemoria. */
    private function forget(string $orgId, string $id, string $stored): void
    {
        if ($stored !== '') {
            $this->files->delete($orgId, new StoredFile($stored, '', 'application/zip', 0, ''));
        }
        @unlink(self::marker($orgId, $id));
    }

    /** Segna come non riuscite le copie della query ancora in coda o in preparazione. */
    private function fail(Builder $query): void
    {
        $query->whereIn('status', ['queued', 'running'])->update(['status' => 'failed', 'finished_at' => now()]);
    }

    /**
     * Nel lavoro in coda non c'è nessuno collegato: il registro delle azioni (letture dei certificati, esportazioni)
     * annota chi ha chiesto la copia. Nella richiesta stessa (coda «sync») l'utente c'è già.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function asUser(string $userId, Closure $callback): mixed
    {
        $guard = $this->auth->guard();
        $user = $guard instanceof SessionGuard && $guard->id() === null ? User::query()->find($userId) : null;
        if (! $guard instanceof SessionGuard || ! $user instanceof User) {
            return $callback();
        }
        $guard->setUser($user);
        try {
            return $callback();
        } finally {
            $guard->forgetUser();
        }
    }

    private static function str(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    private static function int(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    /** Data e ora dal database in formato ISO 8601, che il browser legge sempre. */
    private static function iso(mixed $value): ?string
    {
        return is_string($value) ? Carbon::parse($value)->toIso8601String() : null;
    }

    private function table(): Builder
    {
        return $this->db->connection($this->context->require()->connection)->table('app.full_exports');
    }
}
