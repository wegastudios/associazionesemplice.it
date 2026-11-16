<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Token dell'accesso proprio dei soci (senza utente WordPress): il token della sessione, il codice a 6 cifre per entrare dall'email e
 * il token contro le richieste false (CSRF). Nel database si salva solo l'impronta: chi legge la tabella non può rientrare al posto di nessuno.
 * Classe "pura": non usa né WordPress né il database.
 */
final class SessionToken {

	/** Lunghezza del token scritto nel cookie (32 byte in base64 adatto agli indirizzi). */
	const RAW_LENGTH = 43;

	public static function generate(): string {
		return rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
	}

	public static function is_well_formed( string $raw ): bool {
		return 1 === preg_match( '/^[A-Za-z0-9_-]{' . self::RAW_LENGTH . '}$/D', $raw );
	}

	/** Impronta del token: è quella che sta nel database. */
	public static function hash( string $raw ): string {
		return hash( 'sha256', $raw );
	}

	/** Codice a 6 cifre per entrare con l'email. */
	public static function code(): string {
		return str_pad( (string) random_int( 0, 999999 ), 6, '0', STR_PAD_LEFT );
	}

	/** Impronta del codice: con solo un milione di possibilità serve il segreto del sito, altrimenti si ricostruirebbe dall'impronta. */
	public static function hash_code( string $code, int $person_id, string $secret ): string {
		return hash_hmac( 'sha256', 'code:' . $person_id . ':' . trim( $code ), $secret );
	}

	/** Token CSRF di una sessione per una certa azione: "scadenza.firma". */
	public static function csrf( string $session_hash, string $action, int $expires, string $secret ): string {
		return $expires . '.' . substr( hash_hmac( 'sha256', 'csrf:' . $action . ':' . $session_hash . ':' . $expires, $secret ), 0, 32 );
	}

	public static function check_csrf( string $token, string $session_hash, string $action, int $now, string $secret ): bool {
		if ( 1 !== preg_match( '/^(\d{9,11})\.([a-f0-9]{32})$/', trim( $token ), $m ) || (int) $m[1] < $now ) {
			return false;
		}
		return hash_equals( self::csrf( $session_hash, $action, (int) $m[1], $secret ), trim( $token ) );
	}

	/** Origine (schema://host[:porta]) di un indirizzo, in minuscolo e senza la porta di default; stringa vuota se non è un indirizzo web. */
	public static function origin_of( string $url ): string {
		$p = parse_url( trim( $url ) );
		if ( ! is_array( $p ) || empty( $p['scheme'] ) || empty( $p['host'] ) ) {
			return '';
		}
		$scheme = strtolower( $p['scheme'] );
		if ( 'http' !== $scheme && 'https' !== $scheme ) {
			return '';
		}
		$port = isset( $p['port'] ) ? (int) $p['port'] : 0;
		if ( ( 'http' === $scheme && 80 === $port ) || ( 'https' === $scheme && 443 === $port ) ) {
			$port = 0;
		}
		return $scheme . '://' . strtolower( $p['host'] ) . ( $port ? ':' . $port : '' );
	}

	/**
	 * Con l'accesso proprio una richiesta all'API vale solo se arriva dal sito stesso: un altro sottodominio (stesso sito per il
	 * browser, quindi con il cookie) non deve poter leggere i dati del socio. Senza intestazioni (richiesta diretta, non da una
	 * pagina di un altro sito) la richiesta passa.
	 *
	 * @param string   $origin     Intestazione Origin (vuota se assente).
	 * @param string   $fetch_site Intestazione Sec-Fetch-Site (vuota se assente).
	 * @param string[] $site_urls  Indirizzi del sito (home e sito).
	 */
	public static function request_origin_allowed( string $origin, string $fetch_site, array $site_urls ): bool {
		$fetch_site = strtolower( trim( $fetch_site ) );
		if ( '' !== $fetch_site && 'same-origin' !== $fetch_site && 'none' !== $fetch_site ) {
			return false; // same-site (altro sottodominio) o cross-site
		}
		$origin = trim( $origin );
		if ( '' === $origin ) {
			return true;
		}
		$got = self::origin_of( $origin );
		if ( '' === $got ) {
			return false; // «null» e valori strani
		}
		foreach ( $site_urls as $u ) {
			if ( self::origin_of( (string) $u ) === $got ) {
				return true;
			}
		}
		return false;
	}
}
