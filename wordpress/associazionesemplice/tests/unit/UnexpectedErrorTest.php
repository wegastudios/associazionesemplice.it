<?php
use AssociazioneSemplice\UnexpectedError;
use PHPUnit\Framework\TestCase;

/** «Errore imprevisto»: a chi usa il pannello un messaggio fisso con un codice; il dettaglio interno solo nel registro del server. */
final class UnexpectedErrorTest extends TestCase {

	public function test_code_is_short_and_varies(): void {
		$a = UnexpectedError::new_code();
		$this->assertMatchesRegularExpression( '/^[0-9A-F]{6}$/', $a );
		$codes = array();
		for ( $i = 0; $i < 20; $i++ ) {
			$codes[ UnexpectedError::new_code() ] = true;
		}
		$this->assertGreaterThan( 1, count( $codes ) );
	}

	public function test_fixed_message_has_no_internal_detail(): void {
		$this->assertStringContainsString( '{codice}', UnexpectedError::TEMPLATE );
		$shown = strtr( UnexpectedError::TEMPLATE, array( '{codice}' => 'A1B2C3' ) );
		$this->assertStringContainsString( 'A1B2C3', $shown );
		$this->assertStringNotContainsString( 'SELECT', $shown );
	}

	public function test_log_line_has_code_and_detail(): void {
		$e    = new \RuntimeException( 'SELECT * FROM wp_asem_people: tabella mancante' );
		$line = UnexpectedError::log_line( 'A1B2C3', $e );
		$this->assertStringContainsString( 'A1B2C3', $line );
		$this->assertStringContainsString( 'tabella mancante', $line );
		$this->assertStringContainsString( 'RuntimeException', $line );
	}

	/** Nessun gestore di azioni mostra più il messaggio interno dopo «Errore imprevisto». */
	public function test_no_action_handler_shows_the_internal_message(): void {
		$dir   = dirname( __DIR__, 2 ) . '/includes/Admin/';
		$found = 0;
		foreach ( glob( $dir . '*Actions.php' ) ?: array() as $file ) {
			$src = (string) file_get_contents( $file );
			$this->assertStringNotContainsString( "'Errore imprevisto: '", $src, basename( $file ) );
			$found += substr_count( $src, 'UnexpectedError::report( $e )' );
		}
		$this->assertGreaterThanOrEqual( 8, $found );
	}

	public function test_english_translation_exists(): void {
		$en = json_decode( (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/languages/en.json' ), true );
		$this->assertIsArray( $en );
		$this->assertArrayHasKey( UnexpectedError::TEMPLATE, $en['strings'] );
	}
}
