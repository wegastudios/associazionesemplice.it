<?php

declare(strict_types=1);

namespace App\Modules\Audit\Log;

use App\Modules\Audit\Contracts\AuditLog;
use App\Modules\Cifratura\Contracts\Bytea;
use App\Modules\Cifratura\Contracts\Sealer;
use App\Modules\Core\Contracts\OrgContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use Throwable;

/**
 * Ogni riga riceve il numero successivo della sua associazione e il sigillo HMAC(chiave del registro, sigillo
 * precedente · forma canonica). La testa della catena (audit.heads) si blocca con FOR UPDATE: due scritture della
 * stessa associazione non possono prendere lo stesso numero.
 *
 * Dentro una transazione le righe si raccolgono (PendingEntries) e si legano tutte insieme come ultima cosa prima
 * della conferma: un solo blocco sulla testa, sempre dopo tutti gli altri, tenuto per il tempo di un inserimento.
 * Numero e ora (occurred_at) si assegnano lì, con la testa bloccata: nella catena l'ordine dei numeri resta quello
 * delle ore. L'ora vera dell'azione, presa in record(), va nei dettagli (action_at), coperta dal sigillo. Fuori da una
 * transazione la riga si scrive subito e occurred_at è l'ora dell'azione.
 */
final readonly class ChainedAuditLog implements AuditLog
{
    public const GENESIS = "\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0";

    /** Chiave dei dettagli con l'ora dell'azione, per le righe legate alla conferma di una transazione. */
    public const ACTION_AT = 'action_at';

    /** Righe per istruzione di inserimento: resta sotto il limite di parametri di PostgreSQL. */
    private const CHUNK = 500;

    public function __construct(
        private Application $app,
        private DatabaseManager $db,
        private OrgContext $context,
        private Sealer $sealer,
        private PendingEntries $pending,
    ) {}

    public function record(string $action, ?string $subjectType = null, ?string $subjectId = null, array $details = []): void
    {
        if (preg_match('/^[a-z_]+(\.[a-z_]+)+$/', $action) !== 1) {
            throw new InvalidArgumentException("Azione non valida: {$action}");
        }

        $org = $this->context->require();
        $actor = Actor::current($this->app);
        $details = Entry::validDetails($details);
        $connection = $this->db->connection($org->connection);
        // La chiave si carica adesso: una chiave mancante rifiuta la scrittura subito, come sempre, e alla conferma il
        // servizio chiavi non si chiama con la testa bloccata.
        $this->sealer->seal($org->id, '');

        if ($this->pending->defers($connection)) {
            // occurred_at sarà l'ora del legame alla conferma: l'ora vera dell'azione resta nella riga, coperta dal sigillo.
            $details[self::ACTION_AT] = self::timestamp(CarbonImmutable::now('UTC'));
            $this->pending->add($connection, new Draft($org->id, $actor->type, $actor->id, $action, $subjectType, $subjectId, $actor->ip, $details));

            return;
        }

        $this->appendInTransaction($connection, [new Draft($org->id, $actor->type, $actor->id, $action, $subjectType, $subjectId, $actor->ip, $details)]);
    }

    /**
     * Quando è stata fatta l'azione: action_at nei dettagli per le righe scritte dentro una transazione (legate alla
     * conferma), altrimenti occurred_at. Per la lettura: schermate ed esportazioni.
     */
    public static function actionTime(mixed $occurredAt, mixed $details): string
    {
        $decoded = is_string($details) ? json_decode($details, true) : null;
        $at = is_array($decoded) ? ($decoded[self::ACTION_AT] ?? null) : null;

        return is_string($at) ? $at : (is_scalar($occurredAt) ? (string) $occurredAt : '');
    }

    /** Evento TransactionCommitting: lega le righe raccolte dentro la transazione che sta per confermarsi. */
    public function beforeCommit(Connection $connection): void
    {
        $drafts = $this->pending->committing($connection);
        if ($drafts === []) {
            return;
        }

        try {
            $this->append($connection, $drafts);
        } catch (Throwable $e) {
            // Senza le sue righe di registro la transazione non si conferma e non resta aperta sulla connessione.
            $this->pending->forget($connection);
            try {
                $pdo = $connection->getPdo();
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            } catch (Throwable) {
                // Connessione caduta: la transazione è già finita.
            }
            throw $e;
        }
    }

    /** Evento TransactionCommitted: tornati al livello di base, lega le righe rimaste (nei test, dentro la loro transazione). */
    public function afterCommit(Connection $connection): void
    {
        $drafts = $this->pending->committed($connection);
        if ($drafts === []) {
            return;
        }

        if ($connection->transactionLevel() > 0) {
            $this->append($connection, $drafts);
        } else {
            $this->appendInTransaction($connection, $drafts);
        }
    }

    public static function timestamp(CarbonImmutable $time): string
    {
        return $time->utc()->format('Y-m-d\TH:i:s.uP');
    }

    /** @param  list<Draft>  $drafts */
    private function appendInTransaction(Connection $connection, array $drafts): void
    {
        $connection->transaction(function () use ($connection, $drafts): void {
            $this->append($connection, $drafts);
        });
    }

    /** @param  list<Draft>  $drafts  nell'ordine in cui sono state scritte; va chiamato dentro una transazione */
    private function append(Connection $connection, array $drafts): void
    {
        $byOrg = [];
        foreach ($drafts as $draft) {
            $byOrg[$draft->orgId][] = $draft;
        }
        // Più associazioni nella stessa transazione: le teste si bloccano sempre nello stesso ordine.
        ksort($byOrg, SORT_STRING);

        foreach ($byOrg as $orgId => $list) {
            $this->appendForOrg($connection, (string) $orgId, $list);
        }
    }

    /** @param  list<Draft>  $drafts  mai vuoto: arriva da append() */
    private function appendForOrg(Connection $connection, string $orgId, array $drafts): void
    {
        // Alla conferma l'associazione corrente può essere un'altra (o nessuna): per queste righe vale la loro, fino alla
        // fine della transazione, poi si torna al valore di prima.
        $current = $this->context->current();
        $restore = null;
        if ($current === null || $current->id !== $orgId || $current->connection !== $connection->getName()) {
            $previous = $connection->scalar("SELECT current_setting('app.org_id', true)");
            $restore = is_string($previous) ? $previous : '';
            $connection->select("SELECT set_config('app.org_id', ?, true)", [$orgId]);
        }

        // La testa si crea solo la prima volta: di solito c'è già e basta leggerla (e bloccarla).
        $head = $connection->table('audit.heads')->where('org_id', $orgId)->lockForUpdate()->first(['seq', 'hash']);
        if ($head === null) {
            $connection->table('audit.heads')->insertOrIgnore(['org_id' => $orgId, 'seq' => 0, 'hash' => Bytea::encode(self::GENESIS)]);
            $head = $connection->table('audit.heads')->where('org_id', $orgId)->lockForUpdate()->first(['seq', 'hash']);
        }
        if ($head === null) {
            throw new InvalidArgumentException('Testa del registro non disponibile.');
        }

        $seq = Entry::seq($head->seq);
        $hash = Bytea::decode($head->hash);
        $occurredAt = '';
        $rows = [];
        foreach ($drafts as $draft) {
            $occurredAt = self::timestamp(CarbonImmutable::now('UTC'));
            $entry = $draft->entry(++$seq, $occurredAt);
            $hash = $this->sealer->seal($orgId, $hash.$entry->canonical());
            $rows[] = [
                'org_id' => $entry->orgId,
                'occurred_at' => $entry->occurredAt,
                'seq' => $entry->seq,
                'actor_type' => $entry->actorType,
                'actor_id' => $entry->actorId,
                'action' => $entry->action,
                'subject_type' => $entry->subjectType,
                'subject_id' => $entry->subjectId,
                'ip' => $entry->ip,
                'details' => json_encode($entry->details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'hash' => Bytea::encode($hash),
            ];
        }

        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            $connection->table('audit.log')->insert($chunk);
        }
        $connection->table('audit.heads')->where('org_id', $orgId)
            ->update(['seq' => $seq, 'hash' => Bytea::encode($hash), 'updated_at' => $occurredAt]);

        if ($restore !== null) {
            $connection->select("SELECT set_config('app.org_id', ?, true)", [$restore]);
        }
    }
}
