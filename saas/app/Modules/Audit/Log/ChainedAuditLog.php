<?php

declare(strict_types=1);

namespace App\Modules\Audit\Log;

use App\Modules\Audit\Contracts\AuditLog;
use App\Modules\Cifratura\Contracts\Bytea;
use App\Modules\Cifratura\Contracts\Sealer;
use App\Modules\Core\Contracts\OrgContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;

/**
 * Ogni riga riceve il numero successivo della sua associazione e il sigillo HMAC(chiave del registro, sigillo
 * precedente · forma canonica). La testa della catena (audit.heads) si blocca con FOR UPDATE: due scritture della
 * stessa associazione non possono prendere lo stesso numero.
 */
final readonly class ChainedAuditLog implements AuditLog
{
    public const GENESIS = "\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0";

    public function __construct(
        private Application $app,
        private DatabaseManager $db,
        private OrgContext $context,
        private Sealer $sealer,
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

        $connection->transaction(function () use ($connection, $org, $actor, $action, $subjectType, $subjectId, $details): void {
            $connection->table('audit.heads')->insertOrIgnore(['org_id' => $org->id, 'seq' => 0, 'hash' => Bytea::encode(self::GENESIS)]);
            $head = $connection->table('audit.heads')->where('org_id', $org->id)->lockForUpdate()->first(['seq', 'hash']);
            if ($head === null) {
                throw new InvalidArgumentException('Testa del registro non disponibile.');
            }

            $entry = new Entry(
                $org->id,
                Entry::seq($head->seq) + 1,
                self::timestamp(CarbonImmutable::now('UTC')),
                $actor->type,
                $actor->id,
                $action,
                $subjectType,
                $subjectId,
                $actor->ip,
                $details,
            );
            $hash = $this->sealer->seal($org->id, Bytea::decode($head->hash).$entry->canonical());

            $connection->table('audit.log')->insert([
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
            ]);
            $connection->table('audit.heads')->where('org_id', $org->id)
                ->update(['seq' => $entry->seq, 'hash' => Bytea::encode($hash), 'updated_at' => $entry->occurredAt]);
        });
    }

    public static function timestamp(CarbonImmutable $time): string
    {
        return $time->utc()->format('Y-m-d\TH:i:s.uP');
    }
}
