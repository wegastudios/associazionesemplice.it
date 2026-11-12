<?php

declare(strict_types=1);

namespace App\Modules\Portale\Entitlements;

/** Cosa risponde il DNS per un dominio: separato per poterlo sostituire nelle prove. */
interface DomainDns
{
    /** @return list<string> i CNAME del dominio, in minuscolo e senza punto finale */
    public function cnames(string $domain): array;
}
