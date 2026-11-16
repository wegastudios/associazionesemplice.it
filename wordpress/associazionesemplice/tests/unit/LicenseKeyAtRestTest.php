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
