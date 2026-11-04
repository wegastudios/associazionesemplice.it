<?php

declare(strict_types=1);

namespace App\Modules\Contabilita\Receipts;

use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** La ricevuta in PDF per email a chi ha pagato. */
final class ReceiptMail extends Mailable
{
    public function __construct(
        public readonly string $orgName,
        public readonly string $number,
        public readonly string $holder,
        private readonly string $pdf,
        private readonly string $filename,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Ricevuta n. '.$this->number.' · '.$this->orgName);
    }

    public function content(): Content
    {
        return new Content(htmlString: '<p>Ciao '.e($this->holder).',</p><p>in allegato la ricevuta n. '.e($this->number).'.</p><p>A presto,<br>'.e($this->orgName).'</p>');
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        return [Attachment::fromData(fn (): string => $this->pdf, $this->filename)->withMime('application/pdf')];
    }
}
