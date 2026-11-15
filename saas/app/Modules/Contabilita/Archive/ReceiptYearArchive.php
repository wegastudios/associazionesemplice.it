<?php

declare(strict_types=1);

namespace App\Modules\Contabilita\Archive;

use App\Modules\Audit\Contracts\AuditLog;
use App\Modules\Cifratura\Contracts\DecryptionFailed;
use App\Modules\Cifratura\Contracts\EncryptedFiles;
use App\Modules\Cifratura\Contracts\StoredFile;
use App\Modules\Contabilita\Contracts\LedgerError;
use App\Modules\Contabilita\Receipts\ReceiptFiles;
use App\Modules\Core\Contracts\ArchiveError;
use App\Modules\Core\Contracts\OrgContext;
use App\Modules\Core\Contracts\YearArchives;
use App\Modules\Core\Export\FullExport;
use Illuminate\Contracts\Auth\Factory as Auth;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use League\Flysystem\UnableToReadFile;
use Throwable;
use ZipArchive;

/**
 * Archivio delle ricevute di un anno fiscale chiuso: i PDF (già cifrati uno a uno) si raccolgono in un solo file ZIP
 * cifrato con la chiave dell'associazione, con un indice. Si controlla l'impronta di ogni file, e solo dopo si tolgono
 * i file singoli. Se qualcosa non torna prima di quel punto non cambia niente. «Rimetti i file» fa il percorso inverso.
 */
