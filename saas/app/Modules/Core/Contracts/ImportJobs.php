<?php

declare(strict_types=1);

namespace App\Modules\Core\Contracts;

use App\Modules\Cifratura\Contracts\Bytea;
use App\Modules\Cifratura\Contracts\FieldCipher;
use Closure;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Auth\Factory as Auth;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * Import da file come lavori in coda (H10, H11). L'elenco da importare si salva una volta, cifrato (ha dati personali);
 * poi un lavoro in coda lo applica a blocchi. Ogni blocco è una transazione che salva insieme le righe e il punto a cui
 * si è arrivati: se il lavoro si ferma, riprende dal blocco dopo senza ripetere niente. Un solo import attivo per tipo.
 *
 * Stati: queued (in coda), running (in corso), done (finito), failed (fermo per un errore: si riprende), cancelled
 * (annullato prima di cominciare: nessuna riga importata). Il lavoro gira a nome di chi l'ha avviato (registro, created_by).
 *
 * @phpstan-type Job array{id: string, status: string, total: int, done: int, state: array<string, mixed>, stalled: bool}
 */
final readonly class ImportJobs
{
    /** Un lavoro in coda o in corso senza avanzamento da tanto si considera fermo (lavoratore caduto): si può riprendere. */
    public const STALLED_MINUTES = 15;

    /** Gli import finiti restano a schermo per questo tempo, poi la pagina torna vuota; dopo sette giorni si cancellano. */
    private const SHOWN_MINUTES = 60;

    private const KEEP_DAYS = 7;

    public function __construct(
        private DatabaseManager $db,
        private OrgContext $context,
        private FieldCipher $cipher,
        private Auth $auth,
    ) {}

    /**
     * Nuovo import in coda: null se ce n'è già uno attivo dello stesso tipo.
     *
     * @param  list<mixed>  $items  elementi da applicare in ordine
     * @param  array<string, scalar|null>  $options  senza dati personali
     * @param  array<string, mixed>  $state  stato iniziale (esito a zero)
     */
    public function create(string $kind, array $items, array $options, array $state, ?string $userId): ?string
    {
        $c = $this->connection();
        // Pulizia senza cron: via quelli finiti da più di sette giorni e fermi quelli abbandonati da un lavoratore caduto.
        $c->table('app.import_jobs')->where('kind', $kind)->whereIn('status', ['done', 'cancelled', 'failed'])
            ->where('updated_at', '<', now()->subDays(self::KEEP_DAYS))->delete();
        $c->table('app.import_jobs')->where('kind', $kind)->whereIn('status', ['queued', 'running'])
            ->where('updated_at', '<', now()->subMinutes(self::STALLED_MINUTES))->update(['status' => 'failed', 'updated_at' => now()]);

        $id = Str::uuid7()->toString();
        try {
            $c->transaction(fn () => $c->table('app.import_jobs')->insert([
                'org_id' => $this->orgId(),
                'id' => $id,
                'kind' => $kind,
                'total' => count($items),
                'options' => json_encode($options, JSON_THROW_ON_ERROR),
                'plan_enc' => $this->seal($id, 'plan', $items),
                'state_enc' => $this->seal($id, 'state', $state),
                'user_id' => $userId,
            ]));
        } catch (UniqueConstraintViolationException) {
            return null;
        }

        return $id;
    }

    /**
     * Applica l'import a blocchi finché non finisce o non passano $seconds. $block riceve gli elementi del blocco, lo stato
     * e le opzioni dentro la transazione del blocco e restituisce lo stato nuovo; $finish gira nella transazione dell'ultimo
     * blocco. Restituisce false se resta lavoro: chi chiama rimette in coda.
     *
     * @param  Closure(list<mixed>, array<string, mixed>, array<string, mixed>): array<string, mixed>  $block
     * @param  Closure(array<string, mixed>, array<string, mixed>): array<string, mixed>  $finish
     */
    public function run(string $id, int $blockSize, int $seconds, Closure $block, Closure $finish): bool
    {
        $c = $this->connection();
        $head = $c->table('app.import_jobs')->where('id', $id)->first(['status', 'user_id', 'plan_enc']);
        if ($head === null || ! in_array($head->status, ['queued', 'running'], true) || $head->plan_enc === null) {
            return true;
        }
        $items = array_values($this->open($id, 'plan', $head->plan_enc));
        $deadline = hrtime(true) + max(0, $seconds) * 1_000_000_000;

        return $this->actingAs(is_string($head->user_id) ? $head->user_id : null, function () use ($c, $id, $items, $blockSize, $block, $finish, $deadline): bool {
            do {
                $more = $c->transaction(function () use ($c, $id, $items, $blockSize, $block, $finish): ?bool {
                    // La riga bloccata ferma un secondo lavoratore sullo stesso import: ripartirà dal punto salvato da questo.
                    $row = $c->table('app.import_jobs')->where('id', $id)->lockForUpdate()->first(['status', 'done', 'options', 'state_enc']);
                    if ($row === null || ! in_array($row->status, ['queued', 'running'], true)) {
                        return null;
                    }
                    $done = is_numeric($row->done) ? (int) $row->done : 0;
                    $state = $this->open($id, 'state', $row->state_enc);
                    /** @var array<string, mixed>|null $options */
                    $options = json_decode(is_string($row->options) ? $row->options : '{}', true);
                    $options ??= [];
                    $slice = array_slice($items, $done, max(1, $blockSize));
                    if ($slice !== []) {
                        $state = $block($slice, $state, $options);
                    }
                    $done += count($slice);
                    $finished = $done >= count($items);
                    if ($finished) {
                        $state = $finish($state, $options);
                    }
                    $c->table('app.import_jobs')->where('id', $id)->update([
                        'status' => $finished ? 'done' : 'running',
                        'done' => $done,
                        'state_enc' => $this->seal($id, 'state', $state),
                        'updated_at' => now(),
                        'started_at' => $c->raw('coalesce(started_at, now())'),
                        'finished_at' => $finished ? now() : null,
                    ] + ($finished ? ['plan_enc' => null] : []));

                    return ! $finished;
                });
            } while ($more === true && hrtime(true) < $deadline);

            return $more !== true;
        });
    }

    /** Annulla un import che non è ancora partito. false se è già partito (allora si porta a termine). */
    public function cancel(string $id, string $kind): bool
    {
        return $this->connection()->table('app.import_jobs')->where('id', $id)->where('kind', $kind)->where('status', 'queued')->where('done', 0)
            ->update(['status' => 'cancelled', 'plan_enc' => null, 'updated_at' => now(), 'finished_at' => now()]) === 1;
    }

    /**
     * Rimette in coda un import fermo (errore, oppure in coda o in corso senza avanzamento da troppo): riparte dal punto
     * salvato, sempre a nome e con i permessi di chi l'ha avviato. false se non c'è niente da riprendere.
     */
    public function resume(string $id, string $kind): bool
    {
        return $this->connection()->table('app.import_jobs')->where('id', $id)->where('kind', $kind)->whereNotNull('plan_enc')
            ->where(fn (Builder $q) => $q->where('status', 'failed')->orWhere(fn (Builder $w) => $w->whereIn('status', ['queued', 'running'])
                ->where('updated_at', '<', now()->subMinutes(self::STALLED_MINUTES))))
            ->update(['status' => 'queued', 'updated_at' => now()]) === 1;
    }

    /** Il lavoro in coda si è arreso: l'import resta fermo dov'era, e si può riprendere. */
    public function fail(string $id): void
    {
        $this->connection()->table('app.import_jobs')->where('id', $id)->whereIn('status', ['queued', 'running'])
            ->update(['status' => 'failed', 'updated_at' => now()]);
    }

    /**
     * L'import da mostrare: quello attivo o fermo, o l'ultimo finito da poco. null se non ce n'è.
     *
     * @return Job|null
     */
    public function current(string $kind): ?array
    {
        $row = $this->connection()->table('app.import_jobs')->where('kind', $kind)->orderByDesc('created_at')
            ->selectRaw("id, status, total, done, state_enc, coalesce(finished_at < now() - make_interval(mins => ?), false) AS old,
                status IN ('queued', 'running') AND updated_at < now() - make_interval(mins => ?) AS stalled", [self::SHOWN_MINUTES, self::STALLED_MINUTES])
            ->first();
        if ($row === null || ! is_string($row->id ?? null) || ! is_string($row->status ?? null) || ($row->old ?? false) === true) {
            return null;
        }

        return [
            'id' => $row->id,
            'status' => $row->status,
            'total' => is_numeric($row->total ?? null) ? (int) $row->total : 0,
            'done' => is_numeric($row->done ?? null) ? (int) $row->done : 0,
            'state' => $this->open($row->id, 'state', $row->state_enc ?? null),
            'stalled' => ($row->stalled ?? false) === true,
        ];
    }

    /**
     * Esegue $work a nome di chi ha avviato l'import, e poi rimette com'era (in coda non c'è nessuno; in una richiesta
     * con la coda sincrona c'è già l'utente collegato).
     *
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    private function actingAs(?string $userId, Closure $work): mixed
    {
        $guard = $this->auth->guard();
        if ($userId === null || ! $guard instanceof SessionGuard || ($guard->hasUser() && $guard->id() === $userId)) {
            return $work();
        }
        $previous = $guard->hasUser() ? $guard->user() : null;
        $guard->onceUsingId($userId);
        try {
            return $work();
        } finally {
            if ($previous !== null) {
                $guard->setUser($previous);
            } else {
                $guard->forgetUser();
            }
        }
    }

    private function seal(string $id, string $field, mixed $value): string
    {
        return Bytea::encode($this->cipher->encrypt($this->orgId(), 'app.import_jobs.'.$field, json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $id));
    }

    /** @return array<string, mixed> (l'elenco da importare è una lista: chi lo legge prende array_values) */
    private function open(string $id, string $field, mixed $stored): array
    {
        /** @var array<string, mixed>|scalar|null $value */
        $value = json_decode($this->cipher->decrypt($this->orgId(), 'app.import_jobs.'.$field, Bytea::decode($stored), $id), true, 512, JSON_THROW_ON_ERROR);

        return is_array($value) ? $value : [];
    }

    private function orgId(): string
    {
        return $this->context->require()->id;
    }

    private function connection(): ConnectionInterface
    {
        return $this->db->connection($this->context->require()->connection);
    }
}
