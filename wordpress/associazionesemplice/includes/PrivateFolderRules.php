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
	 * Percorso suggerito per la cartella riservata: nella cartella sopra la radice web più alta fra quelle conosciute (cartella di WordPress, radice del
	 * server, cartelle dei caricamenti e dei contenuti), così non cade dentro la parte servita dal web.
	 *
	 * @param string[] $web_roots cartelle servite dal web
	 * @return string stringa vuota se non c'è nulla da cui partire
	 */
	public static function suggest_path( array $web_roots, string $name ): string {
		$best  = '';
		$depth = PHP_INT_MAX;
		foreach ( $web_roots as $r ) {
			$n = self::normalize( (string) $r );
			if ( '' === $n || '/' === $n || preg_match( '#^[A-Za-z]:$#', $n ) ) {
				continue;
			}
			$d = substr_count( $n, '/' );
			if ( $d < $depth ) {
				$depth = $d;
				$best  = $n;
			}
		}
		if ( '' === $best ) {
			return '';
		}
		$parent = self::normalize( dirname( $best ) );
		return ( '/' === $parent || '.' === $parent ? '' : $parent ) . '/' . $name;
	}

	/** Nome del file segnaposto che marca una cartella come dedicata a un sito (contiene l'identificativo del sito). */
	const PLACEHOLDER = '.asem-segnaposto';

	/** Testo del segnaposto. */
	public static function placeholder_text( string $site_id ): string {
		return 'asem-sito:' . $site_id . "\nCartella dei file riservati di Associazione Semplice: non cancellare questo file.\n";
	}

	/** Identificativo del sito scritto in un segnaposto (stringa vuota se il testo non è un segnaposto). */
	public static function placeholder_id( string $content ): string {
		return 1 === preg_match( '/^asem-sito:([0-9a-f-]{8,64})\s*$/m', $content, $m ) ? $m[1] : '';
	}

	/**
	 * Una cartella indicata va bene? Sì se è vuota o se il segnaposto è di questo sito.
	 *
	 * @param bool   $has_other_files nella cartella c'è qualcosa oltre a un segnaposto valido
	 * @param string $found_id        identificativo letto dal segnaposto ('' se manca o non è valido)
	 * @return string '' = va bene; not_empty = non è vuota e non è segnata da questo sito; foreign_site = è segnata da un altro sito
	 */
	public static function placeholder_verdict( bool $has_other_files, string $found_id, string $my_id ): string {
		if ( '' !== $found_id ) {
			return '' !== $my_id && $found_id !== $my_id ? 'foreign_site' : '';
		}
		return $has_other_files ? 'not_empty' : '';
	}

	/** Nome di un file che il plugin scrive al primo livello della cartella riservata (nome casuale, o resto di una copia interrotta). */
	public static function is_plugin_file( string $name ): bool {
		return 1 === preg_match( '/^[0-9a-f]{32}(\.part-[0-9a-f]+)?$/D', $name );
	}

	/** Una voce (file o cartella al primo livello) che il plugin scrive da sé nella cartella riservata: nome casuale, protezioni, sottocartelle sue, segnaposto. */
	public static function is_plugin_entry( string $name ): bool {
		return self::is_plugin_file( $name ) || in_array( $name, array( 'index.php', '.htaccess', 'backups', 'receipts', self::CHECK_DIR, self::PLACEHOLDER ), true );
	}

	/**
	 * Forme dei file veri su cui si fa la prova: senza estensione nella cartella principale, PDF sotto receipts/<anno>/, ZIP sotto backups/.
	 *
	 * @return array<int,array{rel:string,ext:string}>
	 */
	public static function probe_shapes( string $year ): array {
		return array(
			array( 'rel' => '', 'ext' => '' ),
			array( 'rel' => 'receipts/' . $year, 'ext' => '.pdf' ),
			array( 'rel' => 'backups', 'ext' => '.zip' ),
		);
	}

	/**
	 * Il file di riferimento (messo in una cartella pubblica, che il server di norma serve) si scarica? Se no, l'esito del controllo non vale:
	 * un «chiusa» sarebbe solo la richiesta del sito a sé stesso che non arriva dove arriva un visitatore.
	 *
	 * @return array{0:bool,1:string} [si scarica, motivo]: motivo vuoto se sì; loopback, auth, redirect, server oppure reference (risposta che non contiene il file)
	 */
	public static function reference_ok( int $code, string $body, string $token ): array {
		$c = self::classify( $code, $body, $token );
		if ( self::EXPOSED === $c[0] ) {
			return array( true, '' );
		}
		return array( false, self::UNKNOWN === $c[0] ? $c[1] : 'reference' );
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
