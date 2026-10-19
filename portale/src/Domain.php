<?php
namespace Portale;

/** Dominio nel formato richiesto dal server licenze: minuscolo, punycode, senza schema/porta/percorso/www, niente IP. */
final class Domain {

	/** @return string dominio normalizzato, '' se non valido */
	public static function normalize( string $input ): string {
		$d = strtolower( trim( $input ) );
		$d = preg_replace( '#^[a-z][a-z0-9+.-]*://#', '', $d );
		$d = preg_replace( '#[/?\#].*$#', '', $d );
		$d = preg_replace( '#:\d+$#', '', $d );
		$d = rtrim( (string) $d, '.' );
		if ( 0 === strpos( $d, 'www.' ) ) {
			$d = substr( $d, 4 );
		}
		if ( '' === $d || false !== strpos( $d, '*' ) || filter_var( $d, FILTER_VALIDATE_IP ) ) {
			return '';
		}
		if ( preg_match( '/[^\x00-\x7f]/', $d ) ) {
			if ( ! function_exists( 'idn_to_ascii' ) ) {
				return '';
			}
			$d = (string) idn_to_ascii( $d, 0, defined( 'INTL_IDNA_VARIANT_UTS46' ) ? INTL_IDNA_VARIANT_UTS46 : 0 );
		}
		return self::ascii_ok( $d ) ? $d : '';
	}


	/** @var array|null regole della Public Suffix List (ICANN e privati), caricate alla prima richiesta */
	private static $psl = null;

	private static function rules(): array {
		if ( null === self::$psl ) {
			self::$psl = require __DIR__ . '/PublicSuffixData.php';
		}
		return self::$psl;
	}

	/**
	 * Quante etichette (da destra) formano il suffisso pubblico, con l'algoritmo della Public Suffix List: un'eccezione («!x.y») prevale su tutto,
	 * poi vale la regola più lunga (anche con «*.»), altrimenti l'ultima etichetta.
	 *
	 * @param string[] $labels
	 */
	public static function suffixLength( array $labels ): int {
		$rules = self::rules();
		$n     = count( $labels );
		for ( $i = 0; $i < $n; $i++ ) {
			if ( isset( $rules[ '!' . implode( '.', array_slice( $labels, $i ) ) ] ) ) {
				return $n - $i - 1;
			}
		}
		for ( $i = 0; $i < $n; $i++ ) {
			if ( isset( $rules[ implode( '.', array_slice( $labels, $i ) ) ] ) || ( $i + 1 < $n && isset( $rules[ '*.' . implode( '.', array_slice( $labels, $i + 1 ) ) ] ) ) ) {
				return $n - $i;
			}
		}
		return 1;
	}

	/**
	 * Dominio registrabile: suffisso pubblico più una etichetta («staging.esempio.it» → «esempio.it», «www.esempio.co.uk» → «esempio.co.uk»,
	 * «sito.altervista.org» resta sé stesso). La licenza vale per lui e per tutti i suoi sottodomini. Un dominio che è già un suffisso pubblico non è valido.
	 */
	public static function registrable( string $normalized ): string {
		if ( '' === $normalized ) {
			return '';
		}
		$labels = explode( '.', $normalized );
		$len    = self::suffixLength( $labels );
		return $len >= count( $labels ) ? '' : implode( '.', array_slice( $labels, -( $len + 1 ) ) );
	}

	/** Dominio a cui si lega una licenza partendo da quello che scrive il cliente (valido, oppure stringa vuota). */
	public static function forLicense( string $input ): string {
		return self::registrable( self::normalize( $input ) );
	}

	/** Almeno due etichette alfanumeriche (con trattino interno); i suffissi pubblici li rifiuta il server. */
	private static function ascii_ok( string $d ): bool {
		return strlen( $d ) <= 253 && (bool) preg_match( '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $d );
	}
}
