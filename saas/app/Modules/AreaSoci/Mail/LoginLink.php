<?php

declare(strict_types=1);

namespace App\Modules\AreaSoci\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Link e codice per entrare nell'area soci. Solo testo: niente dati personali oltre all'indirizzo a cui si scrive. */
final class LoginLink extends Mailable
{
    use Queueable;

    public function __construct(
        public readonly string $orgName,
        public readonly string $link,
        public readonly string $code,
        public readonly int $minutes,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Il tuo accesso all\'area soci · '.$this->orgName);
    }

    public function content(): Content
    {
        return new Content(text: 'mail.area-soci-accesso');
    }
}
