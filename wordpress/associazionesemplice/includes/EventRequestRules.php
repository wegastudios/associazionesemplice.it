<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/** Regole (pure) delle richieste di evento dei non soci: quando una scheda già presente può essere riusata. Il resto sta in EventRequests. */
final class EventRequestRules {

	/** Nome o cognome ridotto al confronto: minuscole, senza accenti, solo lettere e cifre (spazi, apostrofi e trattini non contano). */
	public static function name_key( string $s ): string {
		$s = mb_strtolower( trim( $s ), 'UTF-8' );
		$s = strtr(
			$s,
			array(
				'à' => 'a', 'á' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a',
				'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
				'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
				'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o',
				'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
				'ç' => 'c', 'ñ' => 'n', 'ß' => 'ss',
			)
		);
		return (string) preg_replace( '/[^a-z0-9]+/', '', $s );
	}

	/**
	 * La persona della richiesta è quella della scheda esistente con lo stesso codice fiscale?
	 * Contano nome e cognome (senza badare a maiuscole, spazi, accenti) e, se la scheda ha una data di nascita, anche quella.
	 *
	 * @param array $card  scheda esistente (first_name, last_name, birth_date)
	 * @param array $given persona indicata nella richiesta (first_name, last_name, birth_date)
	 */
	public static function same_person( array $card, array $given ): bool {
		if ( self::name_key( (string) ( $card['first_name'] ?? '' ) ) !== self::name_key( (string) ( $given['first_name'] ?? '' ) ) ) {
			return false;
		}
		if ( self::name_key( (string) ( $card['last_name'] ?? '' ) ) !== self::name_key( (string) ( $given['last_name'] ?? '' ) ) ) {
			return false;
		}
		$b = trim( (string) ( $card['birth_date'] ?? '' ) );
		if ( '' !== $b && '0000-00-00' !== $b && substr( $b, 0, 10 ) !== substr( trim( (string) ( $given['birth_date'] ?? '' ) ), 0, 10 ) ) {
			return false;
		}
		return true;
	}
}
