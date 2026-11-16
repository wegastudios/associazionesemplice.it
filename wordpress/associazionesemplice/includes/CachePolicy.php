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
}
