<?php

declare(strict_types=1);

namespace App\Modules\AreaSoci\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Avviso: la verifica in due passaggi di una scheda è stata spenta con il link via email o dalla segreteria.
 * Al socio ($toStaff falso) e alla segreteria ($toStaff vero, con il nome della scheda). Solo testo.
 */
final class TwoFactorOffNotice extends Mailable
{
    use Queueable;

    /** @param  'email'|'staff'  $how */
    public function __construct(
        public readonly string $orgName,
        public readonly string $how,
        public readonly bool $toStaff,
        public readonly string $personName = '',
        public readonly ?string $contactEmail = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->orgName.' · Verifica in due passaggi spenta', replyTo: $this->toStaff || $this->contactEmail === null ? [] : [new Address($this->contactEmail, $this->orgName)]);
    }

    public function content(): Content
    {
        return new Content(text: 'mail.area-soci-verifica-spenta');
    }
}
