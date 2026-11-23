<?php

declare(strict_types=1);

namespace App\Modules\Core\Org;

use App\Modules\Core\Contracts\DatabaseNames;
use App\Modules\Core\Contracts\OrgAccessChanged;
use App\Modules\Core\Contracts\OrgPurger;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Cancella i dati di un'associazione dal database condiviso. Le tabelle dell'associazione si trovano da sole (ogni
 * tabella con la colonna `org_id` negli schemi dei dati), così un modulo nuovo non resta fuori. La RLS vale anche per il
 * proprietario: si lavora con `app.org_id` impostato su quell'associazione, dentro una transazione.
 */
final readonly class DatabaseOrgPurger implements OrgPurger
{
    /** Schemi con i dati delle associazioni. `audit` ha la sua regola (registro solo in aggiunta), `core` è trattato a parte. */
    private const DATA_SCHEMAS = ['app', 'sites', 'portal'];

    /**
     * @param  int<1, max>  $auditBatch  righe del registro cancellate per transazione
     */
    public function __construct(private DatabaseManager $db, private DatabaseNames $names, private int $auditBatch = 5000) {}

    public function purge(string $orgId): array
    {
        $owner = $this->db->connection('pgsql_owner');
        $org = $owner->table('core.orgs')->where('id', $orgId)->first(['id', 'status', 'database']);
        if ($org === null) {
            throw new RuntimeException('Associazione non trovata.');
        }
        if (! in_array($org->status, ['suspended', 'closed'], true)) {
            throw new RuntimeException('Si cancellano solo associazioni sospese o chiuse.');
        }

        // Nessun accesso mentre si cancella: l'associazione è già sospesa o chiusa. Stato e file si toccano solo dopo il
        // successo del database, così dopo un errore a metà il giro successivo può riprovare.
        $users = 0;
        // Il database dedicato, se c'è, si svuota qui; il database stesso lo toglie chi gestisce il server (vedi il registro dello staff).
        $data = is_string($org->database ?? null) && $org->database !== '' ? $this->db->connection($this->names->ownerConnection($org->database)) : $owner;
        // Prima il registro, a blocchi; poi, in una sola transazione, il resto del registro e i dati. Se i dati non si
        // cancellano restano tutti, e del registro resta una catena che si verifica ancora (vedi deleteAuditLogInBatches).
        $hadAnchor = $data->transaction(function () use ($data, $orgId): bool {
            $this->enterPurge($data, $orgId);

            return $data->table('audit.anchors')->where('org_id', $orgId)->exists();
        });
        $rows = $this->deleteAuditLogInBatches($data, $orgId);
        $data->transaction(function () use ($data, $orgId, $hadAnchor, &$rows): void {
            $this->enterPurge($data, $orgId);
            $rows += $this->deleteAuditRows($data, $orgId, $hadAnchor) + $this->deleteDataRows($data, $orgId);
        });

        $owner->transaction(function () use ($owner, $orgId, &$users): void {
            $owner->select("SELECT set_config('app.org_id', ?, true)", [$orgId]);

            $members = $owner->table('core.org_users')->where('org_id', $orgId)->pluck('user_id')->all();
            $owner->table('core.org_users')->where('org_id', $orgId)->delete();
            foreach ($members as $userId) {
                if (is_string($userId) && ! $owner->table('core.org_users')->where('user_id', $userId)->exists()) {
                    $users += $owner->table('core.users')->where('id', $userId)->delete();
                }
            }
            $owner->table('core.org_domains')->where('org_id', $orgId)->delete();
            $owner->table('core.org_roles')->where('org_id', $orgId)->delete();
            // Lavori in coda e falliti di questa associazione (portano il suo identificativo, mai dati personali).
            $owner->delete('DELETE FROM core.jobs WHERE position(? in payload) > 0', [$orgId]);
            $owner->delete('DELETE FROM core.failed_jobs WHERE position(? in payload) > 0', [$orgId]);

            // Per ultima la chiave: senza, il servizio non apre più i campi cifrati e i file; i backup già fatti scadono da soli entro 30 giorni.
            $owner->table('core.orgs')->where('id', $orgId)->update(['status' => 'closed', 'deleted_at' => now()]);
            $owner->table('core.org_keys')->where('org_id', $orgId)->delete();
        });
        event(new OrgAccessChanged($orgId));

        // Per ultimi i file: se il database non si è svuotato, sono ancora tutti lì.
        Storage::disk('org_files')->deleteDirectory($orgId);

        return ['rows' => $rows, 'users' => $users];
    }

    /**
     * Il registro delle azioni dell'associazione si cancella con lei (180 giorni dalla scadenza), a blocchi, ognuno nella
     * sua transazione: le scritture delle altre associazioni non aspettano. Si parte dalle righe più vecchie e a ogni
     * blocco l'ultima riga tolta diventa l'ancora della catena (come nella pulizia delle partizioni vecchie): se ci si
     * ferma a metà, la catena che resta si verifica ancora.
     */
    private function deleteAuditLogInBatches(ConnectionInterface $data, string $orgId): int
    {
        $rows = 0;
        while (true) {
            $deleted = $data->transaction(function () use ($data, $orgId): int {
                $this->enterPurge($data, $orgId);
                $last = $data->scalar(
                    'SELECT max(seq) FROM (SELECT seq FROM audit.log WHERE org_id = ? ORDER BY seq LIMIT ?) AS b',
                    [$orgId, $this->auditBatch],
                );
                if (! is_int($last) && ! (is_string($last) && ctype_digit($last))) {
                    return 0;
                }
                $last = (int) $last;
                $data->insert(
                    'INSERT INTO audit.anchors (org_id, seq, hash)
                     SELECT org_id, seq, hash FROM audit.log WHERE org_id = ? AND seq = ? LIMIT 1
                     ON CONFLICT (org_id) DO UPDATE SET seq = EXCLUDED.seq, hash = EXCLUDED.hash, anchored_at = now()
                     WHERE audit.anchors.seq < EXCLUDED.seq',
                    [$orgId, $last],
                );

                return $data->delete('DELETE FROM audit.log WHERE org_id = ? AND seq <= ?', [$orgId, $last]);
            });
            if ($deleted === 0) {
                return $rows;
            }
            $rows += $deleted;
        }
    }

    /**
     * Quel che resta del registro, nella transazione finale. La testa si blocca per prima: una scrittura arrivata durante
     * i blocchi è già finita (e la sua riga si cancella qui) o aspetta la fine. L'ancora messa dai blocchi non si conta.
     */
    private function deleteAuditRows(ConnectionInterface $data, string $orgId, bool $hadAnchor): int
    {
        $data->select('SELECT 1 FROM audit.heads WHERE org_id = ? FOR UPDATE', [$orgId]);
        $rows = $data->delete('DELETE FROM audit.log WHERE org_id = ?', [$orgId]);
        $rows += $data->delete('DELETE FROM audit.heads WHERE org_id = ?', [$orgId]);
        $anchors = $data->delete('DELETE FROM audit.anchors WHERE org_id = ?', [$orgId]);
        $rows += $hadAnchor ? $anchors : 0;
        $rows += $data->delete('DELETE FROM audit.verify_checkpoints WHERE org_id = ?', [$orgId]);

        return $rows;
    }

    /**
     * Associazione corrente e varco della cancellazione, in questa sola transazione: i trigger di sola aggiunta (registro
     * delle azioni, movimenti) lasciano cancellare solo le righe di questa associazione, e solo al proprietario.
     */
    private function enterPurge(ConnectionInterface $data, string $orgId): void
    {
        $data->select("SELECT set_config('app.org_id', ?, true), set_config('app.purging', ?, true)", [$orgId, $orgId]);
    }

    private function deleteDataRows(ConnectionInterface $owner, string $orgId): int
    {
        $found = $owner->select(
            "SELECT c.table_schema || '.' || c.table_name AS t
             FROM information_schema.columns c
             JOIN information_schema.tables t ON t.table_schema = c.table_schema AND t.table_name = c.table_name
             WHERE c.column_name = 'org_id' AND t.table_type = 'BASE TABLE' AND c.table_schema IN ('app', 'sites', 'portal')
             ORDER BY 1",
        );
        $pending = [];
        foreach ($found as $row) {
            $name = is_object($row) && isset($row->t) && is_string($row->t) ? $row->t : '';
            if (preg_match('/^('.implode('|', self::DATA_SCHEMAS).')\.[a-z][a-z0-9_]*$/', $name) === 1) {
                $pending[] = $name;
            }
        }

        // Le tabelle si riferiscono tra loro: si prova più volte, quelle ancora richiamate da altre restano per il giro dopo.
        $rows = 0;
        for ($pass = 0; $pass < 12 && $pending !== []; $pass++) {
            $left = [];
            foreach ($pending as $table) {
                try {
                    $rows += $owner->transaction(fn (): int => $owner->delete("DELETE FROM {$table} WHERE org_id = ?", [$orgId]));
                } catch (QueryException) {
                    $left[] = $table;
                }
            }
            if (count($left) === count($pending)) {
                break;
            }
            $pending = $left;
        }
        if ($pending !== []) {
            throw new RuntimeException('Tabelle non svuotate: '.implode(', ', $pending));
        }

        // La riga dell'associazione nello schema dei dati, se c'è.
        if ($owner->scalar("SELECT to_regclass('app.orgs') IS NOT NULL") === true) {
            $rows += $owner->delete('DELETE FROM app.orgs WHERE id = ?', [$orgId]);
        }

        return $rows;
    }
}
