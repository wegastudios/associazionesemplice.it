<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Regole pure della cartella dei file riservati: controllo del percorso indicato in wp-config.php, appartenenza a una cartella,
 * indirizzo web di un percorso e lettura della risposta del controllo. Niente WordPress, niente disco: si provano con i test unitari.
 */
final class PrivateFolderRules {

	/** Sottocartella (dentro la cartella riservata) dove vive, per pochi secondi, il file di prova del controllo. */
	const CHECK_DIR = 'controllo';

	const EXPOSED = 'exposed'; // il file di prova si scarica da fuori
	const CLOSED  = 'closed';  // non si scarica
	const OUTSIDE = 'outside'; // la cartella è fuori dalla parte pubblica: non ha un indirizzo web
	const UNKNOWN = 'unknown'; // non verificabile (nessun allarme)

	/** Percorso con barre normali, senza barre doppie né finale. */
	public static function normalize( string $p ): string {
		$p   = trim( str_replace( '\\', '/', $p ) );
		$unc = 0 === strpos( $p, '//' ) && 0 !== strpos( $p, '///' );
		$p   = (string) preg_replace( '#/+#', '/', $p );
		$p   = '/' === $p ? $p : rtrim( $p, '/' );
		return $unc ? '/' . $p : $p;
	}

	/**
	 * Il percorso scritto in wp-config.php va bene nella forma? Stringa vuota se sì, altrimenti il motivo:
	 * empty (vuoto), relative (non assoluto), dotdot (contiene «..»), root (è la radice del disco).
	 */
	public static function path_error( string $raw ): string {
		$p = self::normalize( $raw );
		if ( '' === $p ) {
			return 'empty';
		}
		if ( false !== strpos( $raw, "\0" ) || ! preg_match( '#^(/|[A-Za-z]:(/|$))#', $p ) ) {
			return 'relative';
		}
		if ( preg_match( '#(^|/)\.\.(/|$)#', $p ) ) {
			return 'dotdot';
		}
		if ( '/' === $p || preg_match( '#^[A-Za-z]:$#', $p ) ) {
			return 'root';
		}
		return '';
	}

	/** `$path` è `$base` o sta dentro `$base`? (Nei percorsi con lettera di unità le maiuscole non contano.) */
	public static function is_inside( string $path, string $base ): bool {
		$p = self::normalize( $path );
		$b = self::normalize( $base );
		if ( '' === $p || '' === $b ) {
			return false;
		}
		if ( preg_match( '#^[A-Za-z]:#', $b ) ) {
			$p = strtolower( $p );
			$b = strtolower( $b );
		}
		return $p === $b || 0 === strpos( $p, rtrim( $b, '/' ) . '/' );
	}

	/**
	 * Indirizzo web di un percorso sul disco, se sta dentro una delle parti pubbliche.
	 *
	 * @param array<int,array{0:string,1:string}> $pairs coppie [cartella sul disco, indirizzo], dalla più specifica
	 * @return string stringa vuota se il percorso non sta in nessuna di esse
	 */
	public static function map_url( string $path, array $pairs ): string {
		foreach ( $pairs as $pair ) {
			if ( ! self::is_inside( $path, (string) $pair[0] ) ) {
				continue;
			}
			$rel   = ltrim( substr( self::normalize( $path ), strlen( self::normalize( (string) $pair[0] ) ) ), '/' );
			$parts = '' === $rel ? array() : array_map( 'rawurlencode', explode( '/', $rel ) );
			return rtrim( (string) $pair[1], '/' ) . '/' . implode( '/', $parts );
		}
		return '';
	}

	/**
	 * Esito del controllo dalla risposta alla richiesta del file di prova.
	 *
	 * @param int    $code  codice HTTP (0 se la richiesta non è riuscita)
	 * @param string $body  corpo della risposta
	 * @param string $token testo contenuto nel file di prova
	 * @return array{0:string,1:string} [stato, motivo]: solo se «unknown» il motivo è valorizzato (loopback, auth, redirect, server)
	 */
	public static function classify( int $code, string $body, string $token ): array {
		if ( $code <= 0 ) {
			return array( self::UNKNOWN, 'loopback' );
		}
		if ( $code >= 200 && $code < 300 && '' !== $token && false !== strpos( $body, $token ) ) {
			return array( self::EXPOSED, '' );
		}
		if ( 401 === $code || 407 === $code ) {
			return array( self::UNKNOWN, 'auth' ); // sito dietro password del server: non si può dire nulla, e nessun allarme
		}
		if ( $code >= 300 && $code < 400 ) {
			return array( self::UNKNOWN, 'redirect' );
		}
		if ( $code >= 500 || 429 === $code ) {
			return array( self::UNKNOWN, 'server' );
		}
		return array( self::CLOSED, '' ); // 403, 404 o una pagina qualsiasi senza il contenuto del file
	}
}
