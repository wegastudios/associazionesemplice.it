<?php

declare(strict_types=1);

namespace App\Modules\AreaSoci\Services;

use RuntimeException;

/**
 * Chi sta usando l'area soci in questa richiesta: la sessione, la persona che è entrata e la scheda aperta (la sua o
 * quella di un minore di cui è tutore).
 */
final class CurrentMember
{
    /** @var array{session: string, person: string, active: string, profiles: list<array{id: string, name: string, own: bool}>, org_name: string, org_slug: string}|null */
    private ?array $state = null;

    /** @param  array{session: string, person: string, active: string, profiles: list<array{id: string, name: string, own: bool}>, org_name: string, org_slug: string}|null  $state */
    public function set(?array $state): void
    {
        $this->state = $state;
    }

    /** @return array{session: string, person: string, active: string, profiles: list<array{id: string, name: string, own: bool}>, org_name: string, org_slug: string} */
    public function require(): array
    {
        return $this->state ?? throw new RuntimeException('Nessun socio collegato.');
    }

    /** La scheda aperta. */
    public function active(): string
    {
        return $this->require()['active'];
    }

    /** @return list<string> tutte le schede che il socio può vedere */
    public function ids(): array
    {
        return array_map(static fn (array $p): string => $p['id'], $this->require()['profiles']);
    }
}
