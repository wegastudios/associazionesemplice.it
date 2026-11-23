<?php

declare(strict_types=1);

namespace App\Modules\Soci\Services;

use App\Modules\Core\Contracts\OrgContext;
use Illuminate\Database\DatabaseManager;

/**
 * Matricola dei soci: un numero fisso per persona, dato una volta sola a chi entra nel libro soci, in ordine di
 * ingresso (data di ingresso, poi id) e senza buchi. Non cambia e non si riassegna mai: il contatore dell'associazione
 * resta anche se una scheda sparisce. Il numero di tessera è un'altra cosa.
 */
final readonly class MatricolaService
{
    /**
     * Un'unica istruzione: blocca le schede senza matricola (chi arriva insieme aspetta e poi non le trova più, quindi
     * niente buchi né doppioni), sposta il contatore di quante sono e le numera in ordine di ingresso. Se non ne manca
     * nessuna non scrive nulla.
     */
    private const ASSIGN = <<<'SQL'
        WITH locked AS (
            SELECT id, joined_on, created_at FROM app.people
            WHERE org_id = ? AND type IN ('founder', 'ordinary', 'volunteer') AND matricola IS NULL
            FOR UPDATE
        ), missing AS (
            SELECT id, row_number() OVER (ORDER BY coalesce(joined_on, created_at::date), id) AS rn FROM locked
        ), n AS (
            SELECT count(*) AS k FROM missing
        ), counter AS (
            INSERT INTO app.matricola_counters AS c (org_id, last_value)
            SELECT ?, coalesce((SELECT max(matricola) FROM app.people WHERE org_id = ?), 0) + n.k FROM n WHERE n.k > 0
            ON CONFLICT (org_id) DO UPDATE SET last_value = c.last_value + (SELECT k FROM n)
            RETURNING last_value
        )
        UPDATE app.people p SET matricola = counter.last_value - n.k + m.rn
        FROM missing m, counter, n
        WHERE p.org_id = ? AND p.id = m.id
        SQL;

    public function __construct(
        private DatabaseManager $db,
        private OrgContext $context,
    ) {}

    /** Dà la matricola ai soci che non ce l'hanno ancora. Può essere chiamato quante volte si vuole. */
    public function assignMissing(): void
    {
        $org = $this->context->require();
        $this->db->connection($org->connection)->update(self::ASSIGN, [$org->id, $org->id, $org->id, $org->id]);
    }
}
