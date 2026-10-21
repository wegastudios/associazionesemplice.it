<?php

namespace App\Services\Site;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

/**
 * Controllo "e una persona?" del modulo ticket, senza CAPTCHA di terzi. Tre barriere insieme:
 *  1. campo trappola (honeypot) invisibile: un bot che compila ogni campo lo riempie;
 *  2. marca temporale cifrata emessa al caricamento del modulo: i bot inviano subito, una persona impiega qualche secondo;
 *  3. la marca scade e non e falsificabile (cifrata con la chiave dell'applicazione).
 * Il limite per indirizzo IP e applicato a parte dalle rotte.
 */
class HumanCheck
{
    public const OK = 'ok';

    /** Campo trappola compilato: si scarta in silenzio (il bot non deve capire cosa lo ha fermato). */
    public const BOT = 'bot';

    public const TOO_FAST = 'too_fast';

    public const EXPIRED = 'expired';

    public const INVALID = 'invalid';

    public const MIN_SECONDS = 4;

    public const MAX_SECONDS = 86400;

    public const HONEYPOT_FIELD = 'website';

    public const TOKEN_FIELD = 'form_token';

    /** Marca da inserire nel modulo. */
    public function issue(?CarbonInterface $at = null): string
    {
        return Crypt::encryptString('wega-form|'.($at ?? now())->getTimestamp());
    }

    public function verify(Request $request): string
    {
        if ($request->filled(self::HONEYPOT_FIELD)) {
            return self::BOT;
        }

        $token = (string) $request->input(self::TOKEN_FIELD, '');
        if ($token === '') {
            return self::INVALID;
        }

        try {
            $plain = Crypt::decryptString($token);
        } catch (DecryptException) {
            return self::INVALID;
        }

        [$prefix, $issued] = array_pad(explode('|', $plain, 2), 2, '');
        if ($prefix !== 'wega-form' || ! ctype_digit($issued)) {
            return self::INVALID;
        }

        $age = now()->getTimestamp() - (int) $issued;

        return match (true) {
            $age < self::MIN_SECONDS => self::TOO_FAST,
            $age > self::MAX_SECONDS => self::EXPIRED,
            default => self::OK,
        };
    }
}
