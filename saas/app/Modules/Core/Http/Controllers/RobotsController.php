<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Contracts\DemoScale;
use Illuminate\Http\Response;

/**
 * /robots.txt risposto dall'applicazione (il .htaccess gli manda la richiesta invece di servire il file statico):
 * nella demo nega tutto ai motori di ricerca, altrove dà il contenuto di public/robots.txt.
 */
final class RobotsController
{
    public function __invoke(): Response
    {
        $body = "User-agent: *\nDisallow: /\n";
        if (! DemoScale::restricted()) {
            $file = public_path('robots.txt');
            $read = is_file($file) ? file_get_contents($file) : false;
            $body = is_string($read) && $read !== '' ? $read : "User-agent: *\nDisallow:\n";
        }

        return response($body, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
