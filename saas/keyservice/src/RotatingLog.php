<?php

declare(strict_types=1);

namespace KeyService;

/**
 * Registro del servizio con un limite di dimensione: quando il file arriva al tetto passa a `.1`, `.2`, ... e se ne
 * tengono al massimo `$keep`. Le righe sono ripulite dai caratteri di controllo e tagliate. Non lancia mai errori: un
 * registro che non si scrive non deve fermare il servizio né mostrare percorsi.
 */
final readonly class RotatingLog
{
    private const MAX_LINE = 400;

    public function __construct(
        private string $file,
        private int $maxBytes = Config::LOG_MAX_BYTES,
        private int $keep = Config::LOG_KEEP,
    ) {}

    public function write(string $line): void
    {
        $clean = preg_replace('/[^\x20-\x7E]/', '?', $line) ?? '';
        $dir = dirname($this->file);
        if (! is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }

        $lock = @fopen($this->file.'.lock', 'c');
        if ($lock === false) {
            return;
        }

        try {
            flock($lock, LOCK_EX);
            $this->rotate();
            @file_put_contents($this->file, gmdate('c').' '.substr($clean, 0, self::MAX_LINE)."\n", FILE_APPEND);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function rotate(): void
    {
        clearstatcache(true, $this->file);
        $size = is_file($this->file) ? filesize($this->file) : 0;
        if ($size === false || $size < $this->maxBytes) {
            return;
        }

        if ($this->keep < 1) {
            @unlink($this->file);

            return;
        }

        @unlink($this->file.'.'.$this->keep);
        for ($i = $this->keep - 1; $i >= 1; $i--) {
            if (is_file($this->file.'.'.$i)) {
                @rename($this->file.'.'.$i, $this->file.'.'.($i + 1));
            }
        }
        @rename($this->file, $this->file.'.1');
    }
}
