<?php
use PHPUnit\Framework\TestCase;

/** Prova sul sorgente della demo pubblica: nessun caricamento di file e nessuna importazione o ripristino da file. */
final class DemoUploadsOffTest extends TestCase {

	private function demo(): string {
		return (string) file_get_contents( dirname( __DIR__, 4 ) . '/demo/asem-demo.php' );
	}

	public function test_documents_uploads_are_switched_off_late(): void {
		$this->assertMatchesRegularExpression( "/add_filter\\(\\s*'asem_documents_uploads_enabled',\\s*'__return_false',\\s*\\d{2,}\\s*\\)/", $this->demo() );
	}

	public function test_files_are_stopped_before_the_handlers(): void {
		$src = $this->demo();
		$this->assertStringContainsString( 'empty( $_FILES )', $src );
		$this->assertStringContainsString( 'Nella demo non si possono caricare file.', $src );
		$this->assertStringContainsString( "add_filter( 'wp_handle_upload_prefilter'", $src );
		$this->assertStringContainsString( "add_filter( 'wp_handle_sideload_prefilter'", $src );
		$this->assertStringContainsString( "add_filter( 'wp_check_filetype_and_ext'", $src );
	}

	public function test_imports_and_restore_are_blocked_by_name(): void {
		$src = $this->demo();
		foreach ( array( 'asem_import_preview', 'asem_import_apply', 'asem_import_person', 'asem_import_tx', 'asem_import_undo', 'asem_import_language', 'asem_import_texts', 'asem_tec_import', 'asem_backup_restore', 'asem_wpai_process', 'asem_save_wpai', 'asem_save_app' ) as $action ) {
			$this->assertStringContainsString( "'" . $action . "'", $src, $action );
		}
	}
}
