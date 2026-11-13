<?php

declare(strict_types=1);

namespace App\Modules\Core\Org;

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

    public function __construct(private DatabaseManager $db) {}

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
        if (isset($org->database)) {
            throw new RuntimeException('Database dedicato: la cancellazione non è ancora gestita.');
        }

        // Prima il blocco: nessun accesso mentre si cancella.
        $owner->table('core.orgs')->where('id', $orgId)->update(['status' => 'closed']);

        Storage::disk('org_files')->deleteDirectory($orgId);

        $rows = 0;
        $users = 0;
        $owner->transaction(function () use ($owner, $orgId, &$rows, &$users): void {
            $owner->select("SELECT set_config('app.org_id', ?, true)", [$orgId]);
            $rows = $this->deleteAuditRows($owner, $orgId) + $this->deleteDataRows($owner, $orgId);

            $members = $owner->table('core.org_users')->where('org_id', $orgId)->pluck('user_id')->all();
            $owner->table('core.org_users')->where('org_id', $orgId)->delete();
            foreach ($members as $userId) {
                if (is_string($userId) && ! $owner->table('core.org_users')->where('user_id', $userId)->exists()) {
                    $users += $owner->table('core.users')->where('id', $userId)->delete();
                }
            }
            $owner->table('core.org_domains')->where('org_id', $orgId)->delete();

            // Per ultima la chiave: senza, i campi cifrati e i file rimasti nei backup non si aprono più.
            $owner->table('core.orgs')->where('id', $orgId)->update(['deleted_at' => now()]);
            $owner->table('core.org_keys')->where('org_id', $orgId)->delete();
        });

        return ['rows' => $rows, 'users' => $users];
    }

    /**
     * Il registro delle azioni dell'associazione si cancella con lei (180 giorni dalla scadenza). Il trigger che lo rende
     * «solo in aggiunta» si toglie soltanto dentro questa transazione, per questa sola operazione: col commit è di nuovo attivo.
     */
    private function deleteAuditRows(ConnectionInterface $owner, string $orgId): int
    {
        $owner->statement('ALTER TABLE audit.log DISABLE TRIGGER log_append_only');
        $rows = $owner->delete('DELETE FROM audit.log WHERE org_id = ?', [$orgId]);
        $rows += $owner->delete('DELETE FROM audit.heads WHERE org_id = ?', [$orgId]);
        $rows += $owner->delete('DELETE FROM audit.anchors WHERE org_id = ?', [$orgId]);
        $owner->statement('ALTER TABLE audit.log ENABLE TRIGGER log_append_only');

        return $rows;
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
