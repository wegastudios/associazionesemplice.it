<?php

declare(strict_types=1);

namespace App\Modules\Soci\Services;

use App\Modules\Audit\Contracts\AuditLog;
use App\Modules\Core\Contracts\OrgContext;
use App\Modules\Soci\Contracts\PeopleError;
use App\Modules\Soci\Models\Person;
use App\Modules\Soci\Rules\GuardianRules;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;

/**
 * Tutori dei minori (dal Guardians del plugin): al massimo due; il primo collegato è quello operativo (segue il
 * minore nell'area soci), finché non se ne sceglie un altro. Togliendo l'operativo, passa all'altro.
 */
final readonly class GuardianService
{
    public function __construct(
        private DatabaseManager $db,
        private OrgContext $context,
        private AuditLog $audit,
    ) {}

    public function link(string $personId, string $guardianId): void
    {
        $minor = $this->person($personId);
        $guardian = $this->person($guardianId);
        $linked = $this->table()->where('person_id', $minor->id)->get(['guardian_person_id', 'is_operative']);
        if ($linked->contains('guardian_person_id', $guardian->id)) {
            return;
        }
        $first = ! $linked->contains('is_operative', true);

        $errors = GuardianRules::validate(
            ['id' => $minor->id, 'birth_date' => $minor->birth_date?->format('Y-m-d')],
            ['id' => $guardian->id, 'type' => $guardian->type, 'email' => $guardian->email, 'birth_date' => $guardian->birth_date?->format('Y-m-d')],
            $linked->count(),
            now()->toDateString(),
            $first,
        );
        if ($errors !== []) {
            throw new PeopleError($errors);
        }

        $this->table()->insert([
            'org_id' => $this->context->require()->id,
            'person_id' => $minor->id,
            'guardian_person_id' => $guardian->id,
            'is_operative' => $first,
        ]);
        $this->audit->record('guardian.linked', 'app.people', $minor->id, ['operative' => $first]);
    }

    public function setOperative(string $personId, string $guardianId): void
    {
        $minor = $this->person($personId);
        if (! $this->table()->where('person_id', $minor->id)->where('guardian_person_id', $guardianId)->exists()) {
            throw new PeopleError(['Prima collega questa persona come tutore.']);
        }
        $guardian = $this->person($guardianId);
        $errors = GuardianRules::validate(
            ['id' => $minor->id, 'birth_date' => $minor->birth_date?->format('Y-m-d')],
            ['id' => $guardian->id, 'type' => $guardian->type, 'email' => $guardian->email, 'birth_date' => $guardian->birth_date?->format('Y-m-d')],
            0,
            now()->toDateString(),
        );
        if ($errors !== []) {
            throw new PeopleError($errors);
        }

        $this->connection()->transaction(function () use ($minor, $guardian): void {
            $this->table()->where('person_id', $minor->id)->update(['is_operative' => false]);
            $this->table()->where('person_id', $minor->id)->where('guardian_person_id', $guardian->id)->update(['is_operative' => true]);
        });
        $this->audit->record('guardian.operative', 'app.people', $minor->id);
    }

    public function unlink(string $personId, string $guardianId): void
    {
        $this->connection()->transaction(function () use ($personId, $guardianId): void {
            $was = $this->table()->where('person_id', $personId)->where('guardian_person_id', $guardianId)->value('is_operative');
            if ($this->table()->where('person_id', $personId)->where('guardian_person_id', $guardianId)->delete() === 0) {
                throw new PeopleError(['Collegamento inesistente.']);
            }
            if ($was === true) {
                $other = $this->table()->where('person_id', $personId)->orderBy('created_at')->value('guardian_person_id');
                if (is_string($other)) {
                    $this->table()->where('person_id', $personId)->where('guardian_person_id', $other)->update(['is_operative' => true]);
                }
            }
            $this->audit->record('guardian.unlinked', 'app.people', $personId);
        });
    }

    /** @return list<array{id: string, name: string, email: string|null, operative: bool}> */
    public function of(string $personId): array
    {
        $rows = $this->table()->where('person_id', $personId)->orderBy('created_at')->get(['guardian_person_id', 'is_operative']);
        $people = Person::query()->whereIn('id', $rows->pluck('guardian_person_id'))->get(['id', 'first_name', 'last_name', 'email'])->keyBy('id');

        return array_values($rows->map(static function (object $r) use ($people): ?array {
            $p = is_string($r->guardian_person_id ?? null) ? $people->get($r->guardian_person_id) : null;

            return $p === null ? null : ['id' => $p->id, 'name' => $p->fullName(), 'email' => $p->email, 'operative' => ($r->is_operative ?? false) === true];
        })->filter()->values()->all());
    }

    /** @return list<array{id: string, name: string}> i minori di cui la persona è tutore */
    public function dependentsOf(string $guardianId): array
    {
        $ids = $this->table()->where('guardian_person_id', $guardianId)->pluck('person_id');

        return array_values(Person::query()->whereIn('id', $ids)->orderBy('last_name')->get(['id', 'first_name', 'last_name'])
            ->map(static fn (Person $p): array => ['id' => $p->id, 'name' => $p->fullName()])->all());
    }

    private function person(string $id): Person
    {
        $person = Str::isUuid($id) ? Person::query()->find($id) : null;

        return $person ?? throw new PeopleError(['Persona non trovata.']);
    }

    private function table(): \Illuminate\Database\Query\Builder
    {
        return $this->connection()->table('app.guardians');
    }

    private function connection(): ConnectionInterface
    {
        return $this->db->connection($this->context->require()->connection);
    }
}
