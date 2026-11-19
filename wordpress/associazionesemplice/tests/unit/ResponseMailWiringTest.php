<?php
use PHPUnit\Framework\TestCase;

/** Prova sul sorgente: nelle pagine pubbliche le email partono dopo la risposta e il ramo «indirizzo ignoto» fa lo stesso lavoro del ramo «socio». */
final class ResponseMailWiringTest extends TestCase {

	private function src( string $rel ): string {
		return str_replace( "\r\n", "\n", (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/' . $rel ) );
	}

	public function test_without_the_queue_it_sends_at_once_and_queue_stays_off_by_default(): void {
		$src = $this->src( 'ResponseMail.php' );
		$this->assertStringContainsString( 'private static $defer = false;', $src );
		$this->assertStringContainsString( 'fastcgi_finish_request', $src );
		$this->assertStringContainsString( "add_action( 'shutdown'", $src );
	}

	public function test_public_pages_turn_the_queue_on_before_the_request_is_handled(): void {
		$first = $this->src( 'Frontend/FirstAccess.php' );
		$this->assertLessThan( strpos( $first, "'invalid' === self::submit(" ), (int) strpos( $first, 'ResponseMail::defer( true )' ) );
		$apply = $this->src( 'Frontend/Application.php' );
		$this->assertLessThan( strpos( $apply, 'Applications::submit( $post )' ), (int) strpos( $apply, 'ResponseMail::defer( true )' ) );
		$this->assertStringContainsString( 'MemberLogin::defer( true )', $this->src( 'Frontend/MemberLoginPage.php' ) );
	}

	public function test_the_emails_of_the_three_requests_go_through_the_queue(): void {
		$apps = $this->src( 'Applications.php' );
		$this->assertStringContainsString( "ResponseMail::send( self::notify_email(), ", $apps );
		$this->assertStringContainsString( "Texts::subject( 'Nuova domanda di adesione' )", $apps );
		$this->assertStringContainsString( "ResponseMail::send( \$to, ", $apps );
		$this->assertStringContainsString( "Texts::subject( 'Domanda di adesione ricevuta' )", $apps );
		$first = $this->src( 'Frontend/FirstAccess.php' );
		$this->assertStringContainsString( 'ResponseMail::send( $user->user_email', $first );
		$login = $this->src( 'MemberLogin.php' );
		$this->assertStringContainsString( 'ResponseMail::send( $to, $subject, $text )', $login );
		$this->assertStringNotContainsString( 'self::$queue', $login );
	}

	public function test_unknown_or_blocked_addresses_do_equivalent_work(): void {
		$login = $this->src( 'MemberLogin.php' );
		$this->assertSame( 2, substr_count( $login, 'self::decoy_request();' ) );
		$first = $this->src( 'Frontend/FirstAccess.php' );
		$this->assertSame( 3, substr_count( $first, 'self::decoy( $email );' ) );
		$apps = $this->src( 'Applications.php' );
		$this->assertStringContainsString( 'self::decoy_submit();', $apps );
		// i tre controlli della domanda non si saltano a vicenda
		$this->assertStringContainsString( '$known_mail', $apps );
		$this->assertStringContainsString( '$known_tax', $apps );
		$this->assertStringContainsString( '$queue_full', $apps );
	}
}
