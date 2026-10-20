<?php
use AssociazioneSemplice\ResourceRules as R;
use PHPUnit\Framework\TestCase;

final class ResourceRulesTest extends TestCase {

	private function court(): array {
		return array( 'mode' => 'slot', 'open_time' => '08:00', 'close_time' => '22:00', 'slot_minutes' => 60, 'min_slots' => 1, 'max_slots' => 2, 'advance_days' => 14, 'fee_cents' => 1000, 'guest_fee_cents' => 200 );
	}

	private const NOW = '2026-10-19 10:00:00';

	public function test_overlap_of_two_ranges(): void {
		$this->assertTrue( R::overlaps( '2026-10-20 18:00:00', '2026-10-20 19:00:00', '2026-10-20 18:30:00', '2026-10-20 19:30:00' ) );
		$this->assertFalse( R::overlaps( '2026-10-20 18:00:00', '2026-10-20 19:00:00', '2026-10-20 19:00:00', '2026-10-20 20:00:00' ), 'fine = inizio: non si sovrappongono' );
		$this->assertTrue( R::overlaps( '2026-10-20 18:00:00', '2026-10-20 19:00:00', '2026-10-20 17:00:00', '2026-10-20 23:00:00' ), 'uno contiene l\'altro' );
	}

	public function test_open_ended_assignment_occupies_everything_after_it_starts(): void {
		$this->assertTrue( R::overlaps( '2026-01-01 00:00:00', null, '2030-05-05 00:00:00', '2030-05-06 00:00:00' ) );
		$this->assertFalse( R::overlaps( '2026-01-01 00:00:00', null, '2025-12-01 00:00:00', '2026-01-01 00:00:00' ) );
		$this->assertTrue( R::overlaps( '2026-01-01 00:00:00', null, '2026-06-01 00:00:00', null ) );
	}

	public function test_slot_range_computes_start_and_end(): void {
		$this->assertSame( array( '2026-10-20 18:00:00', '2026-10-20 20:00:00' ), R::slot_range( $this->court(), '2026-10-20', '18:00', 2, self::NOW ) );
	}

	public function test_slot_must_start_on_the_grid_and_end_before_closing(): void {
		$this->expectException( InvalidArgumentException::class );
		R::slot_range( $this->court(), '2026-10-20', '18:30', 1, self::NOW );
	}

	public function test_slot_cannot_pass_closing_time(): void {
		$this->expectException( InvalidArgumentException::class );
		R::slot_range( $this->court(), '2026-10-20', '21:00', 2, self::NOW );
	}

	public function test_last_slot_ending_exactly_at_closing_is_allowed(): void {
		$this->assertSame( array( '2026-10-20 21:00:00', '2026-10-20 22:00:00' ), R::slot_range( $this->court(), '2026-10-20', '21:00', 1, self::NOW ) );
	}

	public function test_number_of_slots_is_limited(): void {
		$this->expectException( InvalidArgumentException::class );
		R::slot_range( $this->court(), '2026-10-20', '10:00', 3, self::NOW );
	}

	public function test_past_slots_are_refused_to_members_but_not_to_staff(): void {
		try {
			R::slot_range( $this->court(), '2026-10-19', '09:00', 1, self::NOW );
			$this->fail( 'una fascia già iniziata non si prenota' );
		} catch ( InvalidArgumentException $e ) {
			$this->assertStringContainsString( 'iniziata', $e->getMessage() );
		}
		$this->assertSame( array( '2026-10-19 09:00:00', '2026-10-19 10:00:00' ), R::slot_range( $this->court(), '2026-10-19', '09:00', 1, self::NOW, true ) );
	}

	public function test_advance_limit(): void {
		$this->assertSame( '2026-11-02 18:00:00', R::slot_range( $this->court(), '2026-11-02', '18:00', 1, self::NOW )[0], '14 giorni esatti sono ammessi' );
		$this->expectException( InvalidArgumentException::class );
		R::slot_range( $this->court(), '2026-11-03', '18:00', 1, self::NOW );
	}

	public function test_staff_can_book_beyond_the_advance_limit(): void {
		$this->assertSame( '2027-01-10 18:00:00', R::slot_range( $this->court(), '2027-01-10', '18:00', 1, self::NOW, true )[0] );
	}

	public function test_invalid_dates_and_times_are_refused(): void {
		foreach ( array( array( '2026-13-40', '18:00' ), array( 'ieri', '18:00' ), array( '2026-10-20', '25:00' ), array( '2026-10-20', '' ) ) as $c ) {
			try {
				R::slot_range( $this->court(), $c[0], $c[1], 1, self::NOW );
				$this->fail( implode( ' ', $c ) );
			} catch ( InvalidArgumentException $e ) {
				$this->assertTrue( true );
			}
		}
	}

	public function test_guests_are_cleaned_and_limited(): void {
		$this->assertSame( array( 'Mario Rossi' ), R::guests( 2, array( '  Mario   Rossi ', '', '   ' ) ) );
		$this->assertSame( array(), R::guests( 0, array( '', ' ' ) ) );
	}

