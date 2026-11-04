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
    ) {}

    /** @return array{ok: bool, checked: int, broken_at: int|null, reason: string|null} */
    public function verify(string $orgId): array
    {
        return $this->context->run($orgId, function () use ($orgId): array {
            $connection = $this->db->connection($this->context->require()->connection);
            $previous = ChainedAuditLog::GENESIS;
            $expected = 1;

            $rows = $connection->table('audit.log')->where('org_id', $orgId)->lazyById(500, 'seq');
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

            // Una riga con un numero già usato verrebbe saltata dalla lettura per numero: si contano tutte.
            if ($connection->table('audit.log')->where('org_id', $orgId)->count() !== $headSeq) {
                return self::result(false, $expected - 1, null, 'righe in più nella catena');
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
