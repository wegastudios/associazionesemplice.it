<?php

namespace App\Services\Hub;

use App\Mail\HighPriorityTicket;
use App\Models\Ticket;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Per i livelli 3 e 4 invia un'email a info@wegastudios.com, agli indirizzi aggiuntivi
 * (ambiente, impostazioni, portale) e all'email del portale che ha ricevuto la richiesta.
 * Una sola email per ticket e per livello raggiunto.
 */
class PriorityNotifier
{
    public function __construct(private readonly SupportSettings $settings) {}

    /** @return list<string> destinatari avvisati (vuoto se non serve o gia inviata) */
    public function notify(Ticket $ticket, int $level): array
    {
        if ($level < (int) config('support.notify_from_priority')) {
            return [];
        }

        $recipients = $this->recipients($ticket);

        try {
            DB::table('priority_notifications')->insert([
                'ticket_id' => $ticket->id,
                'level' => $level,
                'recipients' => json_encode($recipients),
                'created_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return [];
        }

        $label = $this->settings->priorityLabels()[$level] ?? (string) $level;
        Mail::to($recipients)->send(new HighPriorityTicket($ticket->loadMissing('site'), $level, $label));

        return $recipients;
    }

    /** @return list<string> */
    public function recipients(Ticket $ticket): array
    {
        $site = $ticket->site ?? $ticket->load('site')->site;

        $all = array_merge(
            config('support.notify_emails'),
            $this->settings->extraNotifyEmails(),
            $site->notify_emails ?? [],
            [$site->portal_email],
        );

        return array_values(array_unique(array_filter(array_map(fn ($e) => strtolower(trim((string) $e)), $all))));
    }
}
