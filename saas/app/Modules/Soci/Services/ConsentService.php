<?php

declare(strict_types=1);

namespace App\Modules\Soci\Services;

use App\Modules\Audit\Contracts\AuditLog;
use App\Modules\Core\Contracts\OrgContext;
use App\Modules\Soci\Contracts\ConsentChannel;
use App\Modules\Soci\Contracts\ConsentSource;
use App\Modules\Soci\Models\Person;
use Illuminate\Contracts\Auth\Factory as Auth;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;

/**
 * Consensi (dal Consents del plugin): ogni passaggio è un evento che non si modifica, così resta la prova di chi ha
 * dato o revocato cosa, quando e come. Per un minore il consenso lo dà il genitore o tutore ($givenBy).
 */
final readonly class ConsentService
{
    public function __construct(
        private DatabaseManager $db,
        private OrgContext $context,
        private AuditLog $audit,
        private Auth $auth,
    ) {}

    /** Registra il consenso o la revoca; se lo stato è già quello, non scrive nulla. */
    public function set(string $personId, ConsentChannel $channel, bool $granted, ConsentSource $source, ?string $givenBy = null): bool
    {
        if (($this->current($personId)[$channel->value]['granted'] ?? false) === $granted) {
            return false;
        }
        $userId = $this->auth->guard()->id();
        $this->connection()->table('app.consent_events')->insert([
            'org_id' => $this->context->require()->id,
            'id' => Str::uuid7()->toString(),
            'person_id' => $personId,
            'channel' => $channel->value,
            'granted' => $granted,
            'source' => $source->value,
            'given_by_person_id' => $givenBy,
            'recorded_by' => is_string($userId) ? $userId : null,
        ]);
        $this->audit->record($granted ? 'consent.granted' : 'consent.revoked', 'app.people', $personId, ['channel' => $channel->value, 'source' => $source->value]);

        return true;
    }

    public function has(string $personId, ConsentChannel $channel): bool
    {
        return $this->connection()->table('app.current_consents')->where('person_id', $personId)->where('channel', $channel->value)->value('granted') === true;
    }

    /** @return array<string, array{granted: bool, at: string, source: string, given_by: string|null}> canale => stato attuale */
    public function current(string $personId): array
    {
        $rows = $this->connection()->table('app.current_consents')->where('person_id', $personId)
            ->get(['channel', 'granted', 'occurred_at', 'source', 'given_by_person_id']);
        $names = Person::query()->whereIn('id', $rows->pluck('given_by_person_id')->filter())->get(['id', 'first_name', 'last_name'])->keyBy('id');

        $out = [];
        foreach ($rows as $r) {
            if (! is_string($r->channel ?? null)) {
                continue;
            }
            $by = is_string($r->given_by_person_id ?? null) ? $names->get($r->given_by_person_id)?->fullName() : null;
            $out[$r->channel] = [
                'granted' => ($r->granted ?? false) === true,
                'at' => is_string($r->occurred_at ?? null) ? date('d/m/Y', (int) strtotime($r->occurred_at)) : '',
                'source' => ConsentSource::tryFrom(is_string($r->source ?? null) ? $r->source : '')?->label() ?? '',
                'given_by' => $by,
            ];
        }

        return $out;
    }

    private function connection(): ConnectionInterface
    {
        return $this->db->connection($this->context->require()->connection);
    }
}
