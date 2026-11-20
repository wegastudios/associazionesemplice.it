<?php

declare(strict_types=1);

namespace App\Modules\Core\Auth;

/** Indirizzo di una pagina dell'app per le email: parte sempre dall'indirizzo configurato, mai da quello della richiesta. */
final class AppLink
{
    /** @param  array<string, string>  $parameters */
    public static function route(string $name, array $parameters = []): string
    {
        $base = config('app.url');

        return rtrim(is_string($base) ? $base : '', '/').route($name, $parameters, false);
    }
}