	public function test_resource_without_guests_refuses_them(): void {
		$this->expectException( InvalidArgumentException::class );
		R::guests( 0, array( 'Mario Rossi' ) );
	}

	public function test_too_many_guests_are_refused(): void {
		$this->expectException( InvalidArgumentException::class );
		R::guests( 1, array( 'A B', 'C D' ) );
	}

	public function test_fee_is_per_slot_plus_guest_supplement(): void {
		$this->assertSame( 1000, R::fee( $this->court(), 1, 0 ) );
		$this->assertSame( 2400, R::fee( $this->court(), 2, 2 ) );
	}

	public function test_assignment_fee_is_per_period_not_per_slot(): void {
		$this->assertSame( 12000, R::fee( array( 'mode' => 'assign', 'fee_cents' => 12000 ), 5, 0 ) );
	}

	private function rated(): array {
		return array_merge( $this->court(), array( 'rates' => json_encode( R::parse_rates( "feriali 18:00-22:00 15,00\nweekend 08:00-22:00 20" ) ) ) );
	}

	public function test_rates_are_parsed_from_the_form_text(): void {
		$this->assertSame(
			array( array( 'days' => 'weekdays', 'from' => '18:00', 'to' => '22:00', 'fee_cents' => 1500 ), array( 'days' => 'weekend', 'from' => '08:00', 'to' => '22:00', 'fee_cents' => 2000 ), array( 'days' => 'all', 'from' => '08:00', 'to' => '09:00', 'fee_cents' => 550 ) ),
			R::parse_rates( "feriali 18:00-22:00 15,00\n\nweekend 08:00 - 22:00 20\ntutti 08:00-09:00 5.5 €" )
		);
	}

	public function test_rates_text_round_trips(): void {
		$text = "feriali 18:00-22:00 15,00\nweekend 08:00-22:00 20,00";
		$this->assertSame( $text, R::rates_text( R::parse_rates( $text ) ) );
	}

	public function test_malformed_rates_are_refused(): void {
		foreach ( array( 'sempre 18:00-22:00 15', 'feriali 18:00 15', 'feriali 22:00-18:00 15', 'feriali 18:00-25:00 15', 'feriali 18:00-22:00 gratis' ) as $bad ) {
			try {
				R::parse_rates( $bad );
				$this->fail( $bad );
			} catch ( InvalidArgumentException $e ) {
				$this->assertStringContainsString( 'riga 1', $e->getMessage() );
			}
		}
	}

	public function test_band_fee_follows_day_and_hour(): void {
		$res = $this->rated();
		$this->assertSame( 1500, R::band_fee( $res, '2026-10-20 18:00:00' ), 'martedì sera' );
		$this->assertSame( 1000, R::band_fee( $res, '2026-10-20 10:00:00' ), 'martedì mattina: tariffa base' );
		$this->assertSame( 1000, R::band_fee( $res, '2026-10-20 22:00:00' ), 'l\'ora di fine non è compresa' );
		$this->assertSame( 2000, R::band_fee( $res, '2026-10-24 10:00:00' ), 'sabato' );
		$this->assertSame( 2000, R::band_fee( $res, '2026-10-25 21:00:00' ), 'domenica' );
	}

	public function test_without_bands_the_base_fee_applies(): void {
		$this->assertSame( 1000, R::band_fee( $this->court(), '2026-10-20 18:00:00' ) );
		$this->assertSame( 1000, R::band_fee( array_merge( $this->court(), array( 'rates' => 'non è json' ) ), '2026-10-20 18:00:00' ) );
	}

	public function test_each_slot_of_a_booking_has_its_own_rate(): void {
		$this->assertSame( 2500, R::slot_price( $this->rated(), '2026-10-20 17:00:00', 2 ), '17–18 base, 18–19 serale' );
		$this->assertSame( 3000, R::slot_price( $this->rated(), '2026-10-20 18:00:00', 2 ) );
	}

	public function test_fee_uses_bands_when_the_start_is_given(): void {
		$this->assertSame( 1500 + 200, R::fee( $this->rated(), 1, 1, '2026-10-20 18:00:00' ) );
		$this->assertSame( 1000, R::fee( $this->rated(), 1, 0 ), 'senza inizio vale la tariffa base' );
	}

	public function test_series_has_one_date_per_week(): void {
		$this->assertSame( array( '2026-10-20', '2026-10-27', '2026-11-03' ), R::series_dates( '2026-10-20', 3 ) );
		$this->assertSame( array( '2026-10-20' ), R::series_dates( '2026-10-20', 0 ) );
	}

	public function test_day_slots_follow_opening_hours(): void {
		$s = R::day_slots( array( 'open_time' => '08:00', 'close_time' => '10:30', 'slot_minutes' => 60 ) );
		$this->assertSame( array( array( 'start' => '08:00', 'end' => '09:00' ), array( 'start' => '09:00', 'end' => '10:00' ) ), $s, 'la mezz\'ora finale non fa una fascia intera' );
		$this->assertSame( array(), R::day_slots( array( 'slot_minutes' => 60 ) ) );
	}
}
