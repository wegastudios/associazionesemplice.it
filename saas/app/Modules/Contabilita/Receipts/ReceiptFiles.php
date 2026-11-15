<?php

declare(strict_types=1);

namespace App\Modules\Contabilita\Receipts;

use App\Modules\Audit\Contracts\AuditLog;
use App\Modules\Cifratura\Contracts\DecryptionFailed;
use App\Modules\Cifratura\Contracts\EncryptedFiles;
use App\Modules\Cifratura\Contracts\StoredFile;
use App\Modules\Contabilita\Contracts\LedgerError;
use App\Modules\Core\Contracts\OrgContext;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use League\Flysystem\UnableToReadFile;
use RuntimeException;

/**
 * Ricevute definitive: il PDF si crea una volta sola e si salva cifrato con la sua impronta SHA-256. Download ed email
 * servono sempre quel file. Se manca o è alterato: errore chiaro, mai rifatto di nascosto. Lo si rifà solo da
 * «Verifica delle ricevute», e solo se rigenerato dai dati attuali risulta identico all'originale (stessi byte).
 */
final readonly class ReceiptFiles
{
    public const OK = 'ok';

    public const UNFIXED = 'unfixed';

    public const MISSING = 'missing';

    public const ALTERED = 'altered';

    public function __construct(
        private DatabaseManager $db,
        private OrgContext $context,
        private ReceiptPdf $pdf,
        private EncryptedFiles $files,
        private AuditLog $audit,
    ) {}

    /** Fissa il PDF se non lo è già. Ritorna true se l'ha creato adesso. */
    public function fix(string $receiptId, string $source): bool
    {
        if ($this->row($receiptId) !== null) {
            return false;
        }
        $doc = $this->pdf->build($receiptId);
        $stored = $this->store($doc['pdf'], $doc['filename']);
        $this->connection()->table('app.receipt_files')->insertOrIgnore([
            'org_id' => $this->context->require()->id,
            'receipt_id' => $receiptId,
            'path' => $stored->path,
            'original_name' => $stored->originalName,
            'size_bytes' => $stored->size,
            'sha256' => $stored->sha256,
            'source' => $source,
        ]);
        $this->audit->record('receipt.fixed', 'app.receipts', $receiptId, ['number' => $doc['number'], 'source' => $source]);

        return true;
    }

    /**
     * Il documento da scaricare o spedire: sempre il file salvato, dopo il controllo dell'impronta.
     *
     * @return array{pdf: string, filename: string, number: string, email: string|null, holder: string}
     *
     * @throws LedgerError ricevuta annullata o inesistente
     * @throws ReceiptFileProblem file mancante o alterato
     */
    public function document(string $receiptId): array
    {
        $meta = $this->pdf->build($receiptId);
        if ($this->row($receiptId) === null) {
            // Mai fissata (emessa prima di questa funzione): la prima volta che serve diventa il suo file definitivo.
            $this->fix($receiptId, 'lotto');
        }
        $row = $this->row($receiptId) ?? throw new RuntimeException('Ricevuta non fissata.');
        $name = is_string($row->original_name ?? null) ? $row->original_name : $meta['filename'];

        return ['pdf' => $this->read($row, $meta['number']), 'filename' => $name, 'number' => $meta['number'], 'email' => $meta['email'], 'holder' => $meta['holder']];
    }

    /** @return self::OK|self::UNFIXED|self::MISSING|self::ALTERED */
    public function status(string $receiptId): string
    {
        $row = $this->row($receiptId);
        if ($row === null) {
            return self::UNFIXED;
        }
        try {
            $this->files->read($this->context->require()->id, self::stored($row));
        } catch (UnableToReadFile) {
            return self::MISSING;
        } catch (DecryptionFailed $e) {
            return $e->getMessage() === 'File inesistente.' ? self::MISSING : self::ALTERED;
        }

        return self::OK;
    }

    /**
     * Rifà il PDF dai dati attuali, solo se è identico a quello emesso (stessa impronta): altrimenti i dati sono
     * cambiati dall'emissione e non si rifà. Il file diverso resta dov'è, messo da parte.
     *
     * @throws ReceiptFileProblem
     */
    public function rebuild(string $receiptId): void
    {
        $row = $this->row($receiptId) ?? throw new ReceiptFileProblem('Questa ricevuta non ha ancora un PDF fissato.', ReceiptFileProblem::MISSING);
        $doc = $this->pdf->build($receiptId);
        $sha = is_string($row->sha256 ?? null) ? $row->sha256 : '';
        if (! hash_equals($sha, hash('sha256', $doc['pdf']))) {
            throw new ReceiptFileProblem('I dati della ricevuta sono cambiati dall\'emissione: il PDF non si può rifare uguale. Ripristinalo da una copia di sicurezza.', ReceiptFileProblem::CHANGED);
        }
        $stored = $this->store($doc['pdf'], is_string($row->original_name ?? null) ? $row->original_name : $doc['filename']);
        $this->connection()->table('app.receipt_files')->where('receipt_id', $receiptId)->update([
            'path' => $stored->path, 'size_bytes' => $stored->size, 'sha256' => $stored->sha256, 'source' => 'rifatta', 'fixed_at' => now(),
        ]);
        $this->audit->record('receipt.rebuilt', 'app.receipts', $receiptId, ['previous_path' => is_string($row->path ?? null) ? $row->path : '', 'sha256' => $stored->sha256]);
    }

    /**
     * Fissa le prossime ricevute non ancora fissate (non annullate), al massimo $limit.
     *
     * @return array{fixed: int, left: int}
     */
    public function fixNext(int $limit = 100): array
    {
        $ids = $this->connection()->table('app.receipts as r')->leftJoin('app.receipt_files as f', 'f.receipt_id', '=', 'r.id')
            ->whereNull('f.receipt_id')->whereNull('r.voided_at')->orderBy('r.year')->orderBy('r.number')->limit($limit)->pluck('r.id')->all();
        $fixed = 0;
        foreach ($ids as $id) {
            if (is_string($id)) {
                try {
                    $fixed += $this->fix($id, 'lotto') ? 1 : 0;
                } catch (LedgerError) {
                    // Ricevuta senza voci o annullata nel frattempo: resta da fissare.
                }
            }
        }

        return ['fixed' => $fixed, 'left' => $this->unfixedCount()];
    }

    public function unfixedCount(): int
    {
        return (int) $this->connection()->table('app.receipts as r')->leftJoin('app.receipt_files as f', 'f.receipt_id', '=', 'r.id')
            ->whereNull('f.receipt_id')->whereNull('r.voided_at')->count();
    }

    /**
     * Situazione di un esercizio, per «Verifica delle ricevute».
     *
     * @return list<array{id: string, number: string, issued_on: string, status: string}>
     */
    public function overview(int $year): array
    {
        $out = [];
        $rows = $this->connection()->table('app.receipts')->where('year', $year)->orderBy('number')->get(['id', 'number', 'issued_on', 'voided_at']);
        foreach ($rows as $r) {
            $id = is_string($r->id ?? null) ? $r->id : '';
            $out[] = [
                'id' => $id,
                'number' => (is_scalar($r->number ?? null) ? (string) $r->number : '').'/'.$year,
                'issued_on' => is_scalar($r->issued_on ?? null) ? (string) $r->issued_on : '',
                'status' => ($r->voided_at ?? null) !== null ? 'voided' : $this->status($id),
            ];
        }

        return $out;
    }

    private function read(object $row, string $number): string
    {
        try {
            return $this->files->read($this->context->require()->id, self::stored($row));
        } catch (UnableToReadFile) {
            throw new ReceiptFileProblem(self::missingText($number), ReceiptFileProblem::MISSING);
        } catch (DecryptionFailed $e) {
            if ($e->getMessage() === 'File inesistente.') {
                throw new ReceiptFileProblem(self::missingText($number), ReceiptFileProblem::MISSING);
            }
            throw new ReceiptFileProblem('Il PDF della ricevuta n. '.$number.' risulta diverso da quando è stato emesso: per sicurezza non viene scaricato. Un amministratore può controllarlo da «Verifica delle ricevute».', ReceiptFileProblem::ALTERED);
        }
    }

    private static function missingText(string $number): string
    {
        return 'Il PDF della ricevuta n. '.$number.' non si trova più sul sito. Non viene rifatto in automatico: un amministratore può ripristinarlo da una copia di sicurezza oppure, da «Verifica delle ricevute», rigenerarlo dai dati salvati.';
    }

    private function row(string $receiptId): ?object
    {
        return Str::isUuid($receiptId) ? $this->connection()->table('app.receipt_files')->where('receipt_id', $receiptId)->first() : null;
    }

    private static function stored(object $row): StoredFile
    {
        return new StoredFile(
            is_string($row->path ?? null) ? $row->path : '',
            is_string($row->original_name ?? null) ? $row->original_name : 'ricevuta.pdf',
            'application/pdf',
            is_numeric($row->size_bytes ?? null) ? (int) $row->size_bytes : 0,
            is_string($row->sha256 ?? null) ? $row->sha256 : '',
        );
    }

    private function store(string $pdf, string $name): StoredFile
    {
        $dir = storage_path('app/tmp');
        if (! is_dir($dir) && ! mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new RuntimeException('Cartella temporanea non disponibile.');
        }
        $tmp = $dir.'/rcp-'.bin2hex(random_bytes(16));
        if (file_put_contents($tmp, $pdf) === false) {
            throw new RuntimeException('File temporaneo non disponibile.');
        }
        try {
            return $this->files->store($this->context->require()->id, $tmp, $name, 'application/pdf');
        } finally {
            @unlink($tmp);
        }
    }

    private function connection(): ConnectionInterface
    {
        return $this->db->connection($this->context->require()->connection);
    }
}
