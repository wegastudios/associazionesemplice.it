<?php
use AssociazioneSemplice\CreditRules as C;
use AssociazioneSemplice\PaymentItems;
use AssociazioneSemplice\ResourceRules as R;
use PHPUnit\Framework\TestCase;

final class CreditRulesTest extends TestCase {

	public function test_packs_are_parsed_and_sorted_by_credits(): void {
		$this->assertSame(
			array( array( 'credits' => 10, 'price_cents' => 1000 ), array( 'credits' => 50, 'price_cents' => 4500 ), array( 'credits' => 100, 'price_cents' => 8000 ) ),
			C::parse_packs( "50 = 45,00\n\n10 crediti: 10\n100 = 80.00 €" )
		);
	}

	public function test_packs_text_round_trips(): void {
		$text = "10 = 10,00\n50 = 45,50";
		$this->assertSame( $text, C::packs_text( C::parse_packs( $text ) ) );
	}

	public function test_malformed_packs_are_refused(): void {
		foreach ( array( 'dieci = 10', '10', '10 = gratis', '0 = 5', '10 = 0', '10 = 5,555', '-3 = 5' ) as $bad ) {
			try {
				C::parse_packs( $bad );
				$this->fail( $bad );
			} catch ( InvalidArgumentException $e ) {
				$this->assertStringContainsString( 'riga 1', $e->getMessage(), $bad );
			}
		}
	}

	public function test_two_packs_with_the_same_credits_are_refused(): void {
		$this->expectException( InvalidArgumentException::class );
		C::parse_packs( "10 = 10\n10 = 9" );
	}

	public function test_at_most_twelve_packs(): void {
		$lines = array();
		for ( $i = 1; $i <= 13; $i++ ) {
			$lines[] = $i . ' = ' . $i;
		}
		$this->expectException( InvalidArgumentException::class );
		C::parse_packs( implode( "\n", $lines ) );
	}

	public function test_stored_packs_are_cleaned(): void {
		$this->assertSame( array( array( 'credits' => 5, 'price_cents' => 500 ) ), C::clean_packs( json_encode( array( array( 'credits' => 5, 'price_cents' => 500 ), array( 'credits' => 0, 'price_cents' => 100 ), array( 'x' => 1 ) ) ) ) );
		$this->assertSame( array(), C::clean_packs( 'non è json' ) );
		$this->assertSame( array(), C::clean_packs( '' ) );
	}

	public function test_a_pending_charge_is_valid_until_it_expires(): void {
		$c = array( 'status' => 'pending', 'expires_at' => '2026-10-20 12:05:00' );
		$this->assertSame( 'ok', C::charge_state( $c, '2026-10-20 12:00:00' ) );
		$this->assertSame( 'ok', C::charge_state( $c, '2026-10-20 12:05:00' ), 'fino all\'ultimo secondo' );
		$this->assertSame( 'expired', C::charge_state( $c, '2026-10-20 12:05:01' ) );
	}

	public function test_paid_and_cancelled_charges_are_never_payable(): void {
		$this->assertSame( 'paid', C::charge_state( array( 'status' => 'paid', 'expires_at' => '2099-01-01 00:00:00' ), '2026-10-20 12:00:00' ) );
		$this->assertSame( 'cancelled', C::charge_state( array( 'status' => 'cancelled', 'expires_at' => '2099-01-01 00:00:00' ), '2026-10-20 12:00:00' ) );
	}

	public function test_each_unavailable_state_has_a_message(): void {
		foreach ( array( 'paid', 'cancelled', 'expired' ) as $s ) {
			$this->assertNotSame( '', C::charge_message( $s ), $s );
		}
		$this->assertSame( '', C::charge_message( 'ok' ) );
	}

	public function test_cost_is_per_unit(): void {
		$this->assertSame( 6, C::cost( 3, 2 ) );
		$this->assertSame( 3, C::cost( 3, 0 ), 'almeno una unità' );
		$this->assertSame( 0, C::cost( -2, 4 ) );
	}

	public function test_charge_codes_are_24_hex_characters(): void {
		$this->assertTrue( C::is_code( str_repeat( 'a1', 12 ) ) );
		$this->assertFalse( C::is_code( str_repeat( 'a1', 11 ) ) );
		$this->assertFalse( C::is_code( str_repeat( 'A1', 12 ) ) );
		$this->assertFalse( C::is_code( str_repeat( 'g1', 12 ) ) );
		$this->assertFalse( C::is_code( '' ) );
	}

	public function test_credit_pack_payment_keys_round_trip(): void {
		$key = PaymentItems::key( array( 'type' => 'credits', 'person_id' => 7, 'credits' => 50 ) );
		$this->assertSame( 'c:7:50', $key );
		$this->assertSame( array( 'type' => 'credits', 'person_id' => 7, 'credits' => 50 ), PaymentItems::parse( $key ) );
		$this->assertNull( PaymentItems::parse( 'c:7' ) );
		$this->assertNull( PaymentItems::parse( 'c:x:5' ) );
	}

	public function test_credits_are_an_extra_way_to_pay_a_resource(): void {
		$this->assertSame( array( 'credits', 'desk' ), R::pay_options( R::PAY_DESK, true, 1000, 3 ) );
		$this->assertSame( array( 'credits', 'online', 'desk' ), R::pay_options( R::PAY_CHOICE, true, 1000, 3 ) );
		$this->assertSame( array( 'credits' ), R::pay_options( R::PAY_UPFRONT, false, 1000, 3 ), 'con i crediti si prenota anche senza pagamenti online' );
		$this->assertSame( array(), R::pay_options( R::PAY_UPFRONT, false, 1000, 0 ) );
		$this->assertSame( array( 'free' ), R::pay_options( R::PAY_UPFRONT, true, 0, 3 ), 'gratuita: niente da pagare, nemmeno in crediti' );
	}
}
