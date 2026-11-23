<?php
use AssociazioneSemplice\LicenseClient;
use PHPUnit\Framework\TestCase;

/** La firma già verificata si ricorda per un giorno solo senza sodium nativo, solo se positiva e legata al sito; il resto (con WordPress) è in tests/smoke.php. */
final class LicenseSignatureMemoTest extends TestCase {

	private function lic(): string {
		return (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/License.php' );
	}

	private function body(): string {
		$lic  = $this->lic();
		$from = (int) strpos( $lic, 'private static function verified_once(' );
		return substr( $lic, $from, (int) strpos( $lic, 'public static function forget_verified(' ) - $from );
	}

	public function test_jwt_expiry_reads_the_exp_claim(): void {
		$b64 = function ( string $s ) {
			return rtrim( strtr( base64_encode( $s ), '+/', '-_' ), '=' );
		};
		$jwt = $b64( '{"alg":"EdDSA"}' ) . '.' . $b64( '{"exp":1900000000}' ) . '.firma';
		$this->assertSame( 1900000000, LicenseClient::jwt_expiry( $jwt ) );
		$this->assertSame( 0, LicenseClient::jwt_expiry( 'non-un-jwt' ) );
		$this->assertSame( 0, LicenseClient::jwt_expiry( '' ) );
	}

	public function test_native_sodium_is_left_alone(): void {
		$body = $this->body();
		$this->assertStringContainsString( 'if ( self::native_sodium() || \'\' === $jwt ) {', $body );
		$this->assertLessThan( (int) strpos( $body, 'get_option( self::OPT_VMEMO' ), (int) strpos( $body, 'return $verify();' ), 'con sodium nativo si verifica sempre, senza leggere la memoria' );
		$this->assertStringContainsString( "extension_loaded( 'sodium' )", $this->lic() );
	}

	public function test_only_positive_results_are_remembered_and_bounded(): void {
		$body = $this->body();
		$this->assertStringContainsString( 'if ( null === $result ) {', $body );
		$this->assertLessThan( (int) strpos( $body, 'update_option( self::OPT_VMEMO' ), (int) strpos( $body, 'if ( null === $result ) {' ) );
		$this->assertStringContainsString( 'min( $now + self::VMEMO_TTL, LicenseClient::jwt_expiry( $jwt ) + $leeway )', $body, 'mai oltre la scadenza della prova (con la tolleranza)' );
		$this->assertStringContainsString( 'const VMEMO_TTL       = DAY_IN_SECONDS;', $this->lic() );
	}

	public function test_entry_is_tied_to_the_site_and_signed(): void {
		$lic = $this->lic();
		$this->assertStringContainsString( "wp_salt( 'auth' )", substr( $lic, (int) strpos( $lic, 'private static function vmemo_mac(' ), 400 ) );
		$this->assertStringContainsString( 'hash_equals( self::vmemo_mac(', $this->body() );
		// L'impronta porta prova, prodotto, dominio del sito e tolleranza.
		$this->assertGreaterThanOrEqual( 2, substr_count( $lic, "md5( \$s['" ) );
		$this->assertStringContainsString( "|' . \$domain . '|' . \$leeway", $lic );
	}

	public function test_new_state_and_forget_clear_the_memory(): void {
		$lic = $this->lic();
		$set = substr( $lic, (int) strpos( $lic, 'public static function set_state(' ), 400 );
		$this->assertStringContainsString( 'self::forget_verified();', $set );
		$fg = substr( $lic, (int) strpos( $lic, 'public static function forget(): void' ), 200 );
		$this->assertStringContainsString( 'self::forget_verified();', $fg );
		$un = (string) file_get_contents( dirname( __DIR__, 2 ) . '/uninstall.php' );
		$this->assertStringContainsString( "delete_option( 'asem_license_vmemo' )", $un );
	}
}
