<?php

declare(strict_types=1);

namespace App\Modules\Backup\Crypto;

use RuntimeException;

/**
 * Espone un {@see EncryptedPipe} come flusso PHP leggibile, così lo spazio dei backup (Flysystem) lo riceve con
 * `writeStream` mentre pg_dump sta ancora scrivendo. Non si può riavvolgere.
 */
final class EncryptedPipeStream
{
    private const PROTOCOL = 'asbk-pipe';

    /** @var resource|null */
    public $context;

    private ?EncryptedPipe $pipe = null;

    /** @return resource */
    public static function open(EncryptedPipe $pipe)
    {
        if (! in_array(self::PROTOCOL, stream_get_wrappers(), true)) {
            stream_wrapper_register(self::PROTOCOL, self::class);
        }
        $handle = fopen(self::PROTOCOL.'://dump', 'rb', false, stream_context_create([self::PROTOCOL => ['pipe' => $pipe]]));

        return $handle ?: throw new RuntimeException('Flusso del backup non disponibile.');
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $options = is_resource($this->context) ? (stream_context_get_options($this->context)[self::PROTOCOL] ?? null) : null;
        $pipe = is_array($options) ? ($options['pipe'] ?? null) : null;
        $this->pipe = $pipe instanceof EncryptedPipe ? $pipe : null;

        return $this->pipe instanceof EncryptedPipe;
    }

    public function stream_read(int $count): string|false
    {
        return $this->pipe instanceof EncryptedPipe ? $this->pipe->read($count) : false;
    }

    public function stream_eof(): bool
    {
        return ! $this->pipe instanceof EncryptedPipe || $this->pipe->atEnd();
    }

    /** Dimensione sconosciuta: il flusso si genera mentre si legge. */
    public function stream_stat(): false
    {
        return false;
    }

    public function stream_set_option(int $option, int $arg1, ?int $arg2): bool
    {
        return false;
    }

    public function stream_close(): void
    {
        $this->pipe = null;
    }
}
