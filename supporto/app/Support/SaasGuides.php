<?php

namespace App\Support;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Interruttore delle guide del gestionale online (argomenti con prodotto «saas»).
 * Spento (di partenza) le guide non compaiono al pubblico: né negli elenchi, né nella ricerca, né nei rimandi tra guide;
 * chi apre un loro indirizzo torna alla home. Lo staff che è entrato le vede sempre, per lavorarci.
 */
class SaasGuides
{
    public const KEY = 'site.saas_guides_visible';

    /** Le guide sono accese per tutti? */
    public static function enabled(): bool
    {
        return DB::table('settings')->where('key', self::KEY)->value('value') === '1';
    }

    public static function set(bool $on): void
    {
        DB::table('settings')->updateOrInsert(['key' => self::KEY], ['value' => $on ? '1' : '0', 'updated_at' => now(), 'created_at' => now()]);
    }

    /** Chi guarda ora le vede? Tutti se acceso, altrimenti solo lo staff. */
    public static function visible(): bool
    {
        return self::enabled() || (Auth::check() && Auth::user()->isStaff());
    }

    /** Toglie dal testo di una guida del plugin il rimando alle guide del gestionale, quando queste sono nascoste. */
    public static function cleanBody(string $body): string
    {
        if (self::visible()) {
            return $body;
        }

        return ltrim((string) preg_replace('/^> Questa guida vale per il \*\*plugin WordPress\*\*\. Usi il gestionale online\?[^\n]*\n*/u', '', $body));
    }
}
