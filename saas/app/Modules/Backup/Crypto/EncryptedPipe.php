<?php

declare(strict_types=1);

namespace App\Modules\Backup\Crypto;

use HashContext;
use Iterator;
use Throwable;

/**
 * Tubo dal dump in chiaro al file cifrato: legge un pezzo di dump alla volta (per esempio dall'uscita di pg_dump), lo
 * cifra e lo consegna a chi legge, senza scrivere niente su disco. Intanto calcola impronta e dimensione del cifrato.
 * Se la sorgente si interrompe con un errore il flusso finisce SENZA il blocco finale: il file parziale non si apre.
 */
final class EncryptedPipe
{
    private string $ready = '';

    private bool $finished = false;

    private bool $completed = false;

    private bool $primed = false;

    private int $size = 0;

    private ?Throwable $error = null;

    private readonly HashContext $hash;

    /** @param  Iterator<mixed, string>  $source  pezzi di dump in chiaro */
    public function __construct(private readonly Iterator $source, private readonly BackupEncryptor $encryptor)
    {
        $this->hash = hash_init('sha256');
    }

    public function read(int $length): string
    {
        while (strlen($this->ready) < $length && ! $this->finished) {
            $this->pull();
        }
        $piece = substr($this->ready, 0, $length);
        $this->ready = substr($this->ready, $length);
        hash_update($this->hash, $piece);
        $this->size += strlen($piece);

        return $piece;
    }

    public function atEnd(): bool
    {
        return $this->finished && $this->ready === '';
    }

    /** Vero se la sorgente è arrivata in fondo e il blocco finale è stato scritto. */
    public function completed(): bool
    {
        return $this->completed;
    }

    public function error(): ?Throwable
    {
        return $this->error;
    }

    public function size(): int
    {
        return $this->size;
    }

    /** Impronta di quanto consegnato finora: definitiva solo a flusso concluso. */
    public function sha256(): string
    {
        return hash_final(hash_copy($this->hash));
    }

    private function pull(): void
    {
        try {
            if (! $this->primed) {
                $this->source->rewind();
                $this->primed = true;
            }
            if ($this->source->valid()) {
                $this->ready .= $this->encryptor->write((string) $this->source->current());
                $this->source->next();

                return;
            }
            $this->ready .= $this->encryptor->finish();
            $this->completed = true;
        } catch (Throwable $e) {
            $this->error = $e;
            $this->ready = '';
        }
        $this->finished = true;
    }
}
