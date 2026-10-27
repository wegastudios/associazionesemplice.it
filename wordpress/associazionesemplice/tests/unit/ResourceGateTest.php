<?php
use AssociazioneSemplice\ResourceGate as G;
use PHPUnit\Framework\TestCase;

final class ResourceGateTest extends TestCase {

	private const SECRET = 'segreto-di-prova';

	public function test_the_code_round_trips(): void {
		$param  = G::param( 42, 7, self::SECRET );
		$parsed = G::parse( $param );
		$this->assertSame( 42, $parsed[0] );
		$this->assertSame( 7, $parsed[1] );
		$this->assertTrue( G::valid( $parsed[0], $parsed[1], $parsed[2], self::SECRET ) );
	}

	public function test_a_code_for_another_reservation_or_person_is_not_valid(): void {
		$sig = G::make( 42, 7, self::SECRET );
		$this->assertFalse( G::valid( 43, 7, $sig, self::SECRET ) );
		$this->assertFalse( G::valid( 42, 8, $sig, self::SECRET ) );
		$this->assertFalse( G::valid( 42, 7, $sig, 'altro-segreto' ) );
		$this->assertFalse( G::valid( 42, 7, '', self::SECRET ) );
	}

	public function test_malformed_codes_are_refused(): void {
		foreach ( array( '', '42', '42.7', '42.7.xyz', '42.7.' . str_repeat( 'g', 20 ), 'a.b.' . str_repeat( 'a', 20 ), '42.7.' . str_repeat( 'a', 19 ), '42.7.' . str_repeat( 'a', 21 ) ) as $bad ) {
			$this->assertNull( G::parse( $bad ), $bad );
		}
		$this->assertNotNull( G::parse( ' 42.7.' . str_repeat( 'a', 20 ) . ' ' ), 'gli spazi ai lati non contano' );
	}

	public function test_codes_do_not_collide_with_other_tokens(): void {
		$this->assertNotSame( G::make( 5, 5, self::SECRET ), \AssociazioneSemplice\CardToken::make( 5, self::SECRET ) );
	}

	public function test_a_cancelled_reservation_never_gets_in(): void {
		$this->assertSame( 'cancelled', G::state( 'cancelled', '2026-10-20 18:00:00', '2026-10-20 19:00:00', null, '2026-10-20 18:30:00' ) );
		$this->assertSame( 'cancelled', G::state( 'cancelled', '2026-10-20 18:00:00', '2026-10-20 19:00:00', '2026-10-20 18:05:00', '2026-10-20 18:30:00' ) );
	}

	public function test_it_is_too_early_more_than_half_an_hour_before(): void {
		$this->assertSame( 'early', G::state( 'booked', '2026-10-20 18:00:00', '2026-10-20 19:00:00', null, '2026-10-20 17:29:59' ) );
		$this->assertSame( 'ok', G::state( 'booked', '2026-10-20 18:00:00', '2026-10-20 19:00:00', null, '2026-10-20 17:30:00' ) );
	}

	public function test_it_is_valid_until_the_end_of_the_slot(): void {
		$this->assertSame( 'ok', G::state( 'booked', '2026-10-20 18:00:00', '2026-10-20 19:00:00', null, '2026-10-20 18:45:00' ) );
		$this->assertSame( 'ok', G::state( 'booked', '2026-10-20 18:00:00', '2026-10-20 19:00:00', null, '2026-10-20 19:00:00' ) );
		$this->assertSame( 'late', G::state( 'booked', '2026-10-20 18:00:00', '2026-10-20 19:00:00', null, '2026-10-20 19:00:01' ) );
	}

	public function test_it_works_once(): void {
		$this->assertSame( 'used', G::state( 'booked', '2026-10-20 18:00:00', '2026-10-20 19:00:00', '2026-10-20 17:50:00', '2026-10-20 18:10:00' ) );
	}

	public function test_without_an_end_it_is_never_late(): void {
		$this->assertSame( 'ok', G::state( 'booked', '2026-10-20 18:00:00', null, null, '2026-10-21 03:00:00' ) );
	}

	public function test_every_state_has_a_label(): void {
		foreach ( array( 'ok', 'used', 'early', 'late', 'cancelled', 'invalid' ) as $s ) {
			$this->assertNotSame( '', G::label( $s ), $s );
		}
		$this->assertSame( 'Prenotazione valida', G::label( 'ok' ) );
	}

	public function test_the_name_shown_to_everyone_is_shortened(): void {
		$this->assertSame( 'Mario R.', G::short_name( 'Mario', 'Rossi' ) );
		$this->assertSame( 'Élodie Ö.', G::short_name( 'Élodie', 'östberg' ) );
		$this->assertSame( 'Mario', G::short_name( 'Mario', '' ) );
	}
}
