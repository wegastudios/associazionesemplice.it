<?php
/** I calcoli delle date delle lezioni com'erano prima di CourseCalendar: servono solo a dimostrare che i risultati sono identici. */

use AssociazioneSemplice\LessonExceptions;
use AssociazioneSemplice\Schedule;

if ( ! function_exists( 'asem_legacy_reset_dates' ) ) {

	/** ActivityReset::course_dates di prima. */
	function asem_legacy_reset_dates( array $lessons, array $a, array $ex, string $from, string $to ): array {
		$dates = array();
		foreach ( $lessons as $s ) {
			if ( 'single' === $s['type'] ) {
				$dates[] = (string) $s['date'];
				continue;
			}
			$lo = max( $from, (string) ( $s['from'] ?: ( $a['starts_on'] ?: $from ) ) );
			$hi = min( $to, (string) ( $s['until'] ?: ( $a['ends_on'] ?: $to ) ) );
			if ( $lo > $hi ) {
				continue;
			}
			foreach ( Schedule::weekly_dates( $lo, $hi, (int) $s['day'] ) as $d ) {
				$dates[] = $d;
			}
		}
		return LessonExceptions::adjust_dates( array_values( array_unique( $dates ) ), $ex, $from, $to );
	}

	/** PublicEvents::course_dates e Attendance::lesson_dates di prima. */
	function asem_legacy_public_dates( array $lessons, array $a, array $ex, string $from, string $to ): array {
		$dates = array();
		foreach ( $lessons as $s ) {
			if ( 'single' === $s['type'] ) {
				if ( $s['date'] >= $from && $s['date'] <= $to ) {
					$dates[] = $s['date'];
				}
				continue;
			}
			$lo = max( $from, (string) ( $s['from'] ?: ( $a['starts_on'] ?: $from ) ) );
			$hi = min( $to, (string) ( $s['until'] ?: ( $a['ends_on'] ?: $to ) ) );
			foreach ( Schedule::weekly_dates( $lo, $hi, (int) $s['day'] ) as $d ) {
				$dates[] = $d;
			}
		}
		return LessonExceptions::adjust_dates( array_values( array_unique( $dates ) ), $ex, $from, $to );
	}

	/** La ripetizione settimanale di Calendar di prima (inizio e fine del corso sempre presenti). */
	function asem_legacy_calendar_slot( array $slot, string $from, string $to, string $base0, string $base1 ): array {
		$out   = array();
		$first = max( $from, $slot['from'] ?: $base0 );
		$last  = min( $to, $slot['until'] ?: $base1 );
		if ( $first > $last || (int) $slot['day'] < 1 || (int) $slot['day'] > 7 ) {
			return $out;
		}
		$d   = new \DateTimeImmutable( $first );
		$d   = $d->modify( '+' . ( ( (int) $slot['day'] - (int) $d->format( 'N' ) + 7 ) % 7 ) . ' days' );
		$end = new \DateTimeImmutable( $last );
		while ( $d <= $end ) {
			$out[] = $d->format( 'Y-m-d' );
			$d     = $d->modify( '+7 days' );
		}
		return $out;
	}

	/** La ripetizione di LessonExceptions::cancel_from di prima. */
	function asem_legacy_cancel_slot( array $s, string $date, string $last ): array {
		$lo = max( $date, (string) ( $s['from'] ?: $date ) );
		$hi = min( $last, (string) ( $s['until'] ?: $last ) );
		return Schedule::weekly_dates( $lo, $hi, (int) $s['day'] );
	}
}
