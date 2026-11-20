<?php

declare(strict_types=1);

namespace App\Modules\AreaSoci\Mail;

use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** La ricevuta della donazione fatta dalla pagina pubblica: va a chi ha donato, che non ha l'area soci. */
final class DonationReceiptMail extends Mailable
{
    /** @param  list<array{pdf: string, filename: string}>  $files */
    public function __construct(
        public readonly string $orgName,
        public readonly string $firstName,
        public readonly string $amount,
        public readonly string $numbers,
        private readonly array $files,
        public readonly ?string $contactEmail,
        public readonly ?string $contactPhone,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->orgName.' · Grazie per la tua donazione', replyTo: $this->contactEmail === null ? [] : [new Address($this->contactEmail, $this->orgName)]);
    }

    public function content(): Content
    {
        $contact = $this->contactEmail !== null ? '<br>Segreteria: '.e($this->contactEmail).($this->contactPhone !== null ? ' · tel. '.e($this->contactPhone) : '') : '';

        $hello = $this->firstName !== '' ? 'Ciao '.e($this->firstName).', g' : 'G';

        return new Content(htmlString: '<p>'.$hello.'razie per la donazione di '.e($this->amount).' a '.e($this->orgName).': il tuo aiuto ci serve davvero.</p><p>In allegato trovi la ricevuta n. '.e($this->numbers).' della tua donazione. Grazie ancora.</p><p>'.e($this->orgName).$contact.'</p>');
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        return array_map(fn (array $f): Attachment => Attachment::fromData(fn (): string => $f['pdf'], $f['filename'])->withMime('application/pdf'), $this->files);
    }
}
