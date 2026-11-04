<?php
use AssociazioneSemplice\IncompleteCards;
use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value ) {
		return $value;
	}
}

final class IncompleteCardsTest extends TestCase {

	private function full( array $over = array() ): array {
		return $over + array( 'id' => 1, 'tax_code' => 'RSSMRA80A01H501Z', 'birth_date' => '1980-01-01', 'birth_place' => 'Roma', 'address' => 'Via Roma 1', 'city' => 'Roma', 'email' => 'a@example.com', 'phone' => '' );
	}

	public function test_complete_card_has_nothing_missing(): void {
		$this->assertSame( array(), IncompleteCards::missing( $this->full(), array(), '2026-10-29' ) );
	}

	public function test_missing_items_are_listed(): void {
		$m = IncompleteCards::missing( $this->full( array( 'tax_code' => '', 'birth_place' => '', 'address' => '', 'email' => '' ) ), array(), '2026-10-29' );
		$this->assertSame( array( 'codice fiscale', 'luogo di nascita', 'residenza', 'email o telefono' ), $m );
	}

	public function test_minor_needs_a_guardian_with_an_email(): void {
		$kid = $this->full( array( 'birth_date' => '2015-05-05', 'email' => '' ) );
		$this->assertSame( array( 'genitore o tutore' ), IncompleteCards::missing( $kid, array(), '2026-10-29' ) );
		$g = array( 'first_name' => 'Marta', 'last_name' => 'Madre', 'tax_code' => '', 'email' => '', 'is_operative' => 1 );
		$m = IncompleteCards::missing( $kid, array( $g ), '2026-10-29' );
		$this->assertContains( 'email del tutore che opera sul sito', $m );
		$this->assertContains( 'codice fiscale di Marta Madre', $m );
		$g['email']    = 'm@example.com';
		$g['tax_code'] = 'MDRMRT80A41H501Y';
		$this->assertSame( array(), IncompleteCards::missing( $kid, array( $g ), '2026-10-29' ) );
	}
}
