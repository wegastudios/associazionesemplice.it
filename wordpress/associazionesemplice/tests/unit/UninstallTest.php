<?php
use PHPUnit\Framework\TestCase;

/** La disinstallazione toglie i lavori programmati del plugin (testo dei file: non carica WordPress). */
final class UninstallTest extends TestCase {

	/** @return string[][] */
	public static function hooks(): array {
		return array(
			array( 'asem_file_crypt_migrate' ),
			array( 'asem_file_crypt_migrate_certs' ),
			array( 'asem_file_crypt_certs_check' ),
			array( 'asem_receipt_pdfs_batch' ),
			array( 'asem_private_folder_check' ),
		);
	}

	/** @dataProvider hooks */
	public function test_scheduled_jobs_are_cleared_on_uninstall( string $hook ): void {
		$root = dirname( __DIR__, 2 );
		$this->assertStringContainsString( "'" . $hook . "'", (string) file_get_contents( $root . '/uninstall.php' ), $hook );
	}

	/** @dataProvider hooks */
	public function test_those_names_are_the_real_ones( string $hook ): void {
		$all = '';
		foreach ( glob( dirname( __DIR__, 2 ) . '/includes/*.php' ) ?: array() as $f ) {
			$all .= (string) file_get_contents( $f );
		}
		$this->assertStringContainsString( "'" . $hook . "'", $all, $hook );
	}
}
