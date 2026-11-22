<?php

declare(strict_types=1);

namespace App\Modules\Core\Org;

use App\Modules\Core\Contracts\CurrentOrg;
use App\Modules\Core\Contracts\NoOrgContext;
use App\Modules\Core\Contracts\OrgContext;
use App\Modules\Core\Database\DatabaseRegistry;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

final class DatabaseOrgContext implements OrgContext
{
    private const SET = "SELECT set_config('app.org_id', ?, false), set_config('lock_timeout', ?, false), "
        ."set_config('statement_timeout', ?, false), set_config('idle_in_transaction_session_timeout', ?, false)";

    private ?CurrentOrg $current = null;

    /** @param  bool  $long  comandi e code: i tempi delle operazioni lunghe valgono per tutto il processo */
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly DatabaseRegistry $registry,
        private bool $long = false,
    ) {}

    public function enter(string $orgId): CurrentOrg
    {
        if (! Str::isUuid($orgId)) {
            throw new InvalidArgumentException('Identificativo di associazione non valido.');
        }

        // Prima si azzera tutto: passando da un'associazione all'altra, o da un database all'altro,
        // nessuna connessione deve restare con l'associazione precedente.
        $this->leave();

        $row = $this->db->connection($this->registry->sharedAppConnection())
            ->table('core.orgs')
            ->where('id', $orgId)
            ->whereNull('deleted_at')
            ->first(['id', 'database']);

        if ($row === null) {
            throw new InvalidArgumentException('Associazione inesistente.');
        }

        $database = is_string($row->database ?? null) ? $row->database : null;
        $connection = $this->registry->appConnection($database);

        // Nei comandi che girano su tutte le associazioni non restano aperte le connessioni ai database dedicati già visti.
        foreach (array_keys($this->db->getConnections()) as $name) {
            if ($name !== $connection && $this->registry->isDedicatedApp((string) $name) && $this->db->connection($name)->transactionLevel() === 0) {
                $this->db->purge($name);
            }
        }

        // Associazione e tempi massimi in un'unica istruzione: nessun giro di rete in più.
        $this->db->connection($connection)->select(self::SET, [$orgId, ...$this->timeouts()]);

        return $this->current = new CurrentOrg($orgId, $connection);
    }

    public function leave(): void
    {
        $this->current = null;

        foreach (array_keys($this->db->getConnections()) as $name) {
            $connection = $this->db->connection($name);
            if ($connection->getDriverName() !== 'pgsql') {
                continue;
            }
            try {
                $connection->select("SELECT set_config('app.org_id', '', false)");
            } catch (Throwable) {
                // Se non si riesce ad azzerare, la connessione si chiude: la prossima sarà pulita.
                $this->db->purge($name);
            }
        }
    }

    public function current(): ?CurrentOrg
    {
        return $this->current;
    }

    public function require(): CurrentOrg
    {
        return $this->current ?? throw NoOrgContext::make();
    }

    public function run(string $orgId, callable $callback): mixed
    {
        $previous = $this->current;

        try {
            return $callback($this->enter($orgId));
        } finally {
            if ($previous !== null) {
                $this->enter($previous->id);
            } else {
                $this->leave();
            }
        }
    }

    public function withLongTimeouts(callable $callback): mixed
    {
        if ($this->long) {
            return $callback();
        }

        $this->long = true;
        try {
            $this->applyTimeouts();

            return $callback();
        } finally {
            $this->long = false;
            try {
                $this->applyTimeouts();
            } catch (Throwable) {
                // Connessione caduta (per esempio chiusa da idle_in_transaction_session_timeout): si chiude e l'associazione
                // si lascia, così nessuna query riparte su una connessione nuova senza associazione né tempi.
                if ($this->current !== null) {
                    $this->db->purge($this->current->connection);
                }
                $this->current = null;
            }
        }
    }

    /** Reimposta i tempi sulla connessione dell'associazione corrente, se c'è: chi entra dopo li imposta da sé. */
    private function applyTimeouts(): void
    {
        if ($this->current !== null) {
            $this->db->connection($this->current->connection)->select(self::SET, [$this->current->id, ...$this->timeouts()]);
        }
    }

    /** @return array{string, string, string} */
    private function timeouts(): array
    {
        $profile = 'saas.database_timeouts.'.($this->long ? 'long' : 'web');

        return [config()->string($profile.'.lock'), config()->string($profile.'.statement'), config()->string($profile.'.idle_in_transaction')];
    }
}
