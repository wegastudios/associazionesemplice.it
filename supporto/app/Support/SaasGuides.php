<?php

namespace App\Support;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Interruttori delle guide per prodotto: «saas» (gestionale online, spento di partenza) e «plugin» (acceso di partenza).
 * Spento, un prodotto non compare al pubblico: né negli elenchi, né nella ricerca, né nei rimandi tra guide; chi apre un indirizzo
 * diretto torna alla home. Lo staff che è entrato le vede sempre.
 * Il gestionale può seguire il portale (SUPPORT_PORTAL_STATUS_URL): se il portale ha la vendita spenta, le guide si nascondono da sole.
 */
class SaasGuides
{
    public const KEY = 'site.saas_guides_visible';

    public const PLUGIN_KEY = 'site.plugin_guides_visible';

    private static function key(string $product): string
    {
        return $product === 'plugin' ? self::PLUGIN_KEY : self::KEY;
    }

    /** Il gestionale segue l'interruttore del portale? */
    public static function followsPortal(): bool
    {
        return (string) config('support.portal_status_url') !== '';
    }

    /** Stato letto dal portale (vendita del gestionale accesa?), null se non raggiungibile. Ricordato 5 minuti. */
    public static function portalSaysOn(): ?bool
    {
        if (! self::followsPortal()) {
            return null;
        }

        return Cache::remember('portal-saas-sale', 300, function () {
            try {
                $r = Http::timeout(3)->acceptJson()->get((string) config('support.portal_status_url'));

                return $r->successful() && is_bool($r->json('saas_sale')) ? ['v' => $r->json('saas_sale')] : ['v' => null];
            } catch (\Throwable) {
                return ['v' => null];
            }
        })['v'];
    }

    /** L'interruttore del prodotto è acceso? (per il gestionale: quello del portale, se lo si legge) */
    public static function enabled(string $product = 'saas'): bool
    {
        if ($product === 'saas' && ($fromPortal = self::portalSaysOn()) !== null) {
            return $fromPortal;
        }

        $value = DB::table('settings')->where('key', self::key($product))->value('value');

        return $product === 'plugin' ? $value !== '0' : $value === '1';
    }

    public static function set(bool $on, string $product = 'saas'): void
    {
        DB::table('settings')->updateOrInsert(['key' => self::key($product)], ['value' => $on ? '1' : '0', 'updated_at' => now(), 'created_at' => now()]);
    }

    /** Chi guarda ora vede le guide del prodotto? Tutti se acceso, altrimenti solo lo staff. */
    public static function visible(string $product = 'saas'): bool
    {
        return self::enabled($product) || (Auth::check() && Auth::user()->isStaff());
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
