<?php

namespace App\Services\Hub;

use App\Exceptions\SupportException;
use App\Models\Site;
use App\Models\Ticket;
use App\Services\AuditLogger;
use App\Support\Actor;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class TicketService
{
    public function __construct(
        private readonly TicketIdGenerator $ids,
        private readonly PriorityNotifier $notifier,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Registra un ticket e gli assegna l'id. Idempotente: lo stesso `request_uuid` dello stesso sito restituisce il ticket gia creato.
     *
     * @param  array<string, mixed>  $data
     * @return array{ticket: Ticket, created: bool}
     */
    public function register(Site $site, array $data): array
    {
        $existing = Ticket::query()->where('site_id', $site->id)->where('request_uuid', $data['request_uuid'])->first();
        if ($existing !== null) {
            return ['ticket' => $existing, 'created' => false];
        }

        $priority = $this->resolvePriority($site, $data['priority'] ?? null);

        if (! empty($data['related_ticket_id'])) {
            $related = Ticket::query()->where('site_id', $site->id)->find((int) $data['related_ticket_id']);
            if ($related === null) {
                throw SupportException::of('RELATED_TICKET_NOT_FOUND', 'Il ticket collegato non esiste per questo portale.', 422);
            }
        }

        try {
            $ticket = DB::transaction(function () use ($site, $data, $priority) {
                return Ticket::create([
                    'id' => $this->ids->next(),
                    'site_id' => $site->id,
                    'request_uuid' => $data['request_uuid'],
                    'subject' => $data['subject'],
                    'priority' => $priority,
                    'status' => 'open',
                    'topic' => $data['topic'] ?? null,
                    'plan' => $data['plan'] ?? null,
                    'customer_name' => $data['customer_name'] ?? null,
                    'customer_email' => $data['customer_email'] ?? null,
                    'related_ticket_id' => $data['related_ticket_id'] ?? null,
                    'privacy_accepted_at' => $data['privacy_accepted_at'] ?? null,
                    'privacy_version' => $data['privacy_version'] ?? null,
                    'last_activity_at' => now(),
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            // Due richieste con lo stesso request_uuid in parallelo: vale la prima
            return [
                'ticket' => Ticket::query()->where('site_id', $site->id)->where('request_uuid', $data['request_uuid'])->firstOrFail(),
                'created' => false,
            ];
        }

        $this->audit->record('ticket.registered', $ticket, ['site' => $site->id, 'priority' => $priority], Actor::site($site->id));
        $this->notifier->notify($ticket->setRelation('site', $site), $priority);

        return ['ticket' => $ticket, 'created' => true];
    }

    /**
     * Aggiorna stato, priorita, argomento o piano di un ticket del sito.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Site $site, Ticket $ticket, array $data): Ticket
    {
        abort_unless($ticket->site_id === $site->id, 404);

        $changes = [];
        $rose = null;

        if (isset($data['priority']) && (int) $data['priority'] !== $ticket->priority) {
            $new = $this->resolvePriority($site, (int) $data['priority']);
            $changes['priority'] = ['from' => $ticket->priority, 'to' => $new];
            if ($new > $ticket->priority) {
                $rose = $new;
            }
            $ticket->priority = $new;
        }

        if (isset($data['status']) && $data['status'] !== $ticket->status) {
            $reopening = $ticket->isClosed() && $data['status'] !== 'closed';
            if ($reopening && ! $site->allow_reopen) {
                throw SupportException::of('REOPEN_DISABLED', 'Questo portale non puo riaprire i ticket chiusi: apri un nuovo ticket.', 409);
            }
            $changes['status'] = ['from' => $ticket->status, 'to' => $data['status']];
            $ticket->status = $data['status'];
            $ticket->closed_at = $data['status'] === 'closed' ? now() : null;
        }

        foreach (['topic', 'plan'] as $field) {
            if (array_key_exists($field, $data) && $data[$field] !== $ticket->{$field}) {
                $changes[$field] = ['from' => $ticket->{$field}, 'to' => $data[$field]];
                $ticket->{$field} = $data[$field];
            }
        }

        if ($changes !== []) {
            $ticket->last_activity_at = now();
            $ticket->save();
            $this->audit->record(isset($changes['status']) && $changes['status']['to'] !== 'closed' && $changes['status']['from'] === 'closed' ? 'ticket.reopened' : 'ticket.updated', $ticket, $changes, Actor::site($site->id));
        }

        if ($rose !== null) {
            $this->notifier->notify($ticket->setRelation('site', $site), $rose);
        }

        return $ticket;
    }

    /** Priorita richiesta (o predefinita) verificata contro i livelli consentiti al sito. */
    private function resolvePriority(Site $site, mixed $requested): int
    {
        $allowed = $site->priorities();

        if ($allowed === []) {
            throw SupportException::of('PRIORITY_NOT_ALLOWED', 'Nessun livello di priorita abilitato per questo portale.', 422, ['allowed_priorities' => []]);
        }

        if ($requested === null || $requested === '') {
            $default = (int) config('support.default_priority');

            return in_array($default, $allowed, true) ? $default : $allowed[0];
        }

        $level = (int) $requested;
        if (! $site->allowsPriority($level)) {
            throw SupportException::of('PRIORITY_NOT_ALLOWED', 'Livello di priorita non consentito a questo portale.', 422, ['allowed_priorities' => $allowed]);
        }

        return $level;
    }
}
