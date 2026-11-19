<?php

declare(strict_types=1);

namespace App\Modules\AreaSoci\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Avviso al socio: una parte o tutto il suo pagamento online è stato restituito. Solo testo, nessun dato oltre a importo, data e motivo. */
final class PaymentRefunded extends Mailable
{
    use Queueable;

    public function __construct(
        public readonly string $orgName,
        public readonly string $amount,
        public readonly string $paidOn,
        public readonly string $reason,
        public readonly ?string $contactEmail = null,
        public readonly ?string $contactPhone = null,
        /** @var list<string> Che cosa resta valido dopo lo storno (prenotazione, iscrizione), una frase per riga in coda all'email. */
        public readonly array $stays = [],
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->orgName.' · Rimborso del tuo pagamento', replyTo: $this->contactEmail === null ? [] : [new Address($this->contactEmail, $this->orgName)]);
    }

    public function content(): Content
    {
        return new Content(text: 'mail.pagamento-rimborsato');
    }
}
