<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Quando una pagina non deve finire nella cache di pagina (del sito, di un plugin di cache o di una CDN) e come lo si dice.
 * Con l'accesso proprio il socio non ha il cookie di WordPress che le cache riconoscono: la pagina che vede lui non va conservata.
 */
final class CachePolicy {

	/** Intestazioni di una pagina da non conservare: né in cache condivise né sul disco. */
	public static function headers(): array {
		return array(
			'Cache-Control'             => 'private, no-store, no-cache, must-revalidate, max-age=0',
			'Pragma'                    => 'no-cache',
			'X-LiteSpeed-Cache-Control' => 'no-cache',
		);
	}

	/**
	 * La pagina va tenuta fuori dalla cache?
	 *
	 * @param string $rule         regola di accesso del contenuto ({@see Visibility})
	 * @param bool   $has_session  chi guarda ha una sessione socio valida
	 */
	public static function skip_page_cache( string $rule, bool $has_session ): bool {
		return $has_session || Visibility::PUBLIC_ !== $rule;
	}

	/** Per quanti secondi si conserva una pagina pubblica degli eventi: scadenza di sicurezza, oltre allo svuotamento a ogni cambio dei dati. */
	const PAGE_TTL = 600;

	/**
	 * Chi guarda può ricevere una pagina conservata (e darne una da conservare)? Solo un visitatore anonimo che apre l'indirizzo con GET:
	 * mai chi ha una sessione (WordPress o accesso proprio), un cookie di accesso dei soci, un invio di modulo, o un indirizzo con parametri
	 * dell'area soci, dei pagamenti, di anteprima o di messaggi di esito.
	 *
	 * @param string   $method       metodo della richiesta (vuoto: non è una richiesta del sito, ad esempio la riga di comando)
	 * @param bool     $logged_in    utente WordPress collegato o sessione socio valida
	 * @param bool     $has_cookie   c'è il cookie di sessione dei soci o quello dell'accesso (anche se non valido)
	 * @param string[] $query_keys   nomi dei parametri dell'indirizzo
	 * @param bool     $other_system la richiesta è REST, AJAX, di amministrazione, cron o un'anteprima
	 */
	public static function page_cacheable( string $method, bool $logged_in, bool $has_cookie, array $query_keys, bool $other_system = false ): bool {
		$method = strtoupper( $method );
		if ( ( 'GET' !== $method && 'HEAD' !== $method ) || $logged_in || $has_cookie || $other_system ) {
			return false;
		}
		foreach ( $query_keys as $key ) {
			if ( self::private_query_key( (string) $key ) ) {
				return false;
			}
		}
		return true;
	}

	/** Un parametro dell'indirizzo che rende la pagina personale, di un passaggio (pagamento, messaggio di esito, filtro) o di anteprima. */
	public static function private_query_key( string $key ): bool {
		return 1 === preg_match( '/^(asem|asemf)_|token|nonce|^wp-|^(payerid|paymentid|_back|_n|_sig|preview|preview_id|s|p|page_id|customize_changeset_uuid|et_fb|fl_builder|elementor-preview)$/i', $key );
	}

	/** Il testo di una pagina può essere conservato? No se porta un modulo, un campo, un nonce o un token: sono per chi li riceve. */
	public static function storable_html( string $html ): bool {
		return 1 !== preg_match( '/<form\b|<input\b|<select\b|<textarea\b|nonce|[?&;]token=|_sig=|asemf_(ok|err)/i', $html );
	}

	/** Intestazioni di una pagina senza dati personali, vista da un anonimo: le cache condivise la tengono poco, il browser la richiede sempre. */
	public static function public_headers(): array {
		return array(
			'Cache-Control'             => 'public, max-age=0, s-maxage=' . self::PAGE_TTL,
			'X-LiteSpeed-Cache-Control' => 'public,max-age=' . self::PAGE_TTL,
		);
	}
}
