<?php

namespace App\Mail;

use App\Models\SupportTicket;
use App\Services\Site\CustomerLink;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Conferma al cliente con il numero del ticket e il link per seguirlo. */
class TicketReceived extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public SupportTicket $ticket) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Abbiamo ricevuto la tua richiesta · ticket {$this->ticket->hub_id}");
    }

    public function content(): Content
    {
        return new Content(text: 'mail.ticket-received', with: ['link' => CustomerLink::show($this->ticket)]);
    }
}
