<?php

namespace App\Mail;

use App\Models\SupportTicket;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Avviso allo staff del portale di un nuovo ticket, con il livello di urgenza nell'oggetto. */
class NewTicketForStaff extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public SupportTicket $ticket) {}

    public function urgency(): string
    {
        return (string) (config('support.priority_labels')[$this->ticket->priority] ?? $this->ticket->priority);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: "[Urgenza: {$this->urgency()}] {$this->ticket->subject}");
    }

    public function content(): Content
    {
        $this->ticket->loadMissing(['topic', 'plan']);

        return new Content(text: 'mail.new-ticket-staff', with: [
            'urgency' => $this->urgency(),
            'firstMessage' => $this->ticket->messages()->oldest('id')->value('body'),
            'link' => route('admin.tickets.show', $this->ticket),
        ]);
    }
}
