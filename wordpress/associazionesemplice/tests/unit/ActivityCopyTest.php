<?php
use AssociazioneSemplice\ActivityCopy as A;
use AssociazioneSemplice\TribeImport as T;
use PHPUnit\Framework\TestCase;

final class ActivityCopyTest extends TestCase {

	public function test_dates_shift_by_days(): void {
		$this->assertSame( '2026-10-27', A::shift_date( '2026-10-20', 7 ) );
		$this->assertSame( '2026-10-13', A::shift_date( '2026-10-20', -7 ) );
		$this->assertSame( '2027-03-01', A::shift_date( '2027-02-28', 1 ) );
	}

	public function test_shift_is_a_whole_number_of_weeks_so_weekdays_stay_the_same(): void {
		$this->assertSame( 364, A::shift_days( '2025-09-01', '2026-09-01' ) );
		$this->assertSame( 371, A::shift_days( '2025-09-01', '2026-09-07' ) );
		$this->assertSame( -364, A::shift_days( '2026-09-01', '2025-09-01' ) );
		$this->assertSame( 0, A::shift_days( '2026-09-01', '2026-09-01' ) );
	}

	public function test_suggested_date_is_the_same_weekday_next_year(): void {
		$this->assertSame( '2026-10-20', A::suggest_date( '2025-10-21', '2026-10-20' ) );
		$this->assertSame( 'Tue', ( new DateTimeImmutable( A::suggest_date( '2025-10-21', '2026-10-20' ) ) )->format( 'D' ) );
	}

	public function test_an_old_event_is_proposed_on_its_weekday_in_a_week_or_more(): void {
		$d = A::suggest_date( '2024-03-05', '2026-10-20' );
		$this->assertSame( '2026-10-27', $d );
		$this->assertSame( ( new DateTimeImmutable( '2024-03-05' ) )->format( 'D' ), ( new DateTimeImmutable( $d ) )->format( 'D' ) );
		$this->assertGreaterThanOrEqual( '2026-10-27', $d );
	}

	public function test_lessons_shift_with_their_dates(): void {
		$lessons = array(
			array( 'type' => 'weekly', 'day' => 2, 'start' => '18:00', 'end' => '19:00', 'from' => '2025-09-09', 'until' => '2026-06-09' ),
			array( 'type' => 'weekly', 'day' => 4, 'start' => '20:00', 'end' => null, 'from' => null, 'until' => null ),
			array( 'type' => 'single', 'date' => '2026-01-15', 'start' => '10:00', 'end' => null ),
		);
		$out = A::shift_lessons( $lessons, 364 );
		$this->assertSame( '2026-09-08', $out[0]['from'] );
		$this->assertSame( '2027-06-08', $out[0]['until'] );
		$this->assertNull( $out[1]['from'] );
		$this->assertNull( $out[1]['until'] );
		$this->assertSame( '2027-01-14', $out[2]['date'] );
		$this->assertSame( 2, $out[0]['day'], 'il giorno della settimana non cambia' );
		$this->assertSame( ( new DateTimeImmutable( '2025-09-09' ) )->format( 'N' ), ( new DateTimeImmutable( $out[0]['from'] ) )->format( 'N' ) );
	}

	// ---------- The Events Calendar ----------

	public function test_a_timed_event_keeps_its_hours(): void {
		$m = T::map_event( array( '_EventStartDate' => '2026-10-20 18:30:00', '_EventEndDate' => '2026-10-20 20:00:00', '_EventAllDay' => '' ), 'Serata giochi', '', '' );
		$this->assertSame( 'Serata giochi', $m['name'] );
		$this->assertSame( array( 'session_date' => '2026-10-20', 'start_time' => '18:30', 'end_time' => '20:00', 'location' => null ), $m['session'] );
		$this->assertNull( $m['notes'] );
	}

	public function test_an_all_day_event_has_no_hours(): void {
		$m = T::map_event( array( '_EventStartDate' => '2026-10-20 00:00:00', '_EventEndDate' => '2026-10-20 23:59:59', '_EventAllDay' => 'yes' ), 'Sagra', '', '' );
		$this->assertNull( $m['session']['start_time'] );
		$this->assertNull( $m['session']['end_time'] );
	}

	public function test_a_multi_day_event_says_so_in_the_notes(): void {
		$m = T::map_event( array( '_EventStartDate' => '2026-10-20 09:00:00', '_EventEndDate' => '2026-10-22 18:00:00' ), 'Gita', '', '' );
		$this->assertStringContainsString( 'Dal 20/10/2026 al 22/10/2026.', $m['notes'] );
		$this->assertNull( $m['session']['end_time'] );
	}

