<?php

declare(strict_types=1);

namespace App\Modules\Soci\Contracts;

/**
 * Le persone viste dall'area soci: chi può entrare, le schede che un socio può vedere (la sua e quelle dei minori di cui
 * è tutore), la tessera, i dati personali e i recapiti che il socio può correggere da sé.
 *
 * @phpstan-type MemberCard array{id: string, first_name: string, last_name: string, type: string, type_label: string, card_number: string|null, member: bool, active: bool, year: string, valid_to: string|null, joined_on: string|null, left: bool, suspended: bool}
 * @phpstan-type MemberDetails array{email: string|null, phone: string|null, tax_code: string|null, birth_date: string|null, birth_place: string|null, address: string|null, guardians: list<string>, memberships: list<array{social_year: string, valid_to: string, valid: bool}>}
 */
interface MemberProfiles
{
    /** @return list<string> le persone che entrano con questa email (in ordine: prima chi è socio) */
    public function loginCandidates(string $email): array;

    public function canLogin(string $personId): bool;

    /** @return list<array{id: string, name: string, own: bool}> la scheda della persona e quelle dei minori di cui è tutore */
    public function profiles(string $personId): array;

    /** @return MemberCard|null */
    public function card(string $personId): ?array;

    /** @return MemberDetails|null */
    public function details(string $personId): ?array;

    /** Il socio corregge telefono e indirizzo. Errori come PeopleError. */
    public function updateContacts(string $personId, ?string $phone, ?string $address): void;
}
