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
		return 1 === preg_match( '/^[A-Za-z0-9_-]{' . self::RAW_LENGTH . '}$/', $raw );
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
}
