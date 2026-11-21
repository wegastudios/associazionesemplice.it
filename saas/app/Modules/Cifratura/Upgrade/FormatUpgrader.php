<?php

declare(strict_types=1);

namespace App\Modules\Cifratura\Upgrade;

use App\Modules\Audit\Contracts\AuditLog;
use App\Modules\Cifratura\Contracts\Bytea;
use App\Modules\Cifratura\Contracts\FieldCipher;
use App\Modules\Core\Contracts\OrgContext;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * Porta i campi cifrati «legati alla riga» dal formato 0x01 al formato 0x02 e, se serve, li riporta indietro.
 *
 * Tutto avviene dentro l'associazione corrente (OrgContext), con la connessione dell'applicazione (ruolo senza
 * privilegi, Row-Level Security attiva): serve solo leggere e aggiornare le righe di quell'associazione. Ogni passaggio
 * è una transazione sola, con le righe bloccate (FOR UPDATE). Dentro la transazione si fanno due giri: il primo
 * decifra, ricifra e verifica ogni campo senza scrivere; solo se nessun campo è illeggibile il secondo salva il vecchio
 * testo cifrato in app.crypt_format_backups e sostituisce il campo. Nessun valore, in chiaro o cifrato, esce da qui.
 *
 * Restano 0x01: fotografie del libro soci, ricevute, anni chiusi, richieste di adesione e certificati.
 */
