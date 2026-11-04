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
    private ?CurrentOrg $current = null;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly DatabaseRegistry $registry,
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

        $this->db->connection($connection)->select("SELECT set_config('app.org_id', ?, false)", [$orgId]);

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
}
