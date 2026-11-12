<?php

declare(strict_types=1);

namespace App\Modules\Portale\Entitlements;

/** Cosa si vede dall'esterno di un dominio: separato per poterlo sostituire nelle prove. */
interface DomainDns
{
    /** @return list<string> i nameserver a cui il dominio è delegato, in minuscolo e senza punto finale */
    public function nameservers(string $domain): array;

    /** Il dominio risponde in HTTPS con un certificato valido per quel nome. */
    public function hasCertificate(string $domain): bool;
}
