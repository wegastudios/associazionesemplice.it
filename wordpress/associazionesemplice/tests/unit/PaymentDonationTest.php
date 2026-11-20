<?php
use AssociazioneSemplice\PaymentDonation;
use AssociazioneSemplice\PaymentItems;
use PHPUnit\Framework\TestCase;

/** Donazione aggiunta al pagamento: importi ammessi, voce a sé con ricevuta sua, sempre erogazione liberale. */
final class PaymentDonationTest extends TestCase {

	public function test_amounts_are_checked(): void {
		$this->assertSame( 500, PaymentDonation::parse_amount( '5' ) );
		$this->assertSame( 550, PaymentDonation::parse_amount( '5,50' ) );
		$this->assertSame( 100, PaymentDonation::parse_amount( '1' ) );
		$this->assertNull( PaymentDonation::parse_amount( '0,50' ) );
		$this->assertNull( PaymentDonation::parse_amount( '1000,01' ) );
		$this->assertNull( PaymentDonation::parse_amount( 'abc' ) );
		$this->assertSame( 100000, PaymentDonation::parse_amount( '1000' ) );
	}

	public function test_donation_has_its_own_receipt_batch(): void {
		$items = array(
			array( 'type' => PaymentItems::MEMBERSHIP, 'person_id' => 7, 'amount_cents' => 1000 ),
			array( 'type' => PaymentItems::DONATION, 'person_id' => 7, 'amount_cents' => 500 ),
			array( 'type' => PaymentItems::BOOKING, 'person_id' => 9, 'amount_cents' => 800 ),
		);
		$b = PaymentItems::receipt_batches( $items );
		$this->assertCount( 3, $b );
		$this->assertSame( 7, $b[0][0] );
		$this->assertSame( array( PaymentItems::MEMBERSHIP ), array_column( $b[0][1], 'type' ) );
		$this->assertSame( array( PaymentItems::DONATION ), array_column( $b[1][1], 'type' ) );
		$this->assertSame( 9, $b[2][0] );
	}

	public function test_the_added_donation_is_always_a_donation_item(): void {
		$this->assertTrue( PaymentItems::is_donation( array( 'type' => PaymentItems::DONATION ), array( 'type' => 'guest' ) ) );
		$this->assertSame( 'd:7:500', PaymentItems::key( array( 'type' => PaymentItems::DONATION, 'person_id' => 7, 'amount_cents' => 500 ) ) );
	}
}
