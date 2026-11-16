<?php
use PHPUnit\Framework\TestCase;

/** Il LEGGIMI della copia dice il vero sul codice di licenza: avvolge la chiave solo se il Pro era attivo al momento della copia. */
final class BackupReadmeLicenseTest extends TestCase {

	public function test_readme_says_license_wrap_depends_on_pro(): void {
		$src = (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/Backup.php' );
		$this->assertStringContainsString( 'se il Pro era attivo quando hai fatto la copia, anche con il codice di licenza', $src );
		$this->assertStringNotContainsString( 'e con il codice di licenza. Senza', $src );
	}
}
