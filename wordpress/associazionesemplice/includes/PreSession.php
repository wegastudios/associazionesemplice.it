<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * «Pre-sessione» dell'accesso dei soci: un cookie casuale e di breve durata, dato a chi apre la pagina di accesso (o il link dell'email).
 * I moduli di accesso portano un segno derivato da quel cookie: un invio che non parte da quel browser, dalla pagina di accesso, non vale.
 * Parte pura (nessuna chiamata a WordPress): il cookie si scrive in MemberLoginPage.
 */
final class PreSession {

	const COOKIE = 'asem_ps';
	const TTL    = 7200; // il cookie e il modulo valgono due ore

	/** Nuovo valore del cookie: «orario.casuale». */
	public static function make( int $now ): string {
		return $now . '.' . SessionToken::generate();
	}

	/** Il cookie ha la forma giusta e non è scaduto? */
	public static function valid( string $cookie, int $now ): bool {
		if ( 1 !== preg_match( '/^(\d{9,11})\.([A-Za-z0-9_-]{' . SessionToken::RAW_LENGTH . '})$/D', $cookie, $m ) ) {
			return false;
		}
		return (int) $m[1] <= $now + 60 && (int) $m[1] > $now - self::TTL;
	}

	/** Segno del modulo per una certa azione, derivato dal cookie. */
	public static function token( string $cookie, string $action, string $secret ): string {
		return hash_hmac( 'sha256', 'presession:' . $action . ':' . $cookie, $secret );
	}

	/** Il segno ricevuto con il modulo corrisponde al cookie ricevuto? Senza cookie o con un cookie diverso, no. */
	public static function check( string $given, string $cookie, string $action, string $secret, int $now ): bool {
		if ( '' === $given || ! self::valid( $cookie, $now ) ) {
			return false;
		}
		return hash_equals( self::token( $cookie, $action, $secret ), $given );
	}
}
