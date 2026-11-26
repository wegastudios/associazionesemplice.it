<?php
use AssociazioneSemplice\Install;
use PHPUnit\Framework\TestCase;

/** Gli indici delle ricerche frequenti: elenco ordinato e sicuro da creare (la creazione vera sul database è provata da tests/smoke.php). */
final class SchemaIndexesTest extends TestCase {

	public function test_schema_version_is_68(): void {
		$this->assertSame( '85', Install::DB_VERSION );
	}

	public function test_index_list_is_well_formed(): void {
		foreach ( Install::INDEXES as $table => $indexes ) {
			$this->assertMatchesRegularExpression( '/^[a-z_]+$/', $table );
			$this->assertNotEmpty( $indexes );
			foreach ( $indexes as $name => $columns ) {
				$this->assertMatchesRegularExpression( '/^[a-z_]+$/', $name, 'nome dell\'indice' );
				$this->assertMatchesRegularExpression( '/^[a-z_]+(, [a-z_]+)*$/', $columns, 'colonne dell\'indice' );
			}
		}
	}

	public function test_every_index_column_exists_in_the_table_definition(): void {
		$src = (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/Install.php' ) . (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/ProSchema.php' ); // le tabelle dell'estensione stanno in ProSchema
		foreach ( Install::INDEXES as $table => $indexes ) {
			$this->assertSame( 1, preg_match( '/CREATE TABLE \{\$p\}' . preg_quote( $table, '/' ) . ' \((.*?)\n\) \$c;/s', $src, $m ), "tabella $table" );
			foreach ( $indexes as $columns ) {
				foreach ( explode( ', ', $columns ) as $col ) {
					$this->assertMatchesRegularExpression( '/^  ' . preg_quote( $col, '/' ) . ' /m', $m[1], "colonna $col di $table" );
				}
			}
		}
	}

	public function test_no_index_duplicates_one_already_in_the_table_definition(): void {
		$src = (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/Install.php' ) . (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/ProSchema.php' );
		foreach ( Install::INDEXES as $table => $indexes ) {
			$this->assertSame( 1, preg_match( '/CREATE TABLE \{\$p\}' . preg_quote( $table, '/' ) . ' \((.*?)\n\) \$c;/s', $src, $m ), "tabella $table" );
			foreach ( $indexes as $name => $columns ) {
				$this->assertDoesNotMatchRegularExpression( '/^  (UNIQUE )?KEY ' . preg_quote( $name, '/' ) . ' /m', $m[1], "indice $name già nella definizione di $table" );
				$this->assertDoesNotMatchRegularExpression( '/^  (UNIQUE )?KEY \w+ \(' . preg_quote( str_replace( ', ', ',', $columns ), '/' ) . '\)/m', str_replace( ', ', ',', $m[1] ), "colonne $columns già indicizzate in $table" );
			}
		}
	}

	public function test_creation_checks_existence_and_never_rewrites_data(): void {
		$src = (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/Install.php' );
		$this->assertStringContainsString( 'CREATE INDEX', $src );
		$this->assertStringContainsString( 'information_schema.STATISTICS', $src );
		$this->assertDoesNotMatchRegularExpression( '/ALTER TABLE[^\n]*(MODIFY|CHANGE|CONVERT|ENGINE)/i', $src );
	}
}
