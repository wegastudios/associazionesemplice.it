<?php
use AssociazioneSemplice\Secrets;
use PHPUnit\Framework\TestCase;

/** La chiave di licenza non è più in chiaro (campo, database) e le copie di sicurezza non contengono valori cifrati. */
final class LicenseKeyAtRestTest extends TestCase {

	private function src( string $file ): string {
		return (string) file_get_contents( dirname( __DIR__, 2 ) . '/' . $file );
	}

	public function test_license_field_never_prints_the_key(): void {
		$panel = $this->src( 'includes/Admin/LicensePanel.php' );
		$this->assertStringNotContainsString( 'esc_attr( License::key() )', $panel );
		$this->assertStringContainsString( 'type="password" id="asem_license_key"', $panel );
		$this->assertStringContainsString( 'Secrets::mask(', $panel );
	}

	public function test_license_key_is_a_secret_key(): void {
		$boot = $this->src( 'includes/ProBoot.php' );
		$this->assertStringContainsString( "add_filter( 'asem_secret_keys', array( __CLASS__, 'secret_keys' ) )", $boot );
		$this->assertStringContainsString( "\$keys[] = 'license_key';", $boot );
		$this->assertStringContainsString( 'Secrets::is_encrypted( $key ) ? $key : mb_substr', $boot, 'il valore cifrato non si accorcia' );
		$this->assertStringContainsString( "Settings::clear_secret( 'license_key' )", $boot, 'la rimozione è esplicita' );
	}

	public function test_old_plain_key_still_works_and_gets_encrypted(): void {
		$lic = $this->src( 'includes/License.php' );
		$this->assertStringContainsString( "Settings::secret( 'license_key' )", $lic );
		$this->assertStringContainsString( 'public static function encrypt_legacy_key', $lic );
		$this->assertStringContainsString( "array( License::class, 'encrypt_legacy_key' )", $this->src( 'includes/ProBoot.php' ) );
	}

	public function test_encrypted_key_round_trips_and_is_not_plain(): void {
		$plain  = 'WEGA-AAAAAAAA-BBBBBBBB-CCCCCCCC-DDDDDDDD';
		$stored = Secrets::encrypt( $plain, 'sale-di-prova-abcdefghijklmnopqrstuvwxyz' );
		$this->assertStringNotContainsString( $plain, $stored );
		$this->assertTrue( Secrets::is_encrypted( $stored ) );
		$this->assertSame( $plain, Secrets::decrypt( $stored, 'sale-di-prova-abcdefghijklmnopqrstuvwxyz' ) );
	}

	/** Chiave illeggibile: avviso fisso per chi amministra e nessuna chiamata al portale con una chiave vuota. */
	public function test_unreadable_key_notice_and_no_portal_call(): void {
		$lic = $this->src( 'includes/License.php' );
		$this->assertStringContainsString( 'La chiave di licenza salvata non si legge più su questo sito (può succedere dopo uno spostamento del sito o un cambio dei codici di sicurezza di WordPress). Inseriscila di nuovo in Impostazioni → Licenza.', $lic );
		$sync = (int) strpos( $lic, 'public static function sync(' );
		$body = substr( $lic, $sync );
		$unread = (int) strpos( $body, 'self::key_unreadable()' );
		$nokey  = (int) strpos( $body, 'STATUS_NOKEY' );
		$call   = (int) strpos( $body, 'LicenseClient::validate(' );
		$this->assertTrue( $unread > 0 && $unread < $nokey && $nokey < $call, 'la chiave illeggibile si ferma prima di «nessuna chiave» e prima della chiamata' );
		$this->assertStringContainsString( 'return self::failed( self::UNREADABLE_MESSAGE );', $body, 'stato di licenza invariato, errore esplicito' );
		$notice = $this->src( 'includes/Admin/LicenseNotice.php' );
		$this->assertStringContainsString( 'License::key_unreadable()', $notice );
		$this->assertStringContainsString( 'current_user_can( Plugin::CAP )', $notice );
		$this->assertStringNotContainsString( 'Dismiss::html( \'license-unreadable', $notice, 'non si può chiudere' );
		$this->assertStringContainsString( "'#asem-license'", $notice, 'collegamento al pannello Licenza' );
	}

	public function test_backup_strips_any_encrypted_value(): void {
		$enc  = Secrets::encrypt( 'sk_live_x', 'sale' );
		$in   = array( 'association_name' => 'Ente', 'stripe_secret_key' => $enc, 'altro' => array( 'k' => $enc, 'ok' => 'valore' ), 'n' => 3 );
		$out  = Secrets::strip_encrypted( $in );
		$this->assertSame( array( 'association_name' => 'Ente', 'altro' => array( 'ok' => 'valore' ), 'n' => 3 ), $out );
	}

	public function test_backup_uses_the_strip_on_export_and_restore(): void {
		$src = $this->src( 'includes/Backup.php' );
		$this->assertSame( 2, substr_count( $src, 'Secrets::strip_encrypted(' ) );
	}
}