final readonly class FormatUpgrader
{
    /**
     * Campi con l'opzione `bound` del cast `Encrypted`, per tabella (un test confronta l'elenco con i modelli).
     *
     * @var array<string, list<string>>
     */
    public const FIELDS = [
        'app.people' => ['tax_code', 'birth_date', 'birth_place', 'address', 'phone', 'iban', 'notes'],
        'app.payment_settings' => ['stripe_secret', 'stripe_webhook', 'paypal_secret'],
    ];

    public const BACKUPS = 'app.crypt_format_backups';

    /** Giorni di conservazione delle copie d'appoggio. */
    public const RETENTION_DAYS = 30;

    private const CHUNK = 500;

    /** Il numero di versione della chiave sta in una colonna intera a 32 bit con segno. */
    private const MAX_KEY_VERSION = 2147483647;

    public function __construct(
        private DatabaseManager $db,
        private OrgContext $context,
        private FieldCipher $cipher,
        private AuditLog $audit,
    ) {}

    /**
     * Le associazioni non cancellate (anche sospese o chiuse: hanno ancora i dati), o solo quella indicata.
     *
     * @return list<string>
     */
    public function orgIds(?string $only = null): array
    {
        $query = $this->db->connection('pgsql')->table('core.orgs')->whereNull('deleted_at')->orderBy('id');
        if ($only !== null) {
            $query->where('id', $only);
        }

        return array_values(array_filter($query->pluck('id')->all(), 'is_string'));
    }

    /**
     * 0x01 → 0x02 per l'associazione corrente. Con $dryRun non scrive nulla (nemmeno blocchi). Se un campo non si
     * decifra, e non è una prova, solleva FormatUpgradeFailed: la transazione si annulla e non resta niente scritto.
     */
    public function upgrade(bool $dryRun): FormatReport
    {
        $org = $this->context->require();
        $conn = $this->db->connection($org->connection);

        $work = function () use ($org, $conn, $dryRun): FormatReport {
            $report = new FormatReport;
            foreach (self::FIELDS as $table => $fields) {
                $report->tables[$table] = $this->walk($conn, $org->id, $table, $fields, ! $dryRun, false);
            }

            if ($report->unreadableRows() > 0) {
                if ($dryRun) {
                    return $report;
                }

                throw new FormatUpgradeFailed($report);
            }
            if ($dryRun) {
                return $report;
            }

            foreach (self::FIELDS as $table => $fields) {
                $done = $this->walk($conn, $org->id, $table, $fields, true, true);
                if ($done->convertFields !== $report->tables[$table]->convertFields) {
                    throw new RuntimeException('Il conteggio è cambiato durante il passaggio.');
                }
            }
            $this->audit->record('crypto.format_upgraded', null, null, $report->details());

            return $report;
        };

        if ($dryRun) {
            return $work();
        }
        $report = $conn->transaction($work);

        return $report instanceof FormatReport ? $report : throw new RuntimeException('Passaggio non riuscito.');
    }

    /** Scrive nel registro dell'associazione corrente che il passaggio non è riuscito (dopo l'annullamento). */
    public function recordFailure(FormatReport $report): void
    {
        $this->audit->record('crypto.format_failed', null, null, $report->details());
    }

    /**
     * 0x02 → 0x01 dalle copie d'appoggio, solo dove la riga è ancora quella scritta dal passaggio (l'impronta del testo
     * corrente coincide con quella salvata). Le altre si saltano. Con $dryRun non scrive.
     *
     * @return array<string, array{restore: int, changed: int}> per tabella
     */
    public function undo(bool $dryRun): array
    {
        $org = $this->context->require();
        $conn = $this->db->connection($org->connection);

        $work = function () use ($org, $conn, $dryRun): array {
            $result = [];
            $restored = 0;
            $changed = 0;
            foreach (self::FIELDS as $table => $fields) {
                $count = ['restore' => 0, 'changed' => 0];
                $allowed = array_map(static fn (string $f): string => $f.'_enc', $fields);
                $last = null;
                do {
                    $rows = self::objects($conn->select(
                        'SELECT id, row_id, column_name, old_value, new_hash FROM '.self::BACKUPS.' WHERE org_id = ? AND table_name = ?'
                        .($last === null ? '' : ' AND id > ?').' ORDER BY id LIMIT '.self::CHUNK.($dryRun ? '' : ' FOR UPDATE'),
                        $last === null ? [$org->id, $table] : [$org->id, $table, $last],
                    ));
                    foreach ($rows as $backup) {
                        $backupId = is_string($backup->id) ? $backup->id : throw new RuntimeException('Copia non valida.');
                        $last = $backupId;
                        $rowId = is_string($backup->row_id) ? $backup->row_id : '';
                        $column = is_string($backup->column_name) ? $backup->column_name : '';
                        if (! in_array($column, $allowed, true)) {
                            $count['changed']++;

                            continue;
                        }

                        $current = $conn->selectOne(
                            "SELECT {$column} AS value FROM {$table} WHERE org_id = ? AND id = ?".($dryRun ? '' : ' FOR UPDATE'),
                            [$org->id, $rowId],
                        );
                        $currentValue = $current instanceof stdClass ? $current->value : null;
                        if ($currentValue === null
                            || ! hash_equals(Bytea::decode($backup->new_hash), hash('sha256', Bytea::decode($currentValue), true))) {
                            $count['changed']++;

                            continue;
                        }

                        $count['restore']++;
                        if (! $dryRun) {
                            if ($conn->update("UPDATE {$table} SET {$column} = ? WHERE org_id = ? AND id = ?", [Bytea::encode(Bytea::decode($backup->old_value)), $org->id, $rowId]) !== 1) {
                                throw new RuntimeException('Riga non aggiornata.');
                            }
                            $conn->delete('DELETE FROM '.self::BACKUPS.' WHERE org_id = ? AND id = ?', [$org->id, $backupId]);
                        }
                    }
                } while (count($rows) === self::CHUNK);

                $result[$table] = $count;
                $restored += $count['restore'];
                $changed += $count['changed'];
            }
            if (! $dryRun) {
                $this->audit->record('crypto.format_restored', null, null, ['fields_restored' => $restored, 'fields_skipped' => $changed]);
            }

            return $result;
        };

        if ($dryRun) {
            return $work();
        }
        $result = $conn->transaction($work);

        /** @var array<string, array{restore: int, changed: int}> $result */
        return is_array($result) ? $result : throw new RuntimeException('Ripristino non riuscito.');
    }

    /**
     * Toglie le copie d'appoggio più vecchie di RETENTION_DAYS per l'associazione corrente; restituisce quante sono
     * (o quante sarebbero, con $dryRun). Se ne ha tolte, lo scrive nel registro delle azioni.
     */
    public function prune(bool $dryRun): int
    {
        $org = $this->context->require();
        $conn = $this->db->connection($org->connection);
        $days = self::RETENTION_DAYS;
        $limit = "created_at < now() - interval '{$days} days'";

        if ($dryRun) {
            $count = $conn->scalar('SELECT count(*) FROM '.self::BACKUPS." WHERE org_id = ? AND {$limit}", [$org->id]);

            return is_int($count) ? $count : 0;
        }

        $deleted = $conn->transaction(function () use ($conn, $org, $limit): int {
            $n = $conn->delete('DELETE FROM '.self::BACKUPS." WHERE org_id = ? AND {$limit}", [$org->id]);
            if ($n > 0) {
                $this->audit->record('crypto.format_pruned', null, null, ['fields_removed' => $n, 'days' => self::RETENTION_DAYS]);
            }

            return $n;
        });

        return is_int($deleted) ? $deleted : 0;
    }

    /**
     * Un giro sulle righe di una tabella, a gruppi, per id. Con $lock le righe si bloccano (FOR UPDATE); con $write si
     * salva la copia d'appoggio e si sostituisce il testo cifrato. Conta e basta: nessun valore esce.
     *
     * @param  list<string>  $fields
     */
    private function walk(ConnectionInterface $conn, string $orgId, string $table, array $fields, bool $lock, bool $write): TableCount
    {
        $count = new TableCount($table);
        $select = 'SELECT id, '.implode(', ', array_map(static fn (string $f): string => $f.'_enc', $fields))." FROM {$table} WHERE org_id = ?";
        $last = null;

        do {
            $rows = self::objects($conn->select(
                $select.($last === null ? '' : ' AND id > ?').' ORDER BY id LIMIT '.self::CHUNK.($lock ? ' FOR UPDATE' : ''),
                $last === null ? [$orgId] : [$orgId, $last],
            ));
            foreach ($rows as $row) {
                $id = is_string($row->id) ? $row->id : throw new RuntimeException('Riga senza identificativo.');
                $last = $id;
                $count->rows++;

                $updates = [];
                $hashes = [];
                $unreadable = false;
                $present = 0;
                foreach ($fields as $field) {
                    $stored = $row->{$field.'_enc'} ?? null;
                    if ($stored === null) {
                        continue;
                    }
                    $present++;
                    $bytes = Bytea::decode($stored);
                    $format = substr($bytes, 0, 1);
                    if ($format === "\x02") {
                        continue;
                    }

                    try {
                        if ($format !== "\x01") {
                            throw new RuntimeException('Formato sconosciuto.');
                        }
                        $new = $this->reencrypt($orgId, $table.'.'.$field, $bytes, $id);
                    } catch (Throwable) {
                        $unreadable = true;
                        if (count($count->unreadable) < TableCount::REMEMBERED) {
                            $count->unreadable[] = "{$table} {$id} {$field}_enc";
                        }

                        continue;
                    }
                    $updates[$field.'_enc'] = [$bytes, $new];
                    $hashes[$field.'_enc'] = hash('sha256', $new, true);
                }

                if ($updates !== []) {
                    $count->convertRows++;
                    $count->convertFields += count($updates);
                }
                if ($unreadable) {
                    $count->unreadableRows++;
                } elseif ($updates === [] && $present > 0) {
                    $count->alreadyRows++;
                }

                if ($write && ! $unreadable && $updates !== []) {
                    $this->write($conn, $orgId, $table, $id, $updates, $hashes);
                }
            }
        } while (count($rows) === self::CHUNK);

        return $count;
    }

    /**
     * @param  array<string, array{0: string, 1: string}>  $updates  colonna => [vecchio, nuovo]
     * @param  array<string, string>  $hashes  colonna => SHA-256 del nuovo testo
     */
    private function write(ConnectionInterface $conn, string $orgId, string $table, string $id, array $updates, array $hashes): void
    {
        $set = [];
        $values = [];
        foreach ($updates as $column => [$old, $new]) {
            $conn->statement(
                'INSERT INTO '.self::BACKUPS.' (org_id, id, table_name, row_id, column_name, old_value, new_hash) VALUES (?, ?, ?, ?, ?, ?, ?)'
                .' ON CONFLICT (org_id, table_name, row_id, column_name) DO UPDATE SET id = EXCLUDED.id, old_value = EXCLUDED.old_value, new_hash = EXCLUDED.new_hash, created_at = now()',
                [$orgId, (string) Str::uuid7(), $table, $id, $column, Bytea::encode($old), Bytea::encode($hashes[$column])],
            );
            $set[] = "{$column} = ?";
            $values[] = Bytea::encode($new);
        }

        if ($conn->update("UPDATE {$table} SET ".implode(', ', $set).' WHERE org_id = ? AND id = ?', array_merge($values, [$orgId, $id])) !== 1) {
            throw new RuntimeException('Riga non aggiornata.');
        }
    }

    /**
     * Le righe lette dal database come oggetti.
     *
     * @param  array<mixed>  $rows
     * @return list<stdClass>
     */
    private static function objects(array $rows): array
    {
        return array_values(array_filter($rows, static fn (mixed $row): bool => $row instanceof stdClass));
    }

    /** Decifra il vecchio testo, lo ricifra con l'id della riga e controlla che il nuovo si riapra identico. */
    private function reencrypt(string $orgId, string $field, string $old, string $rowId): string
    {
        // La versione della chiave si cerca nel database come intero a 32 bit: un testo guasto con un numero più grande
        // farebbe fallire la ricerca con un errore del database (e annullerebbe la transazione) invece di dirsi illeggibile.
        $header = unpack('N', substr($old, 1, 4));
        if (! is_array($header) || ! is_int($header[1] ?? null) || $header[1] > self::MAX_KEY_VERSION) {
            throw new RuntimeException('Versione della chiave non valida.');
        }

        $plain = $this->cipher->decrypt($orgId, $field, $old, $rowId);
        $new = $this->cipher->encrypt($orgId, $field, $plain, $rowId);
        if ($new[0] !== "\x02" || ! hash_equals($plain, $this->cipher->decrypt($orgId, $field, $new, $rowId))) {
            throw new RuntimeException('La verifica del nuovo testo cifrato non è riuscita.');
        }

        return $new;
    }
}
