<?php
use AssociazioneSemplice\PrivateFolderRules as R;
use PHPUnit\Framework\TestCase;

/** Regole pure della cartella dei file riservati: percorso di wp-config.php, appartenenza, indirizzo web ed esito del controllo. */
final class PrivateFolderRulesTest extends TestCase {

	public function test_normalize_cleans_slashes(): void {
		$this->assertSame( '/home/u/asem', R::normalize( '/home//u/asem/' ) );
		$this->assertSame( 'C:/dati/asem', R::normalize( 'C:\\dati\\asem\\' ) );
		$this->assertSame( '/', R::normalize( '/' ) );
		$this->assertSame( '//server/share/x', R::normalize( '\\\\server\\share\\x' ) );
	}

	/** @dataProvider good_paths */
	public function test_absolute_paths_are_accepted( string $p ): void {
		$this->assertSame( '', R::path_error( $p ) );
	}

	public static function good_paths(): array {
		return array( array( '/home/utente/asem-riservata' ), array( '/var/www/privato/' ), array( 'D:\\dati\\asem' ), array( '//server/share/asem' ) );
	}

	/** @dataProvider bad_paths */
	public function test_other_paths_are_refused( string $p, string $why ): void {
		$this->assertSame( $why, R::path_error( $p ) );
	}

	public static function bad_paths(): array {
		return array(
			array( '', 'empty' ),
			array( '   ', 'empty' ),
			array( 'asem-riservata', 'relative' ),
			array( './asem', 'relative' ),
			array( 'wp-content/privato', 'relative' ),
			array( '/home/utente/../altro', 'dotdot' ),
			array( '/home/..', 'dotdot' ),
			array( '/', 'root' ),
			array( 'C:\\', 'root' ),
			array( "/home/u\0/x", 'relative' ),
		);
	}

	public function test_is_inside_compares_whole_segments(): void {
		$this->assertTrue( R::is_inside( '/var/www/html/wp-content/uploads', '/var/www/html' ) );
		$this->assertTrue( R::is_inside( '/var/www/html/', '/var/www/html' ) );
		$this->assertFalse( R::is_inside( '/var/www/html2/x', '/var/www/html' ) ); // prefisso di testo, non cartella
		$this->assertFalse( R::is_inside( '/var/www/privato', '/var/www/html' ) );
		$this->assertFalse( R::is_inside( '/var/www', '/var/www/html' ) );
		$this->assertTrue( R::is_inside( 'C:/Dati/Asem', 'c:/dati' ) );
		$this->assertFalse( R::is_inside( '', '/a' ) );
	}

	public function test_web_address_of_a_path(): void {
		$pairs = array(
			array( '/var/www/html/wp-content/uploads', 'https://example.org/wp-content/uploads' ),
			array( '/var/www/html', 'https://example.org/' ),
		);
		$this->assertSame( 'https://example.org/wp-content/uploads/associazionesemplice-private', R::map_url( '/var/www/html/wp-content/uploads/associazionesemplice-private', $pairs ) );
		$this->assertSame( 'https://example.org/privato/a%20b', R::map_url( '/var/www/html/privato/a b', $pairs ) );
		$this->assertSame( 'https://example.org/', R::map_url( '/var/www/html', $pairs ) );
		$this->assertSame( '', R::map_url( '/var/www/asem-riservata', $pairs ) ); // fuori dalla parte pubblica: nessun indirizzo
	}

	public function test_a_downloaded_probe_is_an_alarm(): void {
		$this->assertSame( array( R::EXPOSED, '' ), R::classify( 200, "xx asem-controllo-abc\n", 'asem-controllo-abc' ) );
	}

	public function test_closed_answers_are_not_an_alarm(): void {
		foreach ( array( 403, 404, 410 ) as $code ) {
			$this->assertSame( array( R::CLOSED, '' ), R::classify( $code, 'Forbidden', 'asem-controllo-abc' ) );
		}
		// una pagina qualsiasi con 200 che non contiene il file di prova (pagina di manutenzione, home): il file non si legge
		$this->assertSame( array( R::CLOSED, '' ), R::classify( 200, '<html>Home</html>', 'asem-controllo-abc' ) );
	}

	public function test_unverifiable_answers_never_raise_the_alarm(): void {
		$this->assertSame( array( R::UNKNOWN, 'loopback' ), R::classify( 0, '', 'asem-controllo-abc' ) );
		$this->assertSame( array( R::UNKNOWN, 'auth' ), R::classify( 401, '', 'asem-controllo-abc' ) ); // sito dietro password del server
		$this->assertSame( array( R::UNKNOWN, 'auth' ), R::classify( 407, '', 'asem-controllo-abc' ) );
		$this->assertSame( array( R::UNKNOWN, 'redirect' ), R::classify( 302, '', 'asem-controllo-abc' ) );
		$this->assertSame( array( R::UNKNOWN, 'server' ), R::classify( 500, '', 'asem-controllo-abc' ) );
		$this->assertSame( array( R::UNKNOWN, 'server' ), R::classify( 503, '', 'asem-controllo-abc' ) );
		$this->assertSame( array( R::UNKNOWN, 'server' ), R::classify( 429, '', 'asem-controllo-abc' ) );
	}

	public function test_an_empty_token_never_matches(): void {
		$this->assertSame( R::CLOSED, R::classify( 200, 'qualunque cosa', '' )[0] );
	}
}
