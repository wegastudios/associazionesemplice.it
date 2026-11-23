<?php

declare(strict_types=1);

namespace App\Modules\Cifratura\Files;

use App\Modules\Cifratura\Contracts\DecryptionFailed;
use App\Modules\Cifratura\Contracts\EncryptedFiles;
use App\Modules\Cifratura\Contracts\StoredFile;
use App\Modules\Cifratura\Keys\KeyRing;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * File cifrati a blocchi da 64 KiB con sodium secretstream (XChaCha20-Poly1305): si cifrano e si leggono senza
 * caricarli interi in memoria, e un file troncato o con blocchi scambiati non si apre.
 *
 * Formato: 0x01 · versione della chiave (4 byte) · intestazione secretstream (24 byte) · blocchi. Ogni blocco porta
 * come dato autenticato associazione e percorso: un file spostato in un'altra associazione non si apre.
 */
final readonly class SecretStreamFiles implements EncryptedFiles
{
    private const FORMAT = "\x01";

    private const CHUNK = 65536;

    /**
     * Spazio occupato per associazione (H33): contatore in cache, aggiornato a ogni salvataggio e cancellazione, così il
     * controllo della quota non scorre tutti i file. Ogni notte saas:files:usage lo riallinea al disco; se scade o la
     * cache lo toglie, si riconta.
     */
    private const USAGE_KEY = 'org-files-bytes:';

    private const USAGE_HOURS = 36;

    public function __construct(
        private Filesystem $disk,
        private KeyRing $keys,
        private Cache $cache,
    ) {}

    public function store(string $orgId, string $sourcePath, string $originalName, string $mime): StoredFile
    {
        $in = fopen($sourcePath, 'rb') ?: throw new InvalidArgumentException('File non leggibile.');
        $tmp = tmpfile() ?: throw new RuntimeException('Impossibile creare un file temporaneo.');
        $path = $orgId.'/'.now()->format('Y').'/'.Str::uuid7()->toString();
        $key = $this->keys->current($orgId);
        $hash = hash_init('sha256');
        $size = 0;

        [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key->fileKey());
        if (! is_string($state) || ! is_string($header)) {
            throw new RuntimeException('Cifratura del file non avviata.');
        }
        fwrite($tmp, self::FORMAT.pack('N', $key->version).$header);

        $chunk = (string) fread($in, self::CHUNK);
        while (true) {
            $next = feof($in) ? '' : (string) fread($in, self::CHUNK);
            $last = $next === '' && feof($in);
            hash_update($hash, $chunk);
            $size += strlen($chunk);
            fwrite($tmp, sodium_crypto_secretstream_xchacha20poly1305_push(
                $state,
                $chunk,
                self::aad($orgId, $path),
                $last ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE,
            ));
            if ($last) {
                break;
            }
            $chunk = $next;
        }
        fclose($in);

        $written = (int) ftell($tmp);
        rewind($tmp);
        if (! $this->disk->writeStream($path, $tmp)) {
            throw new RuntimeException('File non salvato.');
        }
        fclose($tmp);
        $this->addUsage($orgId, $written);

        return new StoredFile($path, self::safeName($originalName), $mime, $size, hash_final($hash));
    }

    public function read(string $orgId, StoredFile $file): string
    {
        $out = '';
        $this->decrypt($orgId, $file, static function (string $chunk) use (&$out): void {
            $out .= $chunk;
        });

        return $out;
    }

    public function readToFile(string $orgId, StoredFile $file, string $targetPath): void
    {
        $out = fopen($targetPath, 'wb') ?: throw new RuntimeException('File di destinazione non scrivibile.');
        try {
            $this->decrypt($orgId, $file, static function (string $chunk) use ($out): void {
                fwrite($out, $chunk);
            });
        } finally {
            fclose($out);
        }
    }

    public function download(string $orgId, StoredFile $file): StreamedResponse
    {
        $this->assertOwned($orgId, $file);

        return new StreamedResponse(function () use ($orgId, $file): void {
            $this->decrypt($orgId, $file, static function (string $chunk): void {
                echo $chunk;
                flush();
            });
        }, 200, [
            // Sempre allegato e con il tipo dichiarato: il browser non deve interpretarlo come pagina.
            'Content-Type' => $file->mime,
            'Content-Length' => (string) $file->size,
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $file->originalName, Str::ascii($file->originalName)),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    public function delete(string $orgId, StoredFile $file): void
    {
        $this->assertOwned($orgId, $file);
        $bytes = $this->disk->exists($file->path) ? $this->disk->size($file->path) : 0;
        if ($this->disk->delete($file->path)) {
            $this->addUsage($orgId, -$bytes);
        }
    }

    public function usedBytes(string $orgId): int
    {
        // Redis restituisce i numeri come testo.
        $bytes = $this->cache->get(self::USAGE_KEY.$orgId);

        return is_numeric($bytes) ? (int) $bytes : $this->recountUsedBytes($orgId);
    }

    public function recountUsedBytes(string $orgId): int
    {
        $total = 0;
        foreach ($this->disk->allFiles($orgId) as $path) {
            $total += $this->disk->size($path);
        }
        $this->cache->put(self::USAGE_KEY.$orgId, $total, now()->addHours(self::USAGE_HOURS));

        return $total;
    }

    /** Aggiorna il contatore, se c'è: se manca, la prossima lettura riconta dal disco. */
    private function addUsage(string $orgId, int $bytes): void
    {
        if ($bytes !== 0 && $this->cache->has(self::USAGE_KEY.$orgId)) {
            $this->cache->increment(self::USAGE_KEY.$orgId, $bytes);
        }
    }

    /** @param  callable(string): void  $emit */
    private function decrypt(string $orgId, StoredFile $file, callable $emit): void
    {
        $this->assertOwned($orgId, $file);
        $in = $this->disk->readStream($file->path) ?? throw new DecryptionFailed('File inesistente.');

        $prefix = (string) fread($in, 5);
        $header = (string) fread($in, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
        if (strlen($prefix) !== 5 || $prefix[0] !== self::FORMAT || strlen($header) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES) {
            throw new DecryptionFailed('File cifrato non valido.');
        }
        /** @var array{1: int} $unpacked */
        $unpacked = unpack('N', substr($prefix, 1));
        $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $this->keys->version($orgId, $unpacked[1])->fileKey());

        $hash = hash_init('sha256');
        do {
            $block = (string) fread($in, self::CHUNK + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES);
            $result = $block === '' ? false : sodium_crypto_secretstream_xchacha20poly1305_pull($state, $block, self::aad($orgId, $file->path));
            if ($result === false) {
                throw new DecryptionFailed('File cifrato alterato o incompleto.');
            }
            [$chunk, $tag] = $result;
            if (! is_string($chunk)) {
                throw new DecryptionFailed('File cifrato non valido.');
            }
            hash_update($hash, $chunk);
            $emit($chunk);
        } while ($tag !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL);
        fclose($in);

        if (! hash_equals($file->sha256, hash_final($hash))) {
            throw new DecryptionFailed('Impronta del file diversa.');
        }
    }

    private function assertOwned(string $orgId, StoredFile $file): void
    {
        if (! str_starts_with($file->path, $orgId.'/') || str_contains($file->path, '..')) {
            throw new DecryptionFailed('Il file appartiene a un\'altra associazione.');
        }
    }

    private static function aad(string $orgId, string $path): string
    {
        return "as-file|{$orgId}|{$path}";
    }

    private static function safeName(string $name): string
    {
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F\/\\\\]+/', '_', basename($name)));

        return $name === '' ? 'file' : Str::limit($name, 200, '');
    }
}
