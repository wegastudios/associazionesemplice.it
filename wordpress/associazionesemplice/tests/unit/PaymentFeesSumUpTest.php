<?php
use AssociazioneSemplice\Gateways;
use AssociazioneSemplice\PaymentConfig as P;
use AssociazioneSemplice\SumUpApi;
use PHPUnit\Framework\TestCase;

/** Commissioni di incasso (fisse e percentuali, anche ribaltate) e pagamento con SumUp. */
final class PaymentFeesSumUpTest extends TestCase {

	public function test_fee_is_fixed_plus_percentage_rounded_to_the_cent(): void {
		$this->assertSame( 40, P::fee_cents( 1000, 25, 150 ) ); // 0,25 + 1,5% di 10,00
		$this->assertSame( 0, P::fee_cents( 0, 25, 150 ) );
		$this->assertSame( 25, P::fee_cents( 1000, 25, 0 ) );
		$this->assertSame( 15, P::fee_cents( 1000, 0, 150 ) );
		$this->assertSame( 0, P::fee_cents( 1000, -5, -5 ) );
	}

	public function test_gross_up_leaves_the_due_amount_after_the_fee(): void {
		foreach ( array( array( 1000, 25, 150 ), array( 5000, 0, 140 ), array( 750, 35, 0 ), array( 99, 10, 250 ) ) as $c ) {
			list( $base, $fixed, $bp ) = $c;
			$total = P::gross_up( $base, $fixed, $bp );
			$this->assertGreaterThanOrEqual( $base, $total - P::fee_cents( $total, $fixed, $bp ), "base $base" );
			$this->assertLessThanOrEqual( $base + 1, $total - P::fee_cents( $total, $fixed, $bp ), "base $base: scarto al massimo di un centesimo" );
		}
		$this->assertSame( 1041, P::gross_up( 1000, 25, 150 ) );
		$this->assertSame( 0, P::gross_up( 0, 25, 150 ) );
	}

	public function test_fee_settings_are_clamped_and_pass_on_is_off_by_default(): void {
		$this->assertSame( array( 0, 0, false ), P::fee_of( array(), 'stripe' ) );
		$this->assertSame( array( 2000, 2500, true ), P::fee_of( array( 'pay_fee_fixed_stripe' => 99999, 'pay_fee_pct_stripe' => 99999, 'pay_fee_pass_stripe' => 1 ), 'stripe' ) );
		$this->assertSame( '', P::fee_note( array( 'pay_fee_fixed_sumup' => 25, 'pay_fee_pct_sumup' => 150 ), 'sumup' ), 'senza ribaltamento nessuna nota' );
		$note = P::fee_note( array( 'pay_fee_fixed_sumup' => 25, 'pay_fee_pct_sumup' => 150, 'pay_fee_pass_sumup' => 1 ), 'sumup' );
		$this->assertStringContainsString( '1,5%', $note );
		$this->assertSame( array( P::STRIPE, P::PAYPAL, P::SUMUP, P::SATISPAY ), P::fee_gateways() );
	}

	public function test_providers_include_sumup_alone_and_together(): void {
		$this->assertSame( array( 'sumup' ), P::gateways_of( 'sumup' ) );
		$this->assertSame( array( 'stripe', 'sumup' ), P::gateways_of( 'stripe_sumup' ) );
		$this->assertSame( array( 'paypal', 'sumup' ), P::gateways_of( 'paypal_sumup' ) );
		$this->assertSame( array( 'stripe', 'paypal', 'sumup' ), P::gateways_of( 'stripe_paypal_sumup' ) );
		foreach ( array( 'sumup', 'stripe_sumup', 'paypal_sumup', 'stripe_paypal_sumup' ) as $p ) {
			$this->assertTrue( P::is_valid( $p ) );
		}
	}

	public function test_sumup_configuration_is_checked(): void {
		$bad = P::validate_gateway( array( 'sumup_api_key' => '', 'sumup_merchant_code' => '' ), P::SUMUP );
		$this->assertCount( 2, $bad['errors'] );
		$ok = P::validate_gateway( array( 'sumup_api_key' => 'sup_sk_abcdefghijklmnop', 'sumup_merchant_code' => 'MABCD123', 'site_https' => 1 ), P::SUMUP );
		$this->assertSame( array(), $ok['errors'] );
		$http = P::validate_gateway( array( 'sumup_api_key' => 'sup_sk_abcdefghijklmnop', 'sumup_merchant_code' => 'MABCD123', 'site_https' => 0 ), P::SUMUP );
		$this->assertSame( array(), $http['errors'] );
		$this->assertNotEmpty( $http['warnings'], 'senza https si avvisa' );
		$this->assertNotSame( '', P::note( P::SUMUP ) );
		$this->assertNotSame( '', P::label( P::SUMUP ) );
	}

