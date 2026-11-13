<?php

declare(strict_types=1);

namespace App\Modules\Soci\Services;

use App\Modules\Core\Contracts\OrgContext;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;

/**
 * Matricola dei soci: un numero fisso per persona, dato una volta sola a chi entra nel libro soci, in ordine di
 * ingresso (data di ingresso, poi id) e senza buchi. Non cambia e non si riassegna mai: il contatore dell'associazione
 * resta anche se una scheda sparisce. Il numero di tessera è un'altra cosa.
 */
final readonly class MatricolaService
{
    public function __construct(
        private DatabaseManager $db,
        private OrgContext $context,
    ) {}

    /** Dà la matricola ai soci che non ce l'hanno ancora. Può essere chiamato quante volte si vuole. */
    public function assignMissing(): void
    {
        $c = $this->connection();
        if (! $this->missing($c)->exists()) {
            return;
        }
        $c->transaction(function () use ($c): void {
            $org = $this->context->require()->id;
            $c->statement(
                'INSERT INTO app.matricola_counters (org_id, last_value) VALUES (?, coalesce((SELECT max(matricola) FROM app.people WHERE org_id = ?), 0)) ON CONFLICT (org_id) DO NOTHING',
                [$org, $org],
            );
            $value = $c->table('app.matricola_counters')->where('org_id', $org)->lockForUpdate()->value('last_value');
            $last = is_numeric($value) ? (int) $value : 0;
            $ids = $this->missing($c)->orderByRaw('coalesce(joined_on, created_at::date)')->orderBy('id')->pluck('id');
            foreach ($ids as $id) {
                $c->table('app.people')->where('id', $id)->whereNull('matricola')->update(['matricola' => ++$last]);
            }
            $c->table('app.matricola_counters')->where('org_id', $org)->update(['last_value' => $last]);
        });
    }

    private function missing(ConnectionInterface $c): Builder
    {
        return $c->table('app.people')->whereIn('type', ['founder', 'ordinary', 'volunteer'])->whereNull('matricola');
    }

    private function connection(): ConnectionInterface
    {
        return $this->db->connection($this->context->require()->connection);
    }
}
