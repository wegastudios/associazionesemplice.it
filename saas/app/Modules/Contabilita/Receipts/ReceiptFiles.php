<?php

declare(strict_types=1);

namespace App\Modules\Contabilita\Receipts;

use App\Modules\Audit\Contracts\AuditLog;
use App\Modules\Cifratura\Contracts\Bytea;
use App\Modules\Cifratura\Contracts\DecryptionFailed;
use App\Modules\Cifratura\Contracts\EncryptedFiles;
use App\Modules\Cifratura\Contracts\FieldCipher;
use App\Modules\Cifratura\Contracts\StoredFile;
use App\Modules\Contabilita\Archive\ArchivedReceipts;
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
 * «Verifica delle ricevute»: dai dati fotografati (cifrati) all'emissione; per le ricevute senza fotografia solo se
 * rigenerato dai dati attuali risulta identico all'originale (stessi byte).
 */
final readonly class ReceiptFiles
{
    private const FIELD = 'receipt_files.snapshot';

    public const OK = 'ok';

    public const UNFIXED = 'unfixed';

    public const MISSING = 'missing';

    public const ALTERED = 'altered';

    public const ARCHIVED = 'archived';

    public function __construct(
        private DatabaseManager $db,
        private OrgContext $context,
        private ReceiptPdf $pdf,
        private EncryptedFiles $files,
        private AuditLog $audit,
        private FieldCipher $cipher,
        private ArchivedReceipts $archived,
    ) {}

    /** Fissa il PDF se non lo è già. Ritorna true se l'ha creato adesso. */
    public function fix(string $receiptId, string $source): bool
    {
        if ($this->row($receiptId) !== null) {
            return false;
        }
        $snapshot = $this->pdf->snapshot($receiptId);
        $stored = $this->store($this->pdf->render($snapshot), is_string($snapshot['filename'] ?? null) ? $snapshot['filename'] : 'ricevuta.pdf');
        $orgId = $this->context->require()->id;
        $this->connection()->table('app.receipt_files')->insertOrIgnore([
            'org_id' => $orgId,
            'receipt_id' => $receiptId,
            'path' => $stored->path,
            'original_name' => $stored->originalName,
            'size_bytes' => $stored->size,
            'sha256' => $stored->sha256,
            'source' => $source,
            'snapshot' => Bytea::encode($this->cipher->encrypt($orgId, self::FIELD, json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE))),
        ]);
        $this->audit->record('receipt.fixed', 'app.receipts', $receiptId, ['number' => is_string($snapshot['number'] ?? null) ? $snapshot['number'] : '', 'source' => $source]);

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
        if (($row->archived_year ?? null) !== null) {
            return self::ARCHIVED;
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
     * Rifà il PDF dai dati fotografati all'emissione (senza fotografia: dai dati attuali, solo se identico). Ritorna se
     * il file nuovo è identico a quello emesso; l'impronta vecchia e la nuova restano nel registro azioni.
     *
     * @throws ReceiptFileProblem
     */
    public function rebuild(string $receiptId): bool
    {
        $row = $this->row($receiptId) ?? throw new ReceiptFileProblem('Questa ricevuta non ha ancora un PDF fissato.', ReceiptFileProblem::MISSING);
        if (($row->archived_year ?? null) !== null) {
            throw new ReceiptFileProblem('Questa ricevuta è nell\'archivio dell\'anno fiscale '.(is_numeric($row->archived_year) ? (int) $row->archived_year : '').': per rifarla rimetti prima i file di quell\'anno da «Copie di sicurezza».', ReceiptFileProblem::CHANGED);
        }
        $snapshot = $this->snapshotOf($row);
        if ($snapshot !== null) {
            // Dai dati fotografati all'emissione: il PDF si rifà uguale anche se socio ed ente sono cambiati.
            $pdf = $this->pdf->render($snapshot);
        } else {
            // Ricevuta fissata prima della fotografia: si rifà solo se i dati di oggi danno lo stesso file.
            $pdf = $this->pdf->build($receiptId)['pdf'];
            $sha = is_string($row->sha256 ?? null) ? $row->sha256 : '';
            if (! hash_equals($sha, hash('sha256', $pdf))) {
                throw new ReceiptFileProblem('Il PDF rifatto non risulta identico a quello emesso (i dati o i testi sono cambiati): non è stato sostituito. Scrivi all\'assistenza di Associazione Semplice.', ReceiptFileProblem::CHANGED);
            }
        }
        $stored = $this->store($pdf, is_string($row->original_name ?? null) ? $row->original_name : 'ricevuta.pdf');
        $this->connection()->table('app.receipt_files')->where('receipt_id', $receiptId)->update([
            'path' => $stored->path, 'size_bytes' => $stored->size, 'sha256' => $stored->sha256, 'source' => 'rifatta', 'fixed_at' => now(),
        ]);
        $previous = is_string($row->sha256 ?? null) ? $row->sha256 : '';
        $identical = hash_equals($previous, $stored->sha256);
        $this->audit->record('receipt.rebuilt', 'app.receipts', $receiptId, [
            'previous_path' => is_string($row->path ?? null) ? $row->path : '',
            'previous_sha256' => $previous,
            'sha256' => $stored->sha256,
            'identical' => $identical,
            'from_snapshot' => $snapshot !== null,
        ]);

        return $identical;
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
        if (($row->archived_year ?? null) !== null) {
            $year = is_numeric($row->archived_year) ? (int) $row->archived_year : 0;
            try {
                return $this->archived->read(is_string($row->receipt_id ?? null) ? $row->receipt_id : '', $year, is_string($row->sha256 ?? null) ? $row->sha256 : '');
            } catch (RuntimeException) {
                throw new ReceiptFileProblem('Il PDF della ricevuta n. '.$number.' è nell\'archivio dell\'anno fiscale '.$year.', ma l\'archivio non si apre o risulta diverso da quando è stato fatto. Scrivi all\'assistenza di Associazione Semplice.', ReceiptFileProblem::ALTERED);
            }
        }
        try {
            return $this->files->read($this->context->require()->id, self::stored($row));
        } catch (UnableToReadFile) {
            throw new ReceiptFileProblem(self::missingText($number), ReceiptFileProblem::MISSING);
        } catch (DecryptionFailed $e) {
            if ($e->getMessage() === 'File inesistente.') {
                throw new ReceiptFileProblem(self::missingText($number), ReceiptFileProblem::MISSING);
            }
            throw new ReceiptFileProblem('Il PDF della ricevuta n. '.$number.' risulta diverso da quando è stato emesso: per sicurezza non viene scaricato. Un amministratore può controllarlo da «Verifica delle ricevute»; se non si riesce a rifarlo, scrivi all\'assistenza di Associazione Semplice.', ReceiptFileProblem::ALTERED);
        }
    }

    private static function missingText(string $number): string
    {
        return 'Il PDF della ricevuta n. '.$number.' non si trova più sul sito. Non viene rifatto in automatico: un amministratore può provare a rifarlo da «Verifica delle ricevute»; se non si riesce a rifarlo, scrivi all\'assistenza di Associazione Semplice.';
    }

    /**
     * I dati fotografati all'emissione, se ci sono (assenti per le ricevute fissate prima della fotografia).
     *
     * @return array<string, mixed>|null
     */
    private function snapshotOf(object $row): ?array
    {
        if (($row->snapshot ?? null) === null) {
            return null;
        }
        $raw = Bytea::decode($row->snapshot);
        $data = json_decode($this->cipher->decrypt($this->context->require()->id, self::FIELD, $raw), true);

        /** @var array<string, mixed>|null $out */
        $out = is_array($data) ? $data : null;

        return $out;
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
