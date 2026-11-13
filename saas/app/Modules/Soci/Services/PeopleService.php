<?php

declare(strict_types=1);

namespace App\Modules\Soci\Services;

use App\Modules\Soci\Contracts\MemberType;
use App\Modules\Soci\Contracts\PeopleError;
use App\Modules\Soci\Models\MemberLevel;
use App\Modules\Soci\Models\Person;
use App\Modules\Soci\Rules\Age;
use App\Modules\Soci\Rules\Iban;
use App\Modules\Soci\Rules\PersonRules;
use App\Modules\Soci\Rules\TaxCode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * Schede dell'anagrafica (dal PeopleService del plugin). I dati sensibili si cifrano da soli (Person); qui si
 * controllano i dati, i collegamenti (ospitante, livello) e l'unicità di tessera e codice fiscale.
 */
final readonly class PeopleService
{
    /** Campi che l'operatore può scrivere. */
    private const FIELDS = [
        'type', 'is_entity', 'entity_same_section', 'first_name', 'last_name', 'email', 'phone', 'card_number', 'tax_code', 'birth_date', 'birth_place',
        'address', 'iban', 'notes', 'level_id', 'host_person_id', 'joined_on',
    ];

    /** @param  array<string, mixed>  $data */
    public function create(array $data): Person
    {
        $clean = $this->clean($data, null);
        $person = new Person($clean);

        return $this->save($person);
    }

    /** @param  array<string, mixed>  $data */
    public function update(string $id, array $data): Person
    {
        $person = Person::query()->findOrFail($id);
        $person->fill($this->clean($data, $person));

        return $this->save($person);
    }

    /** Uscita dall'associazione: la scheda resta (storico e prima nota), con data e motivo. */
    public function leave(string $id, string $date, string $reason): Person
    {
        if (! Age::isValidDate($date)) {
            throw new PeopleError(['La data di uscita non è valida.']);
        }
        if ($date > now()->toDateString()) {
            throw new PeopleError(['L\'uscita non può essere nel futuro.']);
        }
        $person = Person::query()->findOrFail($id);
        $joined = $person->joined_on?->format('Y-m-d');
        if ($joined !== null && $date < $joined) {
            throw new PeopleError(['L\'uscita non può essere prima dell\'ingresso ('.$person->joined_on?->format('d/m/Y').').']);
        }
        $person->fill(['left_on' => $date, 'left_reason' => mb_substr(trim($reason), 0, 190)])->save();

        return $person;
    }

    /**
     * Il numero di tessera libero più basso (riempie anche i buchi), come il pulsante «primo numero libero» del plugin.
     * Si considerano solo i numeri interi; le tessere con lettere restano a mano.
     */
    public function firstFreeCard(): string
    {
        $used = Person::query()->whereNotNull('card_number')->pluck('card_number')
            ->filter(static fn (mixed $c): bool => is_string($c) && ctype_digit($c))
            ->map(static fn (string $c): int => (int) $c)->sort()->values()->all();

        $next = 1;
        foreach ($used as $n) {
            if ($n === $next) {
                $next++;
            } elseif ($n > $next) {
                break;
            }
        }

        return (string) $next;
    }

    /**
     * Ricerca per l'elenco: nome e cognome (anche parziali), numero di tessera esatto, codice fiscale esatto, email.
     *
     * @return Builder<Person>
     */
    public function search(string $query): Builder
    {
        $q = trim($query);
        $builder = Person::query()->whereNull('anonymized_at');
        if ($q === '') {
            return $builder;
        }

        if (TaxCode::isValid($q)) {
            return $builder->whereEncrypted('tax_code', $q);
        }

        return $builder->where(static function (Builder $w) use ($q): void {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($q)).'%';
            $w->whereRaw("lower(first_name || ' ' || last_name) LIKE ?", [$like])
                ->orWhereRaw("lower(last_name || ' ' || first_name) LIKE ?", [$like])
                ->orWhere('card_number', $q)
                ->orWhere('email', $q);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function clean(array $data, ?Person $current): array
    {
        $values = [];
        foreach (self::FIELDS as $field) {
            if (array_key_exists($field, $data)) {
                $value = $data[$field];
                $values[$field] = is_string($value) ? (trim($value) === '' ? null : trim($value)) : $value;
            }
        }
        $values['type'] ??= $current?->type->value ?? MemberType::Ordinary->value;
        $isEntity = array_key_exists('is_entity', $values) ? filter_var($values['is_entity'], FILTER_VALIDATE_BOOLEAN) : ($current !== null && $current->is_entity);
        if ($isEntity) {
            // Un ente non ha nome né data di nascita: la denominazione sta nel cognome.
            $values['is_entity'] = true;
            $values['first_name'] = '';
            $values['birth_date'] = null;
            $values['birth_place'] = null;
        } else {
            $values['is_entity'] = false;
            $values['entity_same_section'] = null;
        }

        $hostType = null;
        if (is_string($values['host_person_id'] ?? null)) {
            $host = Str::isUuid($values['host_person_id']) ? Person::query()->find($values['host_person_id']) : null;
            $hostType = $host?->type;
            if ($host !== null && $current !== null && $host->id === $current->id) {
                $hostType = null;
            }
        }

        $check = $values + ($current === null ? [] : [
            'first_name' => $current->first_name,
            'last_name' => $current->last_name,
        ]);
        $errors = PersonRules::validate($check, $hostType);

        if (is_string($values['level_id'] ?? null)) {
            $level = Str::isUuid($values['level_id']) ? MemberLevel::query()->where('active', true)->find($values['level_id']) : null;
            if ($level === null) {
                $errors[] = 'Livello di socio non valido.';
            }
        }
        if ($errors !== []) {
            throw new PeopleError($errors);
        }

        if (is_string($values['tax_code'] ?? null)) {
            $values['tax_code'] = TaxCode::normalize($values['tax_code']);
        }
        if (is_string($values['iban'] ?? null)) {
            $values['iban'] = Iban::normalize($values['iban']);
        }
        if (is_string($values['email'] ?? null)) {
            $values['email'] = Str::lower($values['email']);
        }
        if ($values['type'] !== MemberType::Guest->value) {
            $values['host_person_id'] = null;
        }

        return $values;
    }

    private function save(Person $person): Person
    {
        try {
            // In una transazione propria: un doppione annulla solo questo salvataggio, non il lavoro di chi chiama.
            $person->getConnection()->transaction(static fn (): bool => $person->save());
        } catch (UniqueConstraintViolationException $e) {
            $message = str_contains($e->getMessage(), 'tax_code')
                ? 'Esiste già una scheda con questo codice fiscale.'
                : (str_contains($e->getMessage(), 'card_number') ? 'Questo numero di tessera è già usato.' : 'Scheda già presente.');
            throw new PeopleError([$message]);
        }

        return $person;
    }
}
