<?php

declare(strict_types=1);

namespace App\Modules\Core\Auth;

use App\Modules\Core\Contracts\HostCookies;
use Illuminate\Auth\SessionGuard;
use Symfony\Component\HttpFoundation\Request;

/**
 * Guardia a sessione di Laravel con un solo cambio: il nome del cookie «ricordami». Laravel lo costruisce da sé
 * (`remember_<guardia>_<hash della classe>`), senza impostazioni e senza prefisso; qui diventa `__Host-remember_<guardia>`
 * dove il prefisso è valido (vedi HostCookies). Durata, cifratura e rotazione del token restano quelle di Laravel.
 */
final class HostSessionGuard extends SessionGuard
{
    public function getRecallerName(): string
    {
        // La richiesta di adesso: la guardia resta in memoria tra una richiesta e l'altra dove il processo è lungo (code, prove).
        $current = app('request');

        return HostCookies::recaller($this->name, $current instanceof Request ? $current : $this->request);
    }
}
