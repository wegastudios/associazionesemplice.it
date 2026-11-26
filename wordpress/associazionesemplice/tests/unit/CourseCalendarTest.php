<?php

use AssociazioneSemplice\CourseCalendar;
use AssociazioneSemplice\LessonExceptions;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/legacy_course_dates.php';

/** Le date delle lezioni in un posto solo: identiche ai calcoli di prima, anche con date strane. */
final class CourseCalendarTest extends TestCase {

	private function day( int $max = 800 ): string {
		return ( new \DateTimeImmutable( '2027-10-15' ) )->modify( '+' . mt_rand( 0, $max ) . ' days' )->format( 'Y-m-d' ); // comprende due capodanni e il 29 febbraio 2028
	}

	public function test_identical_to_the_previous_calculations(): void {
		mt_srand( 20261125 );
		$filled = 0;
		for ( $i = 0; $i < 4000; $i++ ) {
			$lessons = array();
			$n       = mt_rand( 0, 4 );
			for ( $k = 0; $k < $n; $k++ ) {
				$lessons[] = 0 === mt_rand( 0, 3 )
					? array( 'type' => 'single', 'date' => $this->day() )
					: array( 'type' => 'weekly', 'day' => mt_rand( 1, 7 ), 'from' => mt_rand( 0, 1 ) ? $this->day() : null, 'until' => mt_rand( 0, 1 ) ? $this->day() : null );
			}
			$a  = array( 'starts_on' => mt_rand( 0, 1 ) ? $this->day( 300 ) : null, 'ends_on' => mt_rand( 0, 1 ) ? $this->day() : null );
			$d  = array( $this->day(), $this->day() );
			sort( $d );
			list( $from, $to ) = $d;
			if ( 0 === $i % 3 ) { // un mese intero, come nelle quote
				$from = substr( $from, 0, 7 ) . '-01';
				$to   = ( new \DateTimeImmutable( $from ) )->format( 'Y-m-t' );
			}
			$base = CourseCalendar::expand( $lessons, $from, $to, $a['starts_on'], $a['ends_on'] );
			$ex   = array();
			foreach ( array_slice( $base, 0, mt_rand( 0, 4 ) ) as $bd ) {
				$ex[] = mt_rand( 0, 1 ) ? array( 'lesson_date' => $bd, 'action' => LessonExceptions::CANCEL, 'new_date' => null ) : array( 'lesson_date' => $bd, 'action' => LessonExceptions::MOVE, 'new_date' => $this->day() );
			}
			$new = LessonExceptions::adjust_dates( $base, $ex, $from, $to );
			$this->assertSame( asem_legacy_public_dates( $lessons, $a, $ex, $from, $to ), $new, "giro $i" );
			$legacy = array_values( array_filter( asem_legacy_reset_dates( $lessons, $a, $ex, $from, $to ), function ( $d ) use ( $from, $to ) {
				return $d >= $from && $d <= $to;
			} ) );
			$this->assertSame( $legacy, $new, "giro $i (rimborsi: come prima, senza le date fuori dal periodo)" );
			$filled += $new ? 1 : 0;
			foreach ( $lessons as $s ) {
				if ( 'weekly' === $s['type'] ) {
					$this->assertSame( asem_legacy_calendar_slot( $s, $from, $to, '2027-09-01', '2029-08-31' ), CourseCalendar::slot_dates( $s, $from, $to, '2027-09-01', '2029-08-31' ), "giro $i (calendario)" );
					$this->assertSame( asem_legacy_cancel_slot( $s, $from, $to ), CourseCalendar::slot_dates( $s, $from, $to ), "giro $i (annulla le successive)" );
				}
			}
		}
		$this->assertGreaterThan( 1500, $filled );
	}

	public function test_moved_across_the_new_year(): void {
		$l  = array( array( 'type' => 'weekly', 'day' => 4, 'from' => null, 'until' => null ) ); // giovedì
		$ex = array( array( 'lesson_date' => '2026-12-31', 'action' => LessonExceptions::MOVE, 'new_date' => '2027-01-02' ) );
		$dec = CourseCalendar::expand( $l, '2026-12-01', '2026-12-31' );
		$jan = CourseCalendar::expand( $l, '2027-01-01', '2027-01-31' );
		$this->assertSame( array( '2026-12-03', '2026-12-10', '2026-12-17', '2026-12-24' ), LessonExceptions::adjust_dates( $dec, $ex, '2026-12-01', '2026-12-31' ) );
		$this->assertSame( array( '2027-01-02', '2027-01-07', '2027-01-14', '2027-01-21', '2027-01-28' ), LessonExceptions::adjust_dates( $jan, $ex, '2027-01-01', '2027-01-31' ) );
	}
}
