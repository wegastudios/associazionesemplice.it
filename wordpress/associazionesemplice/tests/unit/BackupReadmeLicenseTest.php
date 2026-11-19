<?php
use PHPUnit\Framework\TestCase;

/** Il LEGGIMI della copia dice il vero sul codice di licenza: avvolge la chiave solo se il Pro era attivo al momento della copia. */
final class BackupReadmeLicenseTest extends TestCase {

	public function test_attachments_button_names_all_documents(): void {
		$page = (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/Admin/BackupPage.php' );
		$this->assertStringContainsString( 'Scarica la copia con gli allegati (scontrini, fatture e altri documenti)', $page );
		$this->assertStringNotContainsString( '(scontrini e fatture)', $page );
	}

	public function test_readme_says_license_wrap_depends_on_pro(): void {
		$src = (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/Backup.php' );
		$this->assertStringContainsString( 'il codice di licenza, se il Pro era attivo.', $src );
		$this->assertStringNotContainsString( ' e con il codice di licenza. Senza', $src );
	}
}