	public function test_description_becomes_plain_text_and_the_site_is_kept(): void {
		$m = T::map_event( array( '_EventStartDate' => '2026-10-20 18:00:00', '_EventURL' => 'https://esempio.it/x' ), 'Festa &amp; musica', '<p>Ciao <b>mondo</b></p><p>Seconda&nbsp;riga &amp; fine</p>', 'Sala Rossa, Via Roma 1' );
		$this->assertSame( 'Festa & musica', $m['name'] );
		$this->assertStringContainsString( "Ciao mondo\nSeconda", $m['notes'] );
		$this->assertStringContainsString( '& fine', $m['notes'] );
		$this->assertStringContainsString( 'Sito: https://esempio.it/x', $m['notes'] );
		$this->assertStringNotContainsString( '<', $m['notes'] );
		$this->assertSame( 'Sala Rossa, Via Roma 1', $m['session']['location'] );
	}

	private function lesson( string $date, ?string $start = '18:00', ?string $end = '19:00', string $notes = '', string $venue = '' ): array {
		return array( 'date' => $date, 'start' => $start, 'end' => $end, 'name' => 'Yoga', 'notes' => $notes, 'venue' => $venue );
	}

	public function test_weekly_lessons_become_one_course(): void {
		$c = T::map_course( array( $this->lesson( '2026-09-15' ), $this->lesson( '2026-09-22' ), $this->lesson( '2026-09-29' ), $this->lesson( '2026-10-06' ) ) );
		$this->assertSame( 'Yoga', $c['name'] );
		$this->assertSame( '2026-09-15', $c['starts_on'] );
		$this->assertSame( '2026-10-06', $c['ends_on'] );
		$this->assertSame( array( array( 'type' => 'weekly', 'day' => 2, 'start' => '18:00', 'end' => '19:00', 'from' => '2026-09-15', 'until' => '2026-10-06' ) ), $c['lessons'] );
	}

	public function test_two_weekly_times_make_two_lessons(): void {
		$items = array();
		foreach ( array( '2026-09-15', '2026-09-22', '2026-09-29' ) as $d ) {
			$items[] = $this->lesson( $d, '18:00', '19:00' );
		}
		foreach ( array( '2026-09-17', '2026-09-24', '2026-10-01' ) as $d ) {
			$items[] = $this->lesson( $d, '20:00', '21:00' );
		}
		$c = T::map_course( $items );
		$this->assertCount( 2, $c['lessons'] );
		$this->assertSame( array( 2, 4 ), array_column( $c['lessons'], 'day' ) );
		$this->assertSame( '2026-10-01', $c['ends_on'] );
	}

	public function test_a_day_that_repeats_less_than_three_times_stays_a_single_date(): void {
		$c = T::map_course( array( $this->lesson( '2026-09-15' ), $this->lesson( '2026-09-22' ), $this->lesson( '2026-09-29' ), $this->lesson( '2026-09-30', '10:00', null ) ) );
		$this->assertCount( 2, $c['lessons'] );
		$this->assertSame( 'single', $c['lessons'][1]['type'] );
		$this->assertSame( '2026-09-30', $c['lessons'][1]['date'] );
		$this->assertSame( '10:00', $c['lessons'][1]['start'] );
		$this->assertNull( $c['lessons'][1]['end'] );
	}

	public function test_course_keeps_the_longest_description_and_the_first_venue(): void {
		$c = T::map_course( array( $this->lesson( '2026-09-15', '18:00', '19:00', 'Breve', '' ), $this->lesson( '2026-09-22', '18:00', '19:00', 'Una descrizione più lunga', 'Palestra' ), $this->lesson( '2026-09-29', '18:00', '19:00', '', 'Altra sala' ) ) );
		$this->assertSame( 'Una descrizione più lunga', $c['notes'] );
		$this->assertSame( 'Palestra', $c['location'] );
	}

	public function test_lessons_given_out_of_order_are_sorted(): void {
		$c = T::map_course( array( $this->lesson( '2026-09-29' ), $this->lesson( '2026-09-15' ), $this->lesson( '2026-09-22' ) ) );
		$this->assertSame( '2026-09-15', $c['starts_on'] );
		$this->assertSame( '2026-09-29', $c['ends_on'] );
	}

	public function test_no_lessons_no_course(): void {
		$this->assertNull( T::map_course( array() ) );
	}

	public function test_an_event_without_a_valid_date_is_not_imported(): void {
		$this->assertNull( T::map_event( array(), 'Senza data', '', '' ) );
		$this->assertNull( T::map_event( array( '_EventStartDate' => 'ieri' ), 'Data strana', '', '' ) );
	}

	public function test_an_untitled_event_gets_a_name(): void {
		$m = T::map_event( array( '_EventStartDate' => '2026-10-20 18:00:00' ), '   ', '', '' );
		$this->assertSame( 'Evento senza titolo', $m['name'] );
	}
}
