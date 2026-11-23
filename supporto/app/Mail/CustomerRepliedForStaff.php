<?php

namespace App\Mail;

use App\Models\SupportTicket;
use App\Models\TicketMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Avviso allo staff di una nuova risposta del cliente, con il livello di urgenza nell'oggetto. */
class CustomerRepliedForStaff extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public SupportTicket $ticket, public TicketMessage $reply) {}

    public function urgency(): string
    {
        return (string) (config('support.priority_labels')[$this->ticket->priority] ?? $this->ticket->priority);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: "[Urgenza: {$this->urgency()}] Risposta del cliente: {$this->ticket->subject}");
    }

    public function content(): Content
    {
        return new Content(text: 'mail.customer-replied-staff', with: [
            'urgency' => $this->urgency(),
            'link' => route('admin.tickets.show', $this->ticket),
        ]);
    }
}
