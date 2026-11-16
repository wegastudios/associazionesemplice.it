<?php
use PHPUnit\Framework\TestCase;

/** Prova sul sorgente: i consensi di un genitore già in anagrafica si attribuiscono solo dopo la sua conferma, con un link a tempo e monouso. */
final class GuardianConsentWiringTest extends TestCase {

	private function src( string $rel ): string {
		return str_replace( "\r\n", "\n", (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/' . $rel ) );
	}

	public function test_approval_does_not_attribute_consents_to_an_existing_guardian(): void {
		$src = $this->src( 'Applications.php' );
		$fn  = (int) strpos( $src, 'public static function approve(' );
		$this->assertGreaterThan( 0, $fn );
		$body = substr( $src, $fn, (int) strpos( $src, 'private static function register_guardian' ) - $fn );
		// le attribuzioni (image_consent_by, privacy_consent_by) stanno solo nel ramo del genitore appena creato
		$this->assertSame( 1, substr_count( $body, "'image_consent_by' =>" ) );
		$this->assertSame( 1, substr_count( $body, "'privacy_consent_by' =>" ) );
		$else = (int) strpos( $body, '} else {' );
		$this->assertLessThan( $else, (int) strpos( $body, '$confirm = true;' ) );
		$this->assertGreaterThan( $else, (int) strpos( $body, "'image_consent_by' =>" ) );
		$this->assertStringContainsString( 'GuardianConsent::begin(', $body );
	}

	public function test_link_is_random_hashed_single_use_and_needs_a_post_with_a_nonce(): void {
		$src = $this->src( 'GuardianConsent.php' );
		$this->assertStringContainsString( 'wp_generate_password( 32, false, false )', $src );
		$this->assertStringContainsString( "hash( 'sha256', \$token )", $src );
		$this->assertStringContainsString( 'hash_equals(', $src );
		$this->assertStringContainsString( 'wp_verify_nonce(', $src );
		$this->assertStringContainsString( "isset( \$_POST['asem_gcf_go'] )", $src );
		$this->assertStringContainsString( "'application_data' => (string) \$p['application_data']", $src, 'una sola volta: l\'aggiornamento vale solo se l\'attesa è ancora quella letta' );
		$this->assertStringContainsString( 'DAYS            = 7', $src );
		// l'email di conferma va all'indirizzo registrato per il genitore
		$this->assertStringContainsString( "\$to = (string) \$guardian['email'];", $src );
	}

	public function test_page_and_resend_are_wired(): void {
		$this->assertStringContainsString( 'GuardianConsent::class', $this->src( 'Frontend/Application.php' ) );
		$this->assertStringContainsString( "'asem_guardian_consent_resend' => 'guardian_consent_resend'", $this->src( 'Admin/Actions.php' ) );
		$this->assertStringContainsString( 'GuardianConsent::pending(', $this->src( 'Admin/PeoplePage.php' ) );
	}
}
