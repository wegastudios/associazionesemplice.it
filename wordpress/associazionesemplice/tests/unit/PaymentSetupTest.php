<?php
use AssociazioneSemplice\PaymentConfig;
use AssociazioneSemplice\PaymentSetup;
use PHPUnit\Framework\TestCase;

/** Collegamento di Stripe e PayPal dalla configurazione guidata: prova delle chiavi e webhook di Stripe, con risposte finte. */
final class PaymentSetupTest extends TestCase {

	private const URL = 'https://assoc.example/wp-json/associazionesemplice/v1/webhooks/stripe';

	private function seq( array $responses, ?array &$log = null ): callable {
		$log = array();
		return function ( $method, $url, $headers, $body ) use ( &$responses, &$log ) {
			$log[] = compact( 'method', 'url', 'headers', 'body' );
			return array_shift( $responses );
		};
	}

	private function ok( string $body ): array {
		return array( 'code' => 200, 'body' => $body );
	}

	private function in(): array {
		return array( 'stripe_mode' => 'test', 'stripe_publishable_key' => 'pk_test_1', 'stripe_secret_key' => 'sk_test_1' );
	}

	public function test_stripe_creates_the_webhook_and_keeps_its_secret(): void {
		$log = null;
		$r   = PaymentSetup::connect(
			PaymentConfig::STRIPE,
			$this->in(),
			array(),
			$this->seq( array( $this->ok( '{"livemode":false}' ), $this->ok( '{"data":[]}' ), $this->ok( '{"id":"we_1","secret":"whsec_abc"}' ) ), $log ),
			self::URL,
			true
		);
		$this->assertTrue( $r['ok'] );
		$this->assertSame( 'whsec_abc', $r['values']['stripe_webhook_secret'] );
		$this->assertSame( 'sk_test_1', $r['values']['stripe_secret_key'] );
		$this->assertSame( 'POST', $log[2]['method'] );
		$this->assertStringContainsString( 'enabled_events%5B0%5D=checkout.session.completed', (string) $log[2]['body'] );
		$this->assertStringContainsString( 'è stato creato', implode( ' ', $r['notes'] ) );
	}

	public function test_stripe_existing_webhook_is_left_alone(): void {
		$body = '{"data":[{"id":"we_9","url":"' . self::URL . '"}]}';
		$r    = PaymentSetup::connect( PaymentConfig::STRIPE, $this->in(), array(), $this->seq( array( $this->ok( '{"livemode":false}' ), $this->ok( $body ) ) ), self::URL, true );
		$this->assertTrue( $r['ok'] );
		$this->assertArrayNotHasKey( 'stripe_webhook_secret', $r['values'] );
		$this->assertStringContainsString( 'esiste già', implode( ' ', $r['notes'] ) );
	}

	public function test_stripe_restricted_key_without_permission_says_what_to_do(): void {
		$denied = array( 'code' => 403, 'body' => '{"error":{"message":"The provided key does not have the required permissions."}}' );
		$r      = PaymentSetup::connect( PaymentConfig::STRIPE, $this->in(), array(), $this->seq( array( $this->ok( '{"livemode":false}' ), $denied ) ), self::URL, true );
		$this->assertTrue( $r['ok'], 'la prova delle chiavi è riuscita: solo il webhook non si è creato' );
		$this->assertArrayNotHasKey( 'stripe_webhook_secret', $r['values'] );
		$text = implode( ' ', $r['notes'] );
		$this->assertStringContainsString( 'non si è creato da solo', $text );
		$this->assertStringContainsString( 'Webhook Endpoints', $text );
	}

	public function test_stripe_invalid_key_is_not_activated_and_no_webhook_is_asked(): void {
		$log = null;
		$r   = PaymentSetup::connect( PaymentConfig::STRIPE, $this->in(), array(), $this->seq( array( array( 'code' => 401, 'body' => '{}' ) ), $log ), self::URL, true );
		$this->assertFalse( $r['ok'] );
		$this->assertCount( 1, $log );
		$this->assertStringContainsString( 'non è stato attivato', implode( ' ', $r['notes'] ) );
	}

	public function test_stripe_webhook_needs_a_public_https_address(): void {
		$log = null;
		$r   = PaymentSetup::connect( PaymentConfig::STRIPE, $this->in(), array(), $this->seq( array( $this->ok( '{"livemode":false}' ) ), $log ), 'http://localhost/wp-json/x', true );
		$this->assertTrue( $r['ok'] );
		$this->assertCount( 1, $log );
		$this->assertStringContainsString( 'https', implode( ' ', $r['notes'] ) );
	}

	public function test_stripe_with_no_keys_does_nothing(): void {
		$r = PaymentSetup::connect( PaymentConfig::STRIPE, array( 'stripe_mode' => 'test' ), array(), $this->seq( array() ), self::URL, true );
		$this->assertNull( $r['ok'] );
		$this->assertSame( array(), $r['values'] );
	}

	public function test_stripe_empty_secret_field_keeps_the_saved_key(): void {
		$r = PaymentSetup::connect( PaymentConfig::STRIPE, array( 'stripe_mode' => 'test' ), array( 'stripe_secret_key' => 'sk_test_saved', 'stripe_webhook_secret' => 'whsec_old' ), $this->seq( array( $this->ok( '{"livemode":false}' ) ) ), self::URL, true );
		$this->assertTrue( $r['ok'] );
		$this->assertArrayNotHasKey( 'stripe_secret_key', $r['values'] );
		$this->assertArrayNotHasKey( 'stripe_webhook_secret', $r['values'] );
	}

	public function test_paypal_connection(): void {
		$r = PaymentSetup::connect( PaymentConfig::PAYPAL, array( 'paypal_mode' => 'sandbox', 'paypal_client_id' => 'ID', 'paypal_client_secret' => 'SEC' ), array(), $this->seq( array( $this->ok( '{"access_token":"A"}' ) ) ), self::URL, false );
		$this->assertTrue( $r['ok'] );
		$this->assertSame( 'sandbox', $r['values']['paypal_mode'] );
		$this->assertSame( 'SEC', $r['values']['paypal_client_secret'] );
		$bad = PaymentSetup::connect( PaymentConfig::PAYPAL, array( 'paypal_mode' => 'live', 'paypal_client_id' => 'ID', 'paypal_client_secret' => 'SEC' ), array(), $this->seq( array( array( 'code' => 401, 'body' => '{}' ) ) ), self::URL, false );
		$this->assertFalse( $bad['ok'] );
		$this->assertStringContainsString( 'non è stato attivato', implode( ' ', $bad['notes'] ) );
	}
}
