<?php

declare(strict_types=1);

namespace App\Modules\Core\Demo;

use App\Modules\Core\Contracts\DemoScale;

/**
 * Nella demo nessuna posta esce davvero (docs/DEMO.md). Con SAAS_DEMO acceso (e ambiente non di produzione) ogni mailer
 * diventa «log», qualunque cosa dicano MAIL_MAILER, le impostazioni della piattaforma o SAAS_MAIL_PRIMARY: inviti, codici,
 * link dell'area soci e avvisi finiscono nel registro. Si applica all'avvio, dopo le impostazioni della piattaforma.
 * Il controllo del .env (saas:demo:check-env) segnala comunque le variabili di posta vera.
 */
final class DemoMailGuard
{
    /** Mailer che non mandano fuori niente. */
    private const SAFE = ['log', 'array'];

    public static function enforce(): void
    {
        if (! DemoScale::restricted()) {
            return;
        }

        config(['mail.default' => 'log', 'saas.mail_queue.primary' => 'cpanel']);
        $mailers = config('mail.mailers');
        foreach (is_array($mailers) ? array_keys($mailers) : [] as $name) {
            if (is_string($name) && ! in_array($name, self::SAFE, true)) {
                config(['mail.mailers.'.$name => ['transport' => 'log', 'channel' => config('mail.mailers.log.channel')]]);
            }
        }
    }
}
