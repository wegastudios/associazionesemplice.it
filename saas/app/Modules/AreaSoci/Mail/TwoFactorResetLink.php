<?php

declare(strict_types=1);

namespace App\Modules\AreaSoci\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Link per spegnere la verifica in due passaggi dell'area soci (telefono e codici di riserva persi). Solo testo. */
final class TwoFactorResetLink extends Mailable
{
    use Queueable;

    public function __construct(
        public readonly string $orgName,
        public readonly string $link,
        public readonly int $minutes,
        public readonly ?string $contactEmail = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->orgName.' · Spegni la verifica in due passaggi', replyTo: $this->contactEmail === null ? [] : [new Address($this->contactEmail, $this->orgName)]);
    }

    public function content(): Content
    {
        return new Content(text: 'mail.area-soci-verifica-ripristino');
    }
}
