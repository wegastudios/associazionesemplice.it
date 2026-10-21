<?php

namespace App\Mail;

use App\Models\SupportTicket;
use App\Models\TicketMessage;
use App\Services\Site\CustomerLink;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Avviso al cliente di una nuova risposta dello staff. */
class StaffReplied extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public SupportTicket $ticket, public TicketMessage $reply) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Nuova risposta alla richiesta n. {$this->ticket->hub_id}");
    }

    public function content(): Content
    {
        return new Content(text: 'mail.staff-replied', with: ['link' => CustomerLink::show($this->ticket)]);
    }
}
