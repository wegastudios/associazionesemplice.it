<?php
use PHPUnit\Framework\TestCase;

/** Prova sul sorgente: il modulo «Conferma e paga» del QR porta lo stesso token che il gestore controlla, anche con l'accesso proprio. */
final class CreditsPayFormTest extends TestCase {

	private static function src(): string {
		return (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/Frontend/CreditsFront.php' );
	}

	/** Il gestore controlla con Csrf::check (token della sessione propria o nonce di WordPress). */
	public function test_handler_checks_with_csrf_class(): void {
		$this->assertStringContainsString( '\AssociazioneSemplice\Csrf::check( $action );', self::src() );
		$this->assertStringContainsString( "'asem_front_credit_pay'", self::src() );
	}

	/** Il modulo deve produrre il token con Csrf::field per la stessa azione: un nonce di WordPress non vale per chi ha l'accesso proprio. */
	public function test_pay_form_uses_the_same_token_as_the_handler(): void {
		$src = self::src();
		$this->assertStringContainsString( "Csrf::field( 'asem_front_credit_pay' )", $src );
		$this->assertStringNotContainsString( 'wp_nonce_field', $src );
	}
}
