<?php
use AssociazioneSemplice\Ics;
use AssociazioneSemplice\Rules;
use PHPUnit\Framework\TestCase;

/** Una data di un evento può finire un altro giorno (gara di più giorni, serata oltre mezzanotte). */
final class EventDatesRulesTest extends TestCase {

	public function test_same_day_end_must_be_after_start(): void {
		$this->assertSame( array(), Rules::validate_session( array( 'session_date' => '2026-06-12', 'start_time' => '21:30', 'end_time' => '23:59' ) ) );
		$this->assertNotSame( array(), Rules::validate_session( array( 'session_date' => '2026-06-12', 'start_time' => '21:30', 'end_time' => '01:00' ) ) );
	}

	public function test_end_after_midnight_is_fine_with_an_end_date(): void {
		$this->assertSame( array(), Rules::validate_session( array( 'session_date' => '2026-06-12', 'end_date' => '2026-06-13', 'start_time' => '21:30', 'end_time' => '01:00' ) ) );
	}

	public function test_end_date_equal_to_start_is_the_same_as_none(): void {
		$this->assertNotSame( array(), Rules::validate_session( array( 'session_date' => '2026-06-12', 'end_date' => '2026-06-12', 'start_time' => '21:30', 'end_time' => '01:00' ) ) );
	}

	public function test_end_date_before_start_or_invalid_is_refused(): void {
		$this->assertNotSame( array(), Rules::validate_session( array( 'session_date' => '2026-06-12', 'end_date' => '2026-06-11' ) ) );
		$this->assertNotSame( array(), Rules::validate_session( array( 'session_date' => '2026-06-12', 'end_date' => '2026-02-30' ) ) );
	}

	public function test_a_date_cannot_last_more_than_31_days(): void {
		$this->assertSame( array(), Rules::validate_session( array( 'session_date' => '2026-06-01', 'end_date' => '2026-07-01' ) ) ); // 31 giorni
		$this->assertNotSame( array(), Rules::validate_session( array( 'session_date' => '2026-06-01', 'end_date' => '2026-07-02' ) ) );
		$this->assertNotSame( array(), Rules::validate_session( array( 'session_date' => '2026-06-12', 'end_date' => '2027-06-14' ) ) ); // anno sbagliato
	}

	public function test_ics_ends_on_the_end_date(): void {
		$ics = Ics::event( array( 'uid' => 'a@x', 'summary' => 'Regata', 'date' => '2026-06-12', 'end_date' => '2026-06-14', 'start' => '09:00', 'end' => '18:00' ), 'Europe/Rome', '20260101T000000Z' );
		$this->assertStringContainsString( "DTSTART:20260612T070000Z\r\n", $ics );
		$this->assertStringContainsString( "DTEND:20260614T160000Z\r\n", $ics );
		$night = Ics::event( array( 'uid' => 'b@x', 'summary' => 'Concerto', 'date' => '2026-06-12', 'end_date' => '2026-06-13', 'start' => '21:30', 'end' => '01:00' ), 'Europe/Rome', '20260101T000000Z' );
		$this->assertStringContainsString( "DTEND:20260612T230000Z\r\n", $night );
	}

	public function test_all_day_ics_covers_the_last_day(): void {
		$ics = Ics::event( array( 'uid' => 'c@x', 'summary' => 'Campo estivo', 'date' => '2026-07-01', 'end_date' => '2026-07-03' ), 'Europe/Rome', '20260101T000000Z' );
		$this->assertStringContainsString( "DTEND;VALUE=DATE:20260704\r\n", $ics );
	}
}