final readonly class ReceiptYearArchive implements YearArchives
{
    private const CHECK_FAILED = 'L\'archivio non si apre o le impronte non coincidono: nessun file è stato tolto.';

    public function __construct(
        private DatabaseManager $db,
        private OrgContext $context,
        private EncryptedFiles $files,
        private ReceiptFiles $receipts,
        private ArchivedReceipts $archived,
        private AuditLog $audit,
        private Auth $auth,
    ) {}

    public function years(): array
    {
        $closed = $this->connection()->table('app.fiscal_years')->where('status', 'closed')->orderByDesc('year')->pluck('year')->map(static fn (mixed $y): int => is_numeric($y) ? (int) $y : 0)->all();
        $last = $closed === [] ? 0 : max($closed);
        $archives = [];
        foreach ($this->connection()->table('app.year_archives')->get(['fiscal_year', 'archived_at']) as $a) {
            $archives[is_numeric($a->fiscal_year ?? null) ? (int) $a->fiscal_year : 0] = is_string($a->archived_at ?? null) ? substr($a->archived_at, 0, 10) : '';
        }
        $out = [];
        foreach ($closed as $year) {
            $out[] = [
                'year' => $year,
                'files' => (int) $this->connection()->table('app.receipts')->where('year', $year)->whereNull('voided_at')->count(),
                'state' => isset($archives[$year]) ? 'archived' : ($year < $last ? 'ready' : 'not_yet'),
                'archived_on' => $archives[$year] ?? null,
            ];
        }

        return $out;
    }

    public function archive(int $year): int
    {
        $info = null;
        foreach ($this->years() as $y) {
            if ($y['year'] === $year) {
                $info = $y;
            }
        }
        if ($info === null || $info['state'] === 'not_yet') {
            throw new ArchiveError('L\'anno fiscale '.$year.' non si può archiviare: è aperto o è l\'ultimo anno chiuso.');
        }
        if ($info['state'] === 'archived') {
            throw new ArchiveError('L\'anno fiscale '.$year.' è già archiviato.');
        }
        if ($info['files'] > FullExport::MAX_HEAVY_FILES) {
            throw new ArchiveError('L\'anno fiscale '.$year.' ha troppe ricevute per un\'unica archiviazione (oltre '.number_format(FullExport::MAX_HEAVY_FILES, 0, ',', '.').'): non è stato archiviato e non è cambiato nulla. Scrivi all\'assistenza di Associazione Semplice.');
        }
        @set_time_limit(900);
        $orgId = $this->context->require()->id;

        // Le ricevute dell'anno senza PDF fissato si fissano ora (come al primo scarico).
        foreach ($this->connection()->table('app.receipts as r')->leftJoin('app.receipt_files as f', 'f.receipt_id', '=', 'r.id')
            ->where('r.year', $year)->whereNull('f.receipt_id')->whereNull('r.voided_at')->pluck('r.id') as $id) {
            try {
                $this->receipts->fix(is_string($id) ? $id : '', 'lotto');
            } catch (LedgerError) {
                // Ricevuta senza voci: resta fuori, la segnala «Verifica delle ricevute».
            }
        }
        $rows = $this->connection()->table('app.receipt_files as f')->join('app.receipts as r', 'r.id', '=', 'f.receipt_id')
            ->where('r.year', $year)->whereNull('f.archived_year')->orderBy('r.number')
            ->get(['f.receipt_id', 'f.path', 'f.original_name', 'f.size_bytes', 'f.sha256', 'r.number'])->all();

        $work = ArchivedReceipts::tmpPath();
        $zipPath = $work.'.zip';
        $checkPath = $work.'.check';
        $stored = null;
        try {
            $this->collect($rows, $year, $zipPath, $work);
            $stored = $this->files->store($orgId, $zipPath, 'archivio-ricevute-'.$year.'.zip', 'application/zip');
            $this->verify($stored, $rows, $checkPath);

            $user = $this->auth->guard()->id();
            $this->connection()->transaction(function () use ($year, $stored, $rows, $user): void {
                $this->connection()->table('app.year_archives')->insert([
                    'org_id' => $this->context->require()->id, 'fiscal_year' => $year, 'path' => $stored->path, 'original_name' => $stored->originalName,
                    'size_bytes' => $stored->size, 'sha256' => $stored->sha256, 'file_count' => count($rows), 'archived_by' => is_string($user) ? $user : null,
                ]);
                $this->connection()->table('app.receipt_files')->whereIn('receipt_id', array_map(static fn (object $r): string => is_string($r->receipt_id ?? null) ? $r->receipt_id : '', $rows))->update(['archived_year' => $year]);
            });
        } catch (Throwable $e) {
            // Prima di questo punto non è stato tolto niente: si toglie solo l'archivio appena creato.
            if ($stored !== null && ! $this->archiveRowExists($year)) {
                $this->safeDelete($stored);
            }
            throw $e instanceof ArchiveError ? $e : new ArchiveError(self::CHECK_FAILED, 0, $e);
        } finally {
            @unlink($zipPath);
            @unlink($checkPath);
            @unlink($work);
        }

        // L'archivio è controllato e registrato: ora si tolgono i file singoli.
        foreach ($rows as $r) {
            $this->safeDelete(new StoredFile(is_string($r->path ?? null) ? $r->path : '', 'ricevuta.pdf', 'application/pdf', 0, ''));
        }
        $this->archived->flush();
        $this->audit->record('archive.created', 'app.year_archives', null, ['year' => $year, 'receipts' => count($rows), 'sha256' => $stored->sha256]);

        return count($rows);
    }

    public function restore(int $year): int
    {
        $arc = $this->connection()->table('app.year_archives')->where('fiscal_year', $year)->first();
        if ($arc === null) {
            throw new ArchiveError('L\'anno fiscale '.$year.' non è archiviato.');
        }
        @set_time_limit(900);
        $orgId = $this->context->require()->id;
        $archive = new StoredFile(
            is_string($arc->path ?? null) ? $arc->path : '', 'archivio.zip', 'application/zip',
            is_numeric($arc->size_bytes ?? null) ? (int) $arc->size_bytes : 0, is_string($arc->sha256 ?? null) ? $arc->sha256 : '',
        );
        $rows = $this->connection()->table('app.receipt_files')->where('archived_year', $year)->get(['receipt_id', 'original_name', 'size_bytes', 'sha256'])->all();

        $work = ArchivedReceipts::tmpPath();
        $zipPath = $work.'.zip';
        $new = [];
        try {
            try {
                $this->files->readToFile($orgId, $archive, $zipPath);
            } catch (UnableToReadFile|DecryptionFailed $e) {
                throw new ArchiveError('L\'archivio non si apre o le impronte non coincidono: nessun file è stato rimesso.', 0, $e);
            }
            $zip = new ZipArchive;
            if ($zip->open($zipPath) !== true) {
                throw new ArchiveError('L\'archivio non si apre o le impronte non coincidono: nessun file è stato rimesso.');
            }
            foreach ($rows as $r) {
                $id = is_string($r->receipt_id ?? null) ? $r->receipt_id : '';
                $pdf = $zip->getFromName($id.'.pdf');
                if (! is_string($pdf) || ! hash_equals(is_string($r->sha256 ?? null) ? $r->sha256 : '', hash('sha256', $pdf))) {
                    throw new ArchiveError('L\'archivio non si apre o le impronte non coincidono: nessun file è stato rimesso.');
                }
                file_put_contents($work, $pdf);
                $new[$id] = $this->files->store($orgId, $work, is_string($r->original_name ?? null) ? $r->original_name : 'ricevuta.pdf', 'application/pdf');
            }
            $zip->close();

            $this->connection()->transaction(function () use ($new, $year): void {
                foreach ($new as $id => $s) {
                    $this->connection()->table('app.receipt_files')->where('receipt_id', $id)->update(['path' => $s->path, 'size_bytes' => $s->size, 'archived_year' => null]);
                }
                $this->connection()->table('app.year_archives')->where('fiscal_year', $year)->delete();
            });
        } catch (Throwable $e) {
            foreach ($new as $s) {
                $this->safeDelete($s);
            }
            throw $e instanceof ArchiveError ? $e : new ArchiveError('L\'archivio non si apre o le impronte non coincidono: nessun file è stato rimesso.', 0, $e);
        } finally {
            @unlink($zipPath);
            @unlink($work);
        }
        $this->archived->flush();
        $this->safeDelete($archive);
        $this->audit->record('archive.restored', 'app.year_archives', null, ['year' => $year, 'receipts' => count($new)]);

        return count($new);
    }

    /**
     * Raccoglie i PDF singoli in uno ZIP (senza compressione: sono già PDF), con l'indice.
     *
     * @param  list<object>  $rows
     */
    private function collect(array $rows, int $year, string $zipPath, string $tmp): void
    {
        $orgId = $this->context->require()->id;
        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            throw new ArchiveError(self::CHECK_FAILED);
        }
        $index = [];
        $files = [];
        foreach ($rows as $r) {
            $id = is_string($r->receipt_id ?? null) ? $r->receipt_id : '';
            $number = (is_scalar($r->number ?? null) ? (string) $r->number : '').'/'.$year;
            $file = $tmp.'-'.count($files);
            try {
                $this->files->readToFile($orgId, new StoredFile(
                    is_string($r->path ?? null) ? $r->path : '', is_string($r->original_name ?? null) ? $r->original_name : 'ricevuta.pdf', 'application/pdf',
                    is_numeric($r->size_bytes ?? null) ? (int) $r->size_bytes : 0, is_string($r->sha256 ?? null) ? $r->sha256 : '',
                ), $file);
            } catch (UnableToReadFile|DecryptionFailed) {
                @unlink($file);
                foreach ($files as $f) {
                    @unlink($f);
                }
                throw new ArchiveError('Non si può archiviare l\'anno fiscale '.$year.': la ricevuta n. '.$number.' non ha un PDF valido. Controllala in Altro › Verifica delle ricevute.');
            }
            $files[] = $file;
            $zip->addFile($file, $id.'.pdf');
            $zip->setCompressionName($id.'.pdf', ZipArchive::CM_STORE);
            $index[] = ['receipt_id' => $id, 'number' => $number, 'file' => $r->original_name ?? '', 'sha256' => $r->sha256 ?? ''];
        }
        $zip->addFromString('indice.json', json_encode(['fiscal_year' => $year, 'receipts' => $index], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}');
        $zip->close();
        foreach ($files as $f) {
            @unlink($f);
        }
    }

    /**
     * Rilegge l'archivio appena fatto e controlla ogni file contro l'impronta registrata.
     *
     * @param  list<object>  $rows
     */
    private function verify(StoredFile $stored, array $rows, string $checkPath): void
    {
        try {
            $this->files->readToFile($this->context->require()->id, $stored, $checkPath);
        } catch (UnableToReadFile|DecryptionFailed $e) {
            throw new ArchiveError(self::CHECK_FAILED, 0, $e);
        }
        $zip = new ZipArchive;
        if ($zip->open($checkPath) !== true || $zip->numFiles !== count($rows) + 1) {
            throw new ArchiveError(self::CHECK_FAILED);
        }
        foreach ($rows as $r) {
            $pdf = $zip->getFromName((is_string($r->receipt_id ?? null) ? $r->receipt_id : '').'.pdf');
            if (! is_string($pdf) || ! hash_equals(is_string($r->sha256 ?? null) ? $r->sha256 : '', hash('sha256', $pdf))) {
                throw new ArchiveError(self::CHECK_FAILED);
            }
        }
        $zip->close();
    }

    private function archiveRowExists(int $year): bool
    {
        return $this->connection()->table('app.year_archives')->where('fiscal_year', $year)->exists();
    }

    private function safeDelete(StoredFile $file): void
    {
        try {
            $this->files->delete($this->context->require()->id, $file);
        } catch (Throwable) {
            // File già assente: niente da togliere.
        }
    }

    private function connection(): ConnectionInterface
    {
        return $this->db->connection($this->context->require()->connection);
    }
}
