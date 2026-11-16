<?php
use AssociazioneSemplice\Install;
use PHPUnit\Framework\TestCase;

/** Disattivazione e disinstallazione tolgono i lavori programmati e i segreti del sito (testo dei file: non carica WordPress). */
final class UninstallTest extends TestCase {

	/** @return string[][] */
	public static function hooks(): array {
		$out = array();
		foreach ( Install::JOB_HOOKS as $hook ) {
			$out[ $hook ] = array( $hook );
		}
		return $out;
	}

	private static function source( string $rel ): string {
		return (string) file_get_contents( dirname( __DIR__, 2 ) . '/' . $rel );
	}

	private static function all_includes(): string {
		$all = '';
		foreach ( glob( dirname( __DIR__, 2 ) . '/includes/*.php' ) ?: array() as $f ) {
			$all .= (string) file_get_contents( $f );
		}
		return $all;
	}

	/** @dataProvider hooks */
	public function test_those_names_are_the_real_ones( string $hook ): void {
		$this->assertStringContainsString( "'" . $hook . "'", self::all_includes(), $hook );
	}

	public function test_known_jobs_are_in_the_list(): void {
		foreach ( array( 'asem_file_crypt_migrate', 'asem_receipt_pdfs_batch', 'asem_sms_queue', 'asem_newsletter_sync', 'asem_license_resync', 'asem_watch_month_end' ) as $hook ) {
			$this->assertContains( $hook, Install::JOB_HOOKS, $hook );
		}
	}

	public function test_list_has_no_duplicates(): void {
		$this->assertSame( count( Install::JOB_HOOKS ), count( array_unique( Install::JOB_HOOKS ) ) );
	}

	public function test_deactivation_clears_every_job(): void {
		$main = self::source( 'associazionesemplice.php' );
		$this->assertStringContainsString( 'Install::clear_jobs()', $main );
		$this->assertStringContainsString( 'wp_unschedule_hook( $hook )', self::source( 'includes/Install.php' ) );
	}

	public function test_uninstall_clears_jobs_and_secrets_even_when_data_is_kept(): void {
		$src = self::source( 'uninstall.php' );
		$pos = strpos( $src, 'asem_uninstall_secrets();' );
		$this->assertNotFalse( $pos );
		// la pulizia dei segreti viene prima del controllo «cancella i dati»
		$this->assertLessThan( (int) strpos( $src, "delete_on_uninstall'] )" ), $pos );
		foreach ( array( 'Install::JOB_HOOKS', "'license_key'", "'enc1:'", "'asem_vapid'" ) as $needle ) {
			$this->assertStringContainsString( $needle, $src, $needle );
		}
	}

	public function test_kept_data_keeps_qr_secret_and_file_keys(): void {
		$src  = self::source( 'uninstall.php' );
		$from = (int) strpos( $src, 'function asem_uninstall_secrets()' );
		$body = substr( $src, $from, (int) strpos( $src, 'function asem_uninstall_site()' ) - $from );
		$this->assertStringNotContainsString( 'delete_option( \'asem_card_salt\' )', $body );
		$this->assertStringNotContainsString( 'delete_option( \'asem_file_keys\' )', $body );
		$this->assertStringNotContainsString( 'FileKey', $body );
	}

	public function test_enc1_prefix_matches_the_secrets_class(): void {
		$this->assertSame( 'enc1:', \AssociazioneSemplice\Secrets::PREFIX );
		$this->assertSame( 'asem_vapid', \AssociazioneSemplice\Push::OPT_VAPID );
		$this->assertSame( 'asem_card_salt', \AssociazioneSemplice\Settings::CARD_SALT_OPTION );
	}

	/** @dataProvider contents */
	public function test_full_removal_covers_contents( string $needle ): void {
		$this->assertStringContainsString( $needle, self::source( 'uninstall.php' ), $needle );
	}

	/** @return string[][] */
	public static function contents(): array {
		return array(
			array( "'asem_import_person'" ),
			array( "'asem_import_tx'" ),
			array( "'asem_evento'" ),
			array( "'_asem_access'" ),
			array( "'offset' => \$asem_offset" ), // tutti i siti della rete, non solo i primi 100
		);
	}

	public function test_post_type_names_match_the_real_ones(): void {
		$this->assertSame( 'asem_evento', \AssociazioneSemplice\PublicEvents::POST_TYPE );
		$this->assertSame( 'asem_import_tx', \AssociazioneSemplice\WpAllImport::TYPE_LEDGER );
		$this->assertSame( 'asem_import_person', \AssociazioneSemplice\WpAllImport::TYPE_PEOPLE );
	}

	public function test_pro_files_clear_their_own_jobs(): void {
		$root = dirname( __DIR__, 3 ) . '/associazionesemplice-pro';
		foreach ( array( 'uninstall.php', 'associazionesemplice-pro.php' ) as $f ) {
			if ( ! is_file( $root . '/' . $f ) ) {
				$this->markTestSkipped( 'cartella del Pro assente' );
			}
			$src = (string) file_get_contents( $root . '/' . $f );
			foreach ( array( 'asem_license_check', 'asem_update_check', 'asem_license_resync', 'asem_watch_month_end', 'asem_sms_hourly', 'asem_sms_queue', 'asem_newsletter_sync' ) as $hook ) {
				$this->assertStringContainsString( "'" . $hook . "'", $src, $f . ' ' . $hook );
			}
		}
	}
}
