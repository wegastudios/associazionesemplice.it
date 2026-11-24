<?php

declare(strict_types=1);

namespace App\Modules\Core\Demo;

use App\Modules\Core\Contracts\DemoScale;

/**
 * Nella demo nessuna posta esce e nessuna posta resta scritta (docs/DEMO.md). Con SAAS_DEMO acceso (e ambiente non di
 * produzione) ogni mailer diventa «array»: il messaggio vive solo in memoria per la durata della richiesta e poi sparisce,
 * qualunque cosa dicano MAIL_MAILER, le impostazioni della piattaforma o SAAS_MAIL_PRIMARY. Non «log»: i visitatori
 * scrivono email e nomi veri nei moduli, e nel file di log resterebbero, con codici e link, per giorni.
 * Si applica all'avvio, dopo le impostazioni della piattaforma. Il controllo del .env (saas:demo:check-env) segnala
 * comunque le variabili di posta vera.
 */
final class DemoMailGuard
{
    public static function enforce(): void
    {
        if (! DemoScale::restricted()) {
            return;
        }

        config(['mail.default' => 'array', 'saas.mail_queue.primary' => 'cpanel']);
        $mailers = config('mail.mailers');
        foreach (is_array($mailers) ? array_keys($mailers) : [] as $name) {
            if (is_string($name) && $name !== 'array') {
                config(['mail.mailers.'.$name => ['transport' => 'array']]);
            }
        }
    }
}
