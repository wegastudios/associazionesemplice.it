<?php

namespace App\Services\Site;

use App\Exceptions\HubRejectedException;
use App\Exceptions\HubUnavailableException;
use App\Mail\StaffReplied;
use App\Models\SupportTicket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Actor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class TicketService
{
    public function __construct(
        private readonly HubClient $hub,
        private readonly TicketRegistrar $registrar,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Apre un ticket per un cliente. L'id arriva dall'hub: se non risponde il ticket resta in attesa di registrazione.
     *
     * @param  array{subject: string, body: string, customer_name: string, customer_email: string, topic_id?: ?int, plan_id?: ?int, related_hub_id?: ?int, privacy_accepted_at?: mixed, privacy_version?: ?string, portal_context?: ?array}  $data
     */
    public function open(array $data): SupportTicket
    {
        $ticket = DB::transaction(function () use ($data) {
            $ticket = SupportTicket::create([
                'request_uuid' => (string) Str::uuid(),
                'subject' => $data['subject'],
                'topic_id' => $data['topic_id'] ?? null,
                'plan_id' => $data['plan_id'] ?? null,
                'customer_name' => $data['customer_name'],
                'customer_email' => mb_strtolower($data['customer_email']),
                'priority' => $this->defaultPriority(),
                'status' => 'open',
                'related_hub_id' => $data['related_hub_id'] ?? null,
                'privacy_accepted_at' => $data['privacy_accepted_at'] ?? null,
                'privacy_version' => $data['privacy_version'] ?? null,
                'portal_context' => $data['portal_context'] ?? null,
                'last_activity_at' => now(),
            ]);

            $ticket->messages()->create(['author_type' => 'customer', 'author_name' => $data['customer_name'], 'body' => $data['body']]);

            return $ticket;
        });

        $this->registrar->register($ticket);

        return $ticket->refresh();
    }

    /**
     * Risposta del cliente. Su un ticket chiuso serve la riapertura, consentita dalle regole del portale sull'hub.
     *
     * @throws HubRejectedException REOPEN_DISABLED se il portale non puo riaprire
     * @throws HubUnavailableException se l'hub non risponde durante una riapertura
     */
    public function customerReply(SupportTicket $ticket, string $body): TicketMessage
    {
        if ($ticket->status !== 'open') {
            $reopening = $ticket->isClosed();
            try {
                $this->hub->update($ticket, ['status' => 'open']);
            } catch (HubUnavailableException $e) {
                if ($reopening) {
                    throw $e;
                }
                Log::warning('Stato non sincronizzato con l\'hub', ['ticket' => $ticket->id]);
            }
            $ticket->forceFill(['status' => 'open'])->save();
            if ($reopening) {
                $this->audit->record('ticket.reopened', $ticket, ['by' => 'customer']);
            }
        }

        $message = $ticket->messages()->create(['author_type' => 'customer', 'author_name' => $ticket->customer_name, 'body' => $body]);
        $ticket->forceFill(['last_activity_at' => now()])->save();

        return $message;
    }

    /** Risposta dello staff (o nota interna). La risposta al cliente porta il ticket in attesa e lo avvisa per email. */
    public function staffReply(SupportTicket $ticket, User $user, string $body, bool $internalNote = false): TicketMessage
    {
        $message = $ticket->messages()->create([
            'author_type' => $internalNote ? 'note' : 'staff',
            'author_name' => $user->name,
            'user_id' => $user->id,
            'body' => $body,
        ]);

        $ticket->forceFill(['last_activity_at' => now()]);

        if (! $internalNote) {
            if (in_array($ticket->status, ['open'], true)) {
                $ticket->status = 'pending';
                try {
                    $this->hub->update($ticket, ['status' => 'pending']);
                } catch (HubUnavailableException|HubRejectedException) {
                    Log::warning('Stato non sincronizzato con l\'hub', ['ticket' => $ticket->id]);
                }
            }
            if ($ticket->isRegistered()) {
                Mail::to($ticket->customer_email)->send(new StaffReplied($ticket, $message));
            }
        }

        $ticket->save();

        return $message;
    }

    /**
     * Cambia stato. Il cambio e sincronizzato con l'hub prima di salvarlo: se l'hub lo rifiuta (es. riapertura non consentita) non cambia nulla.
     *
     * @throws HubRejectedException
     * @throws HubUnavailableException
     */
    public function setStatus(SupportTicket $ticket, string $status, ?User $user = null): void
    {
        if ($status === $ticket->status) {
            return;
        }

        $this->hub->update($ticket, ['status' => $status]);
        $from = $ticket->status;
        $ticket->forceFill(['status' => $status, 'last_activity_at' => now()])->save();
        $this->audit->record('ticket.status_changed', $ticket, ['from' => $from, 'to' => $status], $user ? Actor::user($user->id) : null);
    }

    /**
     * Cambia la priorita centrale (0-4), nei livelli che l'hub consente a questo portale.
     *
     * @throws HubRejectedException
     * @throws HubUnavailableException
     */
    public function setPriority(SupportTicket $ticket, int $priority, ?User $user = null): void
    {
        if ($priority === $ticket->priority) {
            return;
        }

        $this->hub->update($ticket, ['priority' => $priority]);
        $from = $ticket->priority;
        $ticket->forceFill(['priority' => $priority])->save();
        $this->audit->record('ticket.priority_changed', $ticket, ['from' => $from, 'to' => $priority], $user ? Actor::user($user->id) : null);
    }

    /** Livello iniziale: quello predefinito se consentito, altrimenti il piu basso consentito. */
    private function defaultPriority(): int
    {
        $default = (int) config('support.default_priority');
        $allowed = $this->hub->isConfigured() ? $this->hub->allowedPriorities() : null;

        if ($allowed === null || $allowed === [] || in_array($default, $allowed, true)) {
            return $default;
        }

        sort($allowed);

        return $allowed[0];
    }
}
