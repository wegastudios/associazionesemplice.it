<?php

namespace App\Services\Site;

use App\Exceptions\HubRejectedException;
use App\Exceptions\HubUnavailableException;
use App\Mail\TicketReceived;
use App\Models\SupportTicket;
use Illuminate\Support\Facades\Mail;

/**
 * Ottiene dall'hub l'id dei ticket. Se l'hub non risponde il ticket resta "in attesa di registrazione"
 * e viene riprovato dallo scheduler; l'id non si genera mai in locale.
 */
class TicketRegistrar
{
    public function __construct(private readonly HubClient $hub) {}

    /** Prova a registrare il ticket. Restituisce true se ora ha l'id. */
    public function register(SupportTicket $ticket): bool
    {
        if ($ticket->isRegistered()) {
            return true;
        }

        try {
            $id = $this->registerWithClamp($ticket);
        } catch (HubUnavailableException $e) {
            $this->fail($ticket, $e->getMessage());

            return false;
        } catch (HubRejectedException $e) {
            $this->fail($ticket, "[{$e->errorCode}] {$e->getMessage()}");

            return false;
        }

        $ticket->forceFill(['hub_id' => $id, 'registration_error' => null, 'last_activity_at' => now()])->save();

        // Se nel frattempo lo stato e cambiato, l'hub lo riceve ora
        if ($ticket->status !== 'open') {
            try {
                $this->hub->update($ticket, ['status' => $ticket->status]);
            } catch (HubUnavailableException|HubRejectedException) {
                // lo stato si riallinea alla prossima modifica
            }
        }

        Mail::to($ticket->customer_email)->send(new TicketReceived($ticket));

        return true;
    }

    /** Registra i ticket in attesa: prima quelli con pochi tentativi, poi gli altri ogni 15 minuti. */
    public function registerPending(): int
    {
        if (! $this->hub->isConfigured()) {
            return 0;
        }

        $done = 0;
        SupportTicket::query()->whereNull('hub_id')
            ->where(fn ($q) => $q->where('registration_attempts', '<', 6)->orWhere('updated_at', '<', now()->subMinutes(15)))
            ->orderBy('created_at')->limit(50)->get()
            ->each(function (SupportTicket $ticket) use (&$done) {
                if ($this->register($ticket)) {
                    $done++;
                }
            });

        return $done;
    }

    /** Se l'hub non ammette la priorita richiesta, si usa il livello consentito piu vicino (verso il basso) e si riprova una volta. */
    private function registerWithClamp(SupportTicket $ticket): int
    {
        try {
            return $this->hub->register($ticket);
        } catch (HubRejectedException $e) {
            if ($e->errorCode !== 'PRIORITY_NOT_ALLOWED' || empty($e->allowedPriorities)) {
                throw $e;
            }

            $allowed = $e->allowedPriorities;
            sort($allowed);
            $below = array_filter($allowed, fn (int $l) => $l <= $ticket->priority);
            $ticket->forceFill(['priority' => $below !== [] ? max($below) : $allowed[0]])->save();

            return $this->hub->register($ticket);
        }
    }

    private function fail(SupportTicket $ticket, string $message): void
    {
        $ticket->forceFill([
            'registration_attempts' => $ticket->registration_attempts + 1,
            'registration_error' => mb_substr($message, 0, 500),
        ])->save();
    }
}
