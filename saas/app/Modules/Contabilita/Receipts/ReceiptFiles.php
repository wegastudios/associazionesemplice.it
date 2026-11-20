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
use App\Modules\Contabilita\Archive\ArchiveLock;
use App\Modules\Contabilita\Contracts\LedgerError;
use App\Modules\Core\Contracts\OrgContext;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use League\Flysystem\UnableToReadFile;
use RuntimeException;
use Throwable;

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
        $row = $this->row($receiptId);
        $saved = $row === null ? null : $this->decodeSnapshot($row);
        if ($row !== null && $saved !== null) {
            // Già fissata, con i dati dell'emissione: niente PDF rifatto per poi scartarlo. Numero, nome del file e
            // intestatario vengono dall'emissione; si controlla soltanto che la ricevuta non sia annullata.
            $receipt = Str::isUuid($receiptId) ? $this->connection()->table('app.receipts')->where('id', $receiptId)->first(['voided_at']) : null;
            if ($receipt === null) {
                throw new LedgerError('Ricevuta non trovata.');
            }
            if (($receipt->voided_at ?? null) !== null) {
                throw new LedgerError('La ricevuta è annullata: l\'incasso è stato annullato.');
            }
            $number = is_string($saved['number'] ?? null) ? $saved['number'] : '';
            $name = is_string($row->original_name ?? null) ? $row->original_name : (is_string($saved['filename'] ?? null) ? $saved['filename'] : 'ricevuta.pdf');

            return ['pdf' => $this->read($row, $number), 'filename' => $name, 'number' => $number, 'email' => $this->pdf->holderEmail($saved), 'holder' => $this->pdf->holderName($saved)];
        }

        $meta = $this->pdf->build($receiptId);
        if ($row === null) {
            // Mai fissata (emessa prima di questa funzione): la prima volta che serve diventa il suo file definitivo.
            $this->fix($receiptId, 'lotto');
        }
        $row = $this->row($receiptId) ?? throw new RuntimeException('Ricevuta non fissata.');
        $name = is_string($row->original_name ?? null) ? $row->original_name : $meta['filename'];

        return ['pdf' => $this->read($row, $meta['number']), 'filename' => $name, 'number' => $meta['number'], 'email' => $meta['email'], 'holder' => $meta['holder']];
    }

    /** @return self::OK|self::UNFIXED|self::MISSING|self::ALTERED|self::ARCHIVED */
    public function status(string $receiptId): string
    {
        return $this->statusOf($this->row($receiptId));
    }

    /** @return self::OK|self::UNFIXED|self::MISSING|self::ALTERED|self::ARCHIVED */
    private function statusOf(?object $row): string
    {
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
        // Si rifà solo un PDF mancante o alterato: uno sano non si tocca.
        if ($this->status($receiptId) === self::OK) {
            throw new ReceiptFileProblem('Il PDF di questa ricevuta è a posto: non serve rifarlo.', ReceiptFileProblem::CHANGED);
        }
        $lock = ArchiveLock::take($this->context->require()->id, 120);
        if ($lock === null) {
            throw new ReceiptFileProblem(ArchiveLock::BUSY, ReceiptFileProblem::CHANGED);
        }
        try {
            return $this->rebuildLocked($receiptId);
        } finally {
            $lock->release();
        }
    }

    private function rebuildLocked(string $receiptId): bool
    {
        // Riletta col blocco preso: nel frattempo la ricevuta può essere entrata in un archivio.
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
        $previous = is_string($row->sha256 ?? null) ? $row->sha256 : '';
        $identical = hash_equals($previous, $stored->sha256);
        // Aggiornamento e registro nella stessa transazione: o si fanno tutti e due o nessuno.
        $this->connection()->transaction(function () use ($receiptId, $stored, $row, $previous, $identical, $snapshot): void {
            $this->connection()->table('app.receipt_files')->where('receipt_id', $receiptId)->update([
                'path' => $stored->path, 'size_bytes' => $stored->size, 'sha256' => $stored->sha256, 'source' => 'rifatta', 'fixed_at' => now(),
            ]);
            $this->audit->record('receipt.rebuilt', 'app.receipts', $receiptId, [
                'previous_path' => is_string($row->path ?? null) ? $row->path : '',
                'previous_checksum' => $previous,
                'checksum' => $stored->sha256,
                'identical' => $identical,
                'from_snapshot' => $snapshot !== null,
            ]);
        });

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
                } catch (Throwable $e) {
                    // Un errore su una ricevuta non ferma le altre: si annota (solo identificativo e tipo) e si prosegue.
                    Log::warning('Ricevuta non fissata', ['receipt' => $id, 'error' => $e::class]);
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
        // Una sola query per le ricevute dell'anno e i loro file (niente query per ogni ricevuta); resta il controllo del file.
        $rows = $this->connection()->table('app.receipts as r')->leftJoin('app.receipt_files as f', 'f.receipt_id', '=', 'r.id')
            ->where('r.year', $year)->orderBy('r.number')
            ->get(['r.id', 'r.number', 'r.issued_on', 'r.voided_at', 'f.receipt_id', 'f.path', 'f.original_name', 'f.size_bytes', 'f.sha256', 'f.archived_year']);
        foreach ($rows as $r) {
            $id = is_string($r->id ?? null) ? $r->id : '';
            $out[] = [
                'id' => $id,
                'number' => (is_scalar($r->number ?? null) ? (string) $r->number : '').'/'.$year,
                'issued_on' => is_scalar($r->issued_on ?? null) ? (string) $r->issued_on : '',
                'status' => ($r->voided_at ?? null) !== null ? 'voided' : $this->statusOf($r->receipt_id === null ? null : $r),
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
            throw new ReceiptFileProblem('Il PDF della ricevuta n. '.$number.' risulta diverso da quando è stato emesso: per sicurezza non viene scaricato. Un amministratore può controllarlo da «Verifica delle ricevute»; se non ci riesce, scrivi all\'assistenza di Associazione Semplice.', ReceiptFileProblem::ALTERED);
        }
    }

    private static function missingText(string $number): string
    {
        return 'Il PDF della ricevuta n. '.$number.' non si trova più. Non viene rifatto in automatico: un amministratore può provare a rifarlo da «Verifica delle ricevute»; se non ci riesce, scrivi all\'assistenza di Associazione Semplice.';
    }

    /**
     * I dati fotografati all'emissione, se ci sono (assenti per le ricevute fissate prima della fotografia).
     *
     * @return array<string, mixed>|null
     */
    private function snapshotOf(object $row): ?array
    {
        return $this->decodeSnapshot($row);
    }

    /**
     * Il PDF rifatto dalla fotografia dei dati dell'emissione, senza toccare niente (serve a ricostruire un archivio che
     * non si apre). Null se la ricevuta non ha la fotografia.
     */
    public function pdfFromSnapshot(string $receiptId): ?string
    {
        $row = $this->row($receiptId);
        $snapshot = $row === null ? null : $this->decodeSnapshot($row);

        return $snapshot === null ? null : $this->pdf->render($snapshot);
    }

    /** @return array<string, mixed>|null */
    private function decodeSnapshot(object $row): ?array
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
