<?php

namespace App\Mail;

use App\Models\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Avviso per i ticket di priorita alta (3) o massima (4). */
class HighPriorityTicket extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Ticket $ticket, public int $level, public string $levelLabel) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "[Priorità {$this->levelLabel}] Ticket {$this->ticket->id} · {$this->ticket->site->name}",
        );
    }

    public function content(): Content
    {
        return new Content(text: 'mail.high-priority-ticket');
    }
}
