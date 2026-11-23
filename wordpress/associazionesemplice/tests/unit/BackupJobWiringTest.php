<?php
use PHPUnit\Framework\TestCase;

/** Copia di sicurezza a tappe (L10): collegamenti nel sorgente (il comportamento lo prova smoke_backup_job.php in WordPress). */
final class BackupJobWiringTest extends TestCase {

	private static function src( string $rel ): string {
		return (string) file_get_contents( dirname( __DIR__, 2 ) . '/' . $rel );
	}

	public function test_the_job_is_declared_without_loading_the_class(): void {
		$this->assertStringContainsString( "add_filter( 'asem_long_jobs', array( __NAMESPACE__ . '\\\\Backup', 'long_jobs' ) );", self::src( 'includes/Plugin.php' ) );
		$this->assertStringContainsString( "'asem_backup_get'      => 'Backup',", self::src( 'includes/Plugin.php' ) );
	}

	public function test_tables_are_read_by_key_not_by_offset(): void {
		$b = self::src( 'includes/Backup.php' );
		$this->assertStringContainsString( 'WHERE `$col` > %s ORDER BY `$col` LIMIT %d', $b );
		$this->assertSame( 1, substr_count( $b, 'OFFSET %d' ), 'OFFSET resta solo per una tabella senza chiave primaria di una colonna' );
	}

	public function test_schema_upgrade_does_not_make_a_backup_in_a_visitor_request(): void {
		$i     = self::src( 'includes/Install.php' );
		$start = (int) strpos( $i, "version_compare( \$old, '71', '<' )" );
		$this->assertGreaterThan( 0, $start );
		$block = substr( $i, $start, (int) strpos( $i, '}', $start ) - $start );
		$this->assertStringNotContainsString( 'maybe_realign_memberships', $block, 'l\'aggiornamento dello schema segna solo il lavoro da fare' );
		$fn = substr( $i, (int) strpos( $i, 'public static function maybe_realign_memberships' ), 900 );
		$this->assertStringContainsString( 'current_user_can( Plugin::CAP )', $fn );
		$this->assertStringContainsString( 'wp_doing_ajax()', $fn );
	}

	public function test_new_texts_with_placeholders_are_translated(): void {
		$en = json_decode( self::src( 'includes/languages/en.json' ), true );
		$this->assertArrayHasKey( 'La copia di sicurezza è pronta ({size}). Dopo lo scarico non resta sul sito; se non la scarichi, si cancella da sola dopo {ore} ore.', $en['strings'] );
		$this->assertArrayHasKey( 'Attenzione: nella copia ci sono dati che rimandano a righe mancanti ({elenco}). Il ripristino è completo: controlla questi dati.', $en['strings'] );
	}

	public function test_small_copies_stay_in_one_request_and_old_ready_copies_ask_the_password(): void {
		$b    = self::src( 'includes/Backup.php' );
		$body = substr( $b, (int) strpos( $b, 'public static function handle_download' ) );
		$this->assertLessThan( (int) strpos( $body, 'self::start_job( $with )' ), (int) strpos( $body, 'self::fits_one_request( $with )' ), 'sotto la soglia la copia si fa subito, prima di qualsiasi tappa' );
		$get = substr( $b, (int) strpos( $b, 'public static function handle_get' ) );
		$this->assertLessThan( (int) strpos( $get, 'self::deliver(' ), (int) strpos( $get, 'self::require_password( $back )' ) );
		$this->assertStringContainsString( 'Mentre la copia si prepara, evita di registrare movimenti o incassi: quello che registri adesso potrebbe non entrare nella copia.', self::src( 'includes/Admin/BackupPage.php' ) );
	}
}
