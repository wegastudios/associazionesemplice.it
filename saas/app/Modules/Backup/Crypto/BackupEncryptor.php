<?php

declare(strict_types=1);

namespace App\Modules\Backup\Crypto;

use InvalidArgumentException;
use RuntimeException;

/**
 * Cifratura a flusso dei dump, a pezzi: lo stesso formato 2 di {@see BackupCipher}, ma si alimenta man mano che i dati
 * arrivano (per esempio dall'uscita di pg_dump) senza scrivere file in chiaro. Un blocco resta sempre in attesa, perché
 * l'ultimo porta l'etichetta «finale»: un file troncato non si apre.
 */
final class BackupEncryptor
{
    public const CHUNK = 65536;

    private const MAGIC = "ASBK\x02";

    private const MAX_META = 1024;

    private string $state;

    private string $prefix;

    private string $ad;

    private string $buffer = '';

    private ?string $pending = null;

    private bool $started = false;

    private bool $finished = false;

    /** @param  array<string, string>  $meta  database, ora e ambiente del dump: entrano nel dato autenticato */
    public function __construct(string $publicKey, array $meta = [])
    {
        if (strlen($publicKey) !== SODIUM_CRYPTO_BOX_PUBLICKEYBYTES) {
            throw new InvalidArgumentException('Chiave pubblica dei backup non valida.');
        }
        ksort($meta);
        $json = json_encode($meta, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (strlen($json) > self::MAX_META) {
            throw new InvalidArgumentException('Intestazione del backup troppo lunga.');
        }
        $fileKey = sodium_crypto_secretstream_xchacha20poly1305_keygen();
        [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($fileKey);
        if (! is_string($state) || ! is_string($header)) {
            throw new RuntimeException('Cifratura del backup non avviata.');
        }

        $this->state = $state;
        $this->prefix = self::MAGIC.pack('n', strlen($json)).$json.sodium_crypto_box_seal($fileKey, $publicKey).$header;
        $this->ad = self::MAGIC.$json;
    }

    /** Dati in chiaro in ingresso; restituisce i byte cifrati pronti (anche vuoti). Il primo giro porta l'intestazione. */
    public function write(string $plain): string
    {
        if ($this->finished) {
            throw new RuntimeException('Cifratura del backup già conclusa.');
        }
        $out = $this->begin();
        $this->buffer .= $plain;
        while (strlen($this->buffer) >= self::CHUNK) {
            $block = substr($this->buffer, 0, self::CHUNK);
            $this->buffer = substr($this->buffer, self::CHUNK);
            $out .= $this->flushPending();
            $this->pending = $block;
        }

        return $out;
    }

    /** Fine dei dati: scrive il blocco finale. */
    public function finish(): string
    {
        if ($this->finished) {
            throw new RuntimeException('Cifratura del backup già conclusa.');
        }
        $out = $this->begin();
        if ($this->buffer !== '') {
            $out .= $this->flushPending();
            $last = $this->buffer;
        } else {
            $last = $this->pending ?? '';
        }
        $this->pending = null;
        $this->buffer = '';
        $this->finished = true;

        return $out.sodium_crypto_secretstream_xchacha20poly1305_push(
            $this->state,
            $last,
            $this->ad,
            SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL,
        );
    }

    private function begin(): string
    {
        if ($this->started) {
            return '';
        }
        $this->started = true;

        return $this->prefix;
    }

    private function flushPending(): string
    {
        if ($this->pending === null) {
            return '';
        }
        $out = sodium_crypto_secretstream_xchacha20poly1305_push(
            $this->state,
            $this->pending,
            $this->ad,
            SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE,
        );
        $this->pending = null;

        return $out;
    }
}