	public function test_sumup_checkout_payload_and_responses(): void {
		$items   = array( array( 'type' => 'booking', 'person_id' => 3, 'person_name' => 'Ada Rossi', 'amount_cents' => 1041, 'label' => 'Cena' ) );
		$payload = SumUpApi::checkout_payload( $items, 'pub-123', 'MABCD123', 'https://example.org/area/?ok=1', 'Associazione' );
		$this->assertSame( 10.41, $payload['amount'] );
		$this->assertSame( 'EUR', $payload['currency'] );
		$this->assertSame( 'MABCD123', $payload['merchant_code'] );
		$this->assertSame( 'pub-123', $payload['checkout_reference'] );
		$this->assertTrue( $payload['hosted_checkout']['enabled'] );

		$seen = array();
		$http = function ( $method, $url, $headers, $body ) use ( &$seen ) {
			$seen[] = array( $method, $url, $headers['Authorization'] ?? '' );
			return array( 'code' => 201, 'body' => '{"id":"co-1","status":"PENDING","hosted_checkout_url":"https://checkout.sumup.com/pay/co-1"}' );
		};
		$r = SumUpApi::create_checkout( $http, 'sup_sk_key', $payload );
		$this->assertSame( array( 'id' => 'co-1', 'url' => 'https://checkout.sumup.com/pay/co-1' ), $r );
		$this->assertSame( array( 'POST', 'https://api.sumup.com/v0.1/checkouts', 'Bearer sup_sk_key' ), $seen[0] );

		$insecure = function () {
			return array( 'code' => 201, 'body' => '{"id":"co-2","hosted_checkout_url":"http://evil.example/pay"}' );
		};
		$this->expectException( \RuntimeException::class );
		SumUpApi::create_checkout( $insecure, 'k', $payload );
	}

	public function test_sumup_status_amounts_and_errors(): void {
		$this->assertTrue( SumUpApi::is_paid( array( 'status' => 'PAID' ) ) );
		$this->assertFalse( SumUpApi::is_paid( array( 'status' => 'PENDING' ) ) );
		$this->assertTrue( SumUpApi::is_failed( array( 'status' => 'FAILED' ) ) );
		$this->assertTrue( SumUpApi::is_failed( array( 'status' => 'EXPIRED' ) ) );
		$this->assertFalse( SumUpApi::is_failed( array( 'status' => 'PENDING' ) ) );
		$this->assertSame( 1041, SumUpApi::paid_eur_cents( array( 'amount' => 10.41, 'currency' => 'EUR' ) ) );
		$this->assertSame( 0, SumUpApi::paid_eur_cents( array( 'amount' => 10, 'currency' => 'USD' ) ), 'valuta diversa: da controllare a mano' );
		$this->assertSame( 'TX9', SumUpApi::transaction_id( array( 'transactions' => array( array( 'transaction_code' => 'TX9' ) ), 'id' => 'co' ) ) );
		$this->assertSame( 'co', SumUpApi::transaction_id( array( 'id' => 'co' ) ) );
	}

	public function test_sumup_connection_test(): void {
		$ok = function () {
			return array( 'code' => 200, 'body' => '{"merchant_profile":{"merchant_code":"MABCD123"}}' );
		};
		$this->assertTrue( Gateways::test( 'sumup', array( 'sumup_api_key' => 'k', 'sumup_merchant_code' => 'mabcd123' ), $ok )['ok'] );
		$other = Gateways::test( 'sumup', array( 'sumup_api_key' => 'k', 'sumup_merchant_code' => 'MZZZ9999' ), $ok );
		$this->assertFalse( $other['ok'] );
		$this->assertStringContainsString( 'MABCD123', $other['message'] );
		$denied = function () {
			return array( 'code' => 401, 'body' => '{}' );
		};
		$this->assertStringContainsString( 'non è valida', Gateways::test( 'sumup', array( 'sumup_api_key' => 'k' ), $denied )['message'] );
		$this->assertFalse( Gateways::test( 'sumup', array(), $ok )['ok'], 'senza chiave nessuna prova' );
	}
}
