<?php
use AssociazioneSemplice\EmployeeRules;
use PHPUnit\Framework\TestCase;

/**
 * Ogni azione «asem_*» registrata (admin-post e admin-ajax, lette dal sorgente) o ha una regola in EmployeeRules,
 * o sta nell'elenco delle eccezioni qui sotto con il motivo. Un'azione nuova senza l'una né l'altra fa fallire il test.
 */
final class EmployeeActionsCoverageTest extends TestCase {

	/** Azioni decise dai dati della richiesta (vedi Employees::action_need()). */
	const DYNAMIC = array( 'asem_export', 'asem_doc', 'asem_quick_cash' );

	/** @return array<string,string> azione => motivo per cui nessun permesso rimovibile è in gioco */
	private static function exceptions(): array {
		return array();
	}

	/** @return string[] */
	private static function registered_actions(): array {
		$root = dirname( __DIR__, 2 ) . '/includes';
		$out  = array();
		$it   = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $f ) {
			$path = str_replace( '\\', '/', (string) $f );
			if ( '.php' !== substr( $path, -4 ) || false !== strpos( $path, '/includes/Frontend/' ) ) { // l'area soci ha le sue regole (nonce e socio che agisce), non quelle del dipendente
				continue;
			}
			$src = (string) file_get_contents( $path );
			if ( preg_match_all( "/(?:admin_post_|wp_ajax_)(?:nopriv_)?(asem_[a-z0-9_]+)/", $src, $m ) ) {
				$out = array_merge( $out, $m[1] );
			}
			if ( preg_match_all( "/'(asem_[a-z0-9_]+)'\s*=>/", $src, $m ) ) {
				$out = array_merge( $out, $m[1] );
			}
		}
		$out = array_values( array_unique( $out ) );
		sort( $out );
		return $out;
	}

	public function test_every_registered_action_is_classified_or_explained(): void {
		$actions = self::registered_actions();
		$this->assertGreaterThan( 100, count( $actions ), 'la scansione dei sorgenti non ha trovato le azioni' );
		$ex      = self::exceptions();
		$missing = array();
		foreach ( $actions as $a ) {
			if ( null !== EmployeeRules::action_need( $a ) || in_array( $a, EmployeeRules::activity_actions(), true ) || in_array( $a, self::DYNAMIC, true ) || isset( $ex[ $a ] ) ) {
				continue;
			}
			$missing[] = $a;
		}
		$this->assertSame( array(), $missing, "Azioni senza regola in EmployeeRules e senza eccezione motivata:\n" . implode( "\n", $missing ) );
	}

	public function test_exceptions_are_explained_and_not_classified(): void {
		foreach ( self::exceptions() as $a => $why ) {
			$this->assertNotSame( '', trim( $why ), $a );
			$this->assertNull( EmployeeRules::action_need( $a ), "$a ha già una regola: togli l'eccezione" );
		}
	}
}
