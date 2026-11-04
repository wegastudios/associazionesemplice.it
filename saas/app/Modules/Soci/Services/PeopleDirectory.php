<?php

declare(strict_types=1);

namespace App\Modules\Soci\Services;

use App\Modules\Core\Contracts\OrgContext;
use App\Modules\Soci\Contracts\People;
use App\Modules\Soci\Models\Person;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;

final readonly class PeopleDirectory implements People
{
    public function __construct(
        private DatabaseManager $db,
        private OrgContext $context,
    ) {}

    public function card(string $personId): ?array
    {
        $p = Str::isUuid($personId) ? Person::query()->find($personId) : null;

        return $p === null ? null : self::toCard($p);
    }

    public function guardians(string $personId): array
    {
        $links = $this->db->connection($this->context->require()->connection)->table('app.guardians')
            ->where('person_id', $personId)->orderByDesc('is_operative')->orderBy('created_at')->get(['guardian_person_id', 'is_operative']);
        $people = Person::query()->whereIn('id', $links->pluck('guardian_person_id'))->get()->keyBy('id');
        $out = [];
        foreach ($links as $l) {
            $p = is_string($l->guardian_person_id ?? null) ? $people->get($l->guardian_person_id) : null;
            if ($p instanceof Person) {
                $out[] = self::toCard($p) + ['operative' => ($l->is_operative ?? false) === true];
            }
        }

        return $out;
    }

    /** @return array{id: string, first_name: string, last_name: string, tax_code: string|null, birth_date: string|null, email: string|null} */
    private static function toCard(Person $p): array
    {
        return [
            'id' => $p->id, 'first_name' => $p->first_name, 'last_name' => $p->last_name,
            'tax_code' => $p->anonymized_at === null ? $p->tax_code : null,
            'birth_date' => $p->birth_date?->format('Y-m-d'),
            'email' => $p->email,
        ];
    }
}
