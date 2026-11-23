<?php

declare(strict_types=1);

namespace App\Modules\Audit\Log;

use App\Modules\Cifratura\Contracts\Bytea;
use App\Modules\Cifratura\Contracts\Sealer;
use App\Modules\Core\Contracts\OrgContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;

/**
 * Ripercorre la catena di un'associazione dal primo numero e ricalcola ogni sigillo: una riga modificata, tolta,
 * aggiunta o spostata, o una testa che non corrisponde, interrompono la verifica nel punto esatto.
 */
final readonly class ChainVerifier
{
    public function __construct(
        private DatabaseManager $db,
        private OrgContext $context,
        private Sealer $sealer,
        private HeadCopies $copies,
    ) {}

    /** @return array{ok: bool, checked: int, broken_at: int|null, reason: string|null} */
    public function verify(string $orgId, bool $fromCheckpoint = false): array
    {
        return $this->context->run($orgId, function () use ($orgId, $fromCheckpoint): array {
            $connection = $this->db->connection($this->context->require()->connection);
            $previous = ChainedAuditLog::GENESIS;
            $expected = 1;

            // Dopo la cancellazione delle partizioni vecchie la catena riparte dall'ancora (audit.anchors).
            $anchor = $connection->table('audit.anchors')->where('org_id', $orgId)->first(['seq', 'hash']);
            $anchorSeq = $anchor === null ? 0 : Entry::seq($anchor->seq);
            if ($anchor !== null) {
                $previous = Bytea::decode($anchor->hash);
                $expected = $anchorSeq + 1;
            }

            // Verifica di ogni notte: riparte dall'ultimo punto di controllo giusto, se la riga di quel numero ha ancora
            // lo stesso sigillo; altrimenti (o a richiesta) si ripercorre l'intera catena.
            $startSeq = $anchorSeq;
            if ($fromCheckpoint) {
                $point = $connection->table('audit.verify_checkpoints')->where('org_id', $orgId)->first(['seq', 'hash']);
                if ($point !== null && Entry::seq($point->seq) > $anchorSeq) {
                    $row = $connection->table('audit.log')->where('org_id', $orgId)->where('seq', Entry::seq($point->seq))->first(['hash']);
                    if ($row !== null && hash_equals(Bytea::decode($point->hash), Bytea::decode($row->hash))) {
                        $startSeq = Entry::seq($point->seq);
                        $previous = Bytea::decode($point->hash);
                        $expected = $startSeq + 1;
                    }
                }
            }

            $rows = $connection->table('audit.log')->where('org_id', $orgId)->where('seq', '>', $startSeq)->lazyById(500, 'seq');
            foreach ($rows as $row) {
                /** @var object{seq: int, occurred_at: string, actor_type: string, actor_id: string|null, action: string, subject_type: string|null, subject_id: string|null, ip: string|null, details: string, hash: mixed} $row */
                if ((int) $row->seq !== $expected) {
                    return self::result(false, $expected - 1, $expected, 'numero mancante o ripetuto');
                }

                $details = json_decode($row->details, true, 8, JSON_THROW_ON_ERROR);
                $entry = new Entry(
                    $orgId,
                    (int) $row->seq,
                    ChainedAuditLog::timestamp(CarbonImmutable::parse($row->occurred_at)),
                    $row->actor_type,
                    $row->actor_id,
                    $row->action,
                    $row->subject_type,
                    $row->subject_id,
                    $row->ip,
                    is_array($details) ? $details : [],
                );
                $hash = Bytea::decode($row->hash);

                if (! $this->sealer->verify($orgId, $previous.$entry->canonical(), $hash)) {
                    return self::result(false, $expected - 1, $expected, 'sigillo non valido');
                }

                $previous = $hash;
                $expected++;
            }

            $head = $connection->table('audit.heads')->where('org_id', $orgId)->first(['seq', 'hash']);
            $headSeq = $head === null ? 0 : Entry::seq($head->seq);
            $headHash = $head === null ? ChainedAuditLog::GENESIS : Bytea::decode($head->hash);

            if ($headSeq !== $expected - 1 || ! hash_equals($headHash, $previous)) {
                return self::result(false, $expected - 1, $expected, 'righe mancanti in fondo alla catena');
            }

            // Una riga con un numero già usato verrebbe saltata dalla lettura per numero: si contano quelle del tratto
            // appena percorso (dal punto di controllo, non tutto il registro ogni notte). Un numero ripetuto più
            // indietro lo trova la verifica dell'intera catena, a rotazione.
            if ($connection->table('audit.log')->where('org_id', $orgId)->where('seq', '>', $startSeq)->count() !== $headSeq - $startSeq) {
                return self::result(false, $expected - 1, null, 'righe in più nella catena');
            }

            // Testa e ancora si confrontano con l'ultima copia fatta fuori dal database: una coda tagliata o un'ancora
            // riscritta insieme alla testa, che dentro il database tornerebbero, qui si vedono.
            $conflict = $this->copies->conflict(
                $orgId,
                $connection,
                $headSeq,
                $headHash,
                $anchor === null ? null : ['seq' => $anchorSeq, 'hash' => Bytea::decode($anchor->hash)],
            );
            if ($conflict !== null) {
                return self::result(false, $expected - 1, null, $conflict);
            }

            // Catena giusta: il punto di controllo avanza alla testa (per le associazioni senza righe nuove non cambia).
            if ($headSeq > $anchorSeq) {
                $connection->table('audit.verify_checkpoints')->upsert(
                    [['org_id' => $orgId, 'seq' => $headSeq, 'hash' => Bytea::encode($headHash), 'verified_at' => now()]],
                    ['org_id'],
                    ['seq', 'hash', 'verified_at'],
                );
            }

            return self::result(true, $expected - 1, null, null);
        });
    }

    /** @return array{ok: bool, checked: int, broken_at: int|null, reason: string|null} */
    private static function result(bool $ok, int $checked, ?int $brokenAt, ?string $reason): array
    {
        return ['ok' => $ok, 'checked' => $checked, 'broken_at' => $brokenAt, 'reason' => $reason];
    }
}
