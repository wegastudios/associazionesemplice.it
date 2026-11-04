<?php

declare(strict_types=1);

namespace App\Modules\Core\Access;

use App\Modules\Core\Contracts\Ability;
use App\Modules\Core\Contracts\Operator;
use App\Modules\Core\Contracts\OrgRole;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;

/**
 * Associazioni in cui un utente è operatore. Contano solo le associazioni attive e non cancellate: una sospesa o
 * chiusa non si apre dal gestionale.
 */
final readonly class Memberships
{
    public function __construct(private DatabaseManager $db) {}

    /** @return list<Operator> */
    public function forUser(string $userId): array
    {
        $rows = $this->query()->where('m.user_id', $userId)->orderBy('o.name')->get();

        return array_values($rows->map(fn (object $row): Operator => $this->toOperator($row))->all());
    }

    public function find(string $userId, string $orgId): ?Operator
    {
        $row = $this->query()->where('m.user_id', $userId)->where('m.org_id', $orgId)->first();

        return $row === null ? null : $this->toOperator($row);
    }

    /** L'utente è operatore di almeno un'associazione, anche sospesa: allora la verifica in due passaggi è obbligatoria. */
    public function isOperator(string $userId): bool
    {
        return $this->db->connection('pgsql')->table('core.org_users')->where('user_id', $userId)->exists();
    }

    private function query(): Builder
    {
        return $this->db->connection('pgsql')->table('core.org_users as m')
            ->join('core.orgs as o', 'o.id', '=', 'm.org_id')
            ->leftJoin('core.org_roles as r', 'r.id', '=', 'm.custom_role_id')
            ->whereNull('o.deleted_at')
            ->where('o.status', 'active')
            ->select(['m.user_id', 'm.org_id', 'm.role', 'o.name', 'm.custom_role_id', 'r.name as custom_name', 'r.abilities as custom_abilities']);
    }

    private function toOperator(object $row): Operator
    {
        /** @var object{user_id: string, org_id: string, role: string, name: string, custom_role_id: string|null, custom_name: string|null, custom_abilities: string|null} $row */
        return new Operator(
            $row->user_id, $row->org_id, $row->name, OrgRole::from($row->role),
            $row->custom_role_id, $row->custom_name, self::abilities($row->custom_abilities),
        );
    }

    /**
     * Permessi di un ruolo personalizzato dall'array di PostgreSQL («{people.view,people.edit}»).
     *
     * @return list<Ability>
     */
    public static function abilities(?string $array): array
    {
        $values = $array === null || trim($array, '{}') === '' ? [] : explode(',', trim($array, '{}'));

        return Ability::normalize(array_map(static fn (string $v): string => trim($v, '"'), $values));
    }
}
