<?php

declare(strict_types=1);

namespace App\Modules\Contabilita\Archive;

use App\Modules\Cifratura\Contracts\DecryptionFailed;
use App\Modules\Cifratura\Contracts\EncryptedFiles;
use App\Modules\Cifratura\Contracts\StoredFile;
use App\Modules\Core\Contracts\OrgContext;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use League\Flysystem\UnableToReadFile;
use RuntimeException;
use ZipArchive;

/**
 * Lettura delle ricevute che stanno nell'archivio di un anno. L'archivio si decifra una volta su disco e resta aperto
 * finché dura la richiesta: una copia di sicurezza con migliaia di ricevute archiviate non lo decifra ogni volta.
 */
final class ArchivedReceipts
{
    /** @var array<string, array{zip: ZipArchive, path: string}> */
    private array $open = [];

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly OrgContext $context,
        private readonly EncryptedFiles $files,
    ) {}

    public function __destruct()
    {
        $this->flush();
    }

    /**
     * Il PDF di una ricevuta archiviata, con l'impronta controllata.
     *
     * @throws RuntimeException l'archivio non si apre o la ricevuta non c'è o è diversa
     */
    public function read(string $receiptId, int $year, string $sha256): string
    {
        $zip = $this->zip($year);
        $pdf = $zip->getFromName($receiptId.'.pdf');
        if (! is_string($pdf) || ! hash_equals($sha256, hash('sha256', $pdf))) {
            throw new RuntimeException('Ricevuta mancante o diversa nell\'archivio.');
        }

        return $pdf;
    }

    /** Chiude e cancella gli archivi aperti su disco. */
    public function flush(): void
    {
        foreach ($this->open as $o) {
            @$o['zip']->close();
            @unlink($o['path']);
        }
        $this->open = [];
    }

    /** @throws RuntimeException */
    private function zip(int $year): ZipArchive
    {
        $key = $this->context->require()->id.'|'.$year;
        if (isset($this->open[$key])) {
            return $this->open[$key]['zip'];
        }
        $row = $this->connection()->table('app.year_archives')->where('fiscal_year', $year)->first();
        if ($row === null) {
            throw new RuntimeException('Archivio inesistente.');
        }
        $stored = new StoredFile(
            is_string($row->path ?? null) ? $row->path : '',
            is_string($row->original_name ?? null) ? $row->original_name : 'archivio.zip',
            'application/zip',
            is_numeric($row->size_bytes ?? null) ? (int) $row->size_bytes : 0,
            is_string($row->sha256 ?? null) ? $row->sha256 : '',
        );
        $path = self::tmpPath();
        try {
            $this->files->readToFile($this->context->require()->id, $stored, $path);
        } catch (UnableToReadFile|DecryptionFailed $e) {
            @unlink($path);
            throw new RuntimeException('Archivio non leggibile.', 0, $e);
        }
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            @unlink($path);
            throw new RuntimeException('Archivio non valido.');
        }
        $this->open[$key] = ['zip' => $zip, 'path' => $path];

        return $zip;
    }

    public static function tmpPath(): string
    {
        $dir = storage_path('app/tmp');
        if (! is_dir($dir) && ! mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new RuntimeException('Cartella temporanea non disponibile.');
        }

        return $dir.'/arc-'.bin2hex(random_bytes(16));
    }

    private function connection(): ConnectionInterface
    {
        return $this->db->connection($this->context->require()->connection);
    }
}
