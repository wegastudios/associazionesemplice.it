<?php

declare(strict_types=1);

namespace App\Modules\Soci\Services;

use App\Modules\Soci\Contracts\MemberProfiles;
use App\Modules\Soci\Contracts\PeopleError;
use App\Modules\Soci\Models\Person;
use Illuminate\Support\Str;

/** Le persone viste dall'area soci (vedi il contratto). */
final readonly class MemberProfilesService implements MemberProfiles
{
    public function __construct(
        private MembershipService $memberships,
        private GuardianService $guardians,
        private PeopleService $people,
    ) {}

    public function loginCandidates(string $email): array
    {
        $email = Str::lower(trim($email));
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return [];
        }
        $found = Person::query()->where('email', $email)->whereNull('anonymized_at')->orderBy('created_at')->get();

        return array_values($found->filter(fn (Person $p): bool => $this->mayEnter($p))
            ->sortBy(static fn (Person $p): int => $p->type->isMember() ? 0 : 1)->map(static fn (Person $p): string => $p->id)->all());
    }

    public function canLogin(string $personId): bool
    {
        $p = $this->find($personId);

        return $p !== null && $this->mayEnter($p);
    }

    public function profiles(string $personId): array
    {
        $p = $this->find($personId);
        if ($p === null) {
            return [];
        }
        // Chi ha lasciato l'associazione non ha una scheda propria: vede solo quelle dei figli ancora soci.
        $out = $p->left_on === null ? [['id' => $p->id, 'name' => $p->fullName(), 'own' => true]] : [];
        foreach ($this->activeDependents($p->id) as $d) {
            $out[] = ['id' => $d['id'], 'name' => $d['name'], 'own' => false];
        }

        return $out;
    }

    public function card(string $personId): ?array
    {
        $p = $this->find($personId);
        if ($p === null) {
            return null;
        }
        $year = $this->memberships->membershipYearLabel();
        $history = $this->memberships->history($p->id);
        $current = null;
        foreach ($history as $h) {
            if ($h['valid'] && $h['social_year'] === $year) {
                $current = $h;
            }
        }

        return [
            'id' => $p->id, 'first_name' => $p->first_name, 'last_name' => $p->last_name, 'type' => $p->type->value, 'type_label' => $p->type->label(),
            'card_number' => $p->card_number, 'member' => $p->type->isMember(), 'active' => $p->type->isMember() && $this->memberships->isActive($p->id),
            'year' => $year, 'valid_to' => $current['valid_to'] ?? null, 'joined_on' => $p->joined_on?->format('d/m/Y'),
            'left' => $p->left_on !== null, 'suspended' => $p->suspended_at !== null,
            'qr_version' => max(1, $this->qrVersion($p)),
        ];
    }

    public function details(string $personId): ?array
    {
        $p = $this->find($personId);
        if ($p === null) {
            return null;
        }

        return [
            'email' => $p->email, 'phone' => $p->phone, 'tax_code' => $p->tax_code, 'birth_date' => $p->birth_date?->format('d/m/Y'),
            'birth_place' => $p->birth_place, 'address' => is_string($p->address['text'] ?? null) ? $p->address['text'] : null,
            'guardians' => array_map(static fn (array $g): string => $g['name'], $this->guardians->of($p->id)),
            'memberships' => array_map(static fn (array $h): array => ['social_year' => $h['social_year'], 'valid_to' => $h['valid_to'], 'valid' => $h['valid']], $this->memberships->history($p->id)),
        ];
    }

    public function updateContacts(string $personId, ?string $phone, ?string $address): void
    {
        $this->find($personId) ?? throw new PeopleError(['Persona non trovata.']);
        $address = $address === null || trim($address) === '' ? null : ['text' => mb_substr(trim($address), 0, 255)];
        $this->people->update($personId, ['phone' => $phone, 'address' => $address]);
    }

    /**
     * Entrano nell'area soci solo le persone ammesse: non chi è sospeso, e non chi è uscito dall'associazione, salvo che sia
     * tutore operativo di un minorenne ancora socio (in quel caso entra solo per la scheda del figlio).
     */
    private function mayEnter(Person $p): bool
    {
        if (! $p->type->canLogin() || $p->suspended_at !== null) {
            return false;
        }

        return $p->left_on === null || $this->activeDependents($p->id) !== [];
    }

    /**
     * Le schede dei figli che il tutore può gestire e che sono ancora socie (non uscite né sospese).
     *
     * @return list<array{id: string, name: string}>
     */
    private function activeDependents(string $guardianId): array
    {
        $dependents = $this->guardians->manageableDependentsOf($guardianId);
        if ($dependents === []) {
            return [];
        }
        $active = Person::query()->whereIn('id', array_column($dependents, 'id'))->whereNull('left_on')->whereNull('suspended_at')->whereNull('anonymized_at')->get()
            ->filter(static fn (Person $d): bool => $d->type->isMember())->pluck('id')->all();

        return array_values(array_filter($dependents, static fn (array $d): bool => in_array($d['id'], $active, true)));
    }

    public function regenerateCardCode(string $personId): void
    {
        $this->find($personId) ?? throw new PeopleError(['Persona non trovata.']);
        Person::query()->whereKey($personId)->increment('card_qr_version');
    }

    private function qrVersion(Person $p): int
    {
        $v = $p->getAttribute('card_qr_version');

        return is_int($v) ? $v : 1;
    }

    private function find(string $id): ?Person
    {
        $p = Str::isUuid($id) ? Person::query()->find($id) : null;

        return $p !== null && $p->anonymized_at === null ? $p : null;
    }
}
