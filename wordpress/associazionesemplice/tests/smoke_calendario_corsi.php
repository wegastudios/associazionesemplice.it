<?php
/**
 * Date delle lezioni calcolate in un posto solo (CourseCalendar): per ogni corso e ogni mese di due anni il risultato è identico ai calcoli di prima,
 * e calendario, registro presenze e quote dicono le stesse date. Lezioni spostate, annullate, a cavallo del nuovo anno.
 */

defined( 'ABSPATH' ) || exit;

use AssociazioneSemplice\ActivityReset;
use AssociazioneSemplice\ActivityService;
use AssociazioneSemplice\Calendar;
use AssociazioneSemplice\CourseCalendar;
use AssociazioneSemplice\Db;
use AssociazioneSemplice\LessonExceptions;
use AssociazioneSemplice\Plugin;
use AssociazioneSemplice\Settings;

require_once __DIR__ . '/legacy_course_dates.php';

global $wpdb;
$cc_acts = Plugin::activities();
$cc_year = Settings::social_year();
$cc_s0   = $cc_year->start()->format( 'Y-m-d' );
$cc_s1   = $cc_year->end()->format( 'Y-m-d' );
$cc_mk   = function ( string $name, array $slots, array $extra = array() ) use ( $cc_acts, $cc_year ): int {
	return (int) $cc_acts->create( array_merge( array( 'name' => $name, 'social_year' => $cc_year->label(), 'kind' => 'course', 'fee_cents' => 1000, 'lesson_slots' => $slots ), $extra ) );
};
$cc_wk = function ( int $day, string $start = '18:00' ): array {
	return array( 'type' => 'weekly', 'day' => $day, 'start' => $start, 'end' => '19:00' );
};
$cc_a = $cc_mk( 'Calendario, due volte a settimana', array( $cc_wk( 2 ), $cc_wk( 5 ) ) );
$cc_b = $cc_mk( 'Calendario, con date uniche', array( $cc_wk( 3 ), array( 'type' => 'single', 'date' => substr( $cc_s0, 0, 7 ) . '-15', 'start' => '10:00', 'end' => '11:00' ) ) );
$cc_d = $cc_mk( 'Calendario, con inizio e fine', array( $cc_wk( 4 ) ), array( 'starts_on' => gmdate( 'Y-m-d', strtotime( $cc_s0 . ' +40 days' ) ), 'ends_on' => gmdate( 'Y-m-d', strtotime( $cc_s1 . ' -20 days' ) ) ) );
// annullata, spostata nello stesso mese, spostata a un altro mese, ultima lezione del periodo spostata oltre la fine
$cc_l = CourseCalendar::dates( $cc_acts->get( $cc_a ), $cc_s0, $cc_s1 );
LessonExceptions::cancel( $cc_a, $cc_l[3] );
LessonExceptions::move( $cc_a, $cc_l[6], gmdate( 'Y-m-d', strtotime( $cc_l[6] . ' +1 day' ) ) );
LessonExceptions::move( $cc_a, $cc_l[10], gmdate( 'Y-m-d', strtotime( $cc_l[10] . ' +33 days' ) ) );
LessonExceptions::move( $cc_a, end( $cc_l ), gmdate( 'Y-m-d', strtotime( $cc_s1 . ' +2 days' ) ) );

$cc_bad = array();
$cc_n   = 0;
foreach ( $wpdb->get_results( 'SELECT * FROM ' . Db::t( 'activities' ) . " WHERE deleted_at IS NULL AND kind = 'course'", ARRAY_A ) as $cc_row ) {
	$cc_lessons = ActivityService::lessons( $cc_row );
	for ( $cc_m = 0; $cc_m < 26; $cc_m++ ) {
		$cc_f  = gmdate( 'Y-m-01', strtotime( $cc_s0 . ' ' . ( $cc_m - 1 ) . ' months' ) );
		$cc_t  = gmdate( 'Y-m-t', strtotime( $cc_f ) );
		$cc_ex = LessonExceptions::between( $cc_f, $cc_t, (int) $cc_row['id'] );
		$cc_n++;
		if ( asem_legacy_public_dates( $cc_lessons, $cc_row, $cc_ex, $cc_f, $cc_t ) !== CourseCalendar::dates( $cc_row, $cc_f, $cc_t ) || asem_legacy_reset_dates( $cc_lessons, $cc_row, $cc_ex, $cc_f, $cc_t ) !== ActivityReset::course_dates( $cc_row, $cc_f, $cc_t ) ) {
			$cc_bad[] = $cc_row['id'] . ' ' . $cc_f;
		}
		if ( class_exists( \AssociazioneSemplice\Attendance::class ) && \AssociazioneSemplice\Attendance::lesson_dates( $cc_row, $cc_f, $cc_t ) !== CourseCalendar::dates( $cc_row, $cc_f, $cc_t ) ) {
			$cc_bad[] = 'registro ' . $cc_row['id'] . ' ' . $cc_f;
		}
	}
}
asem_ok( $cc_n > 100 && ! $cc_bad, 'calendario corsi: date identiche ai calcoli di prima per ogni corso e mese (' . $cc_n . ' confronti)' . ( $cc_bad ? ': ' . implode( ', ', array_slice( $cc_bad, 0, 5 ) ) : '' ) );

// il calendario generale dà le stesse date, dentro l'anno sociale, per i corsi della prova
$cc_diff = array();
foreach ( array( $cc_a, $cc_b, $cc_d ) as $cc_id ) {
	for ( $cc_m = 0; $cc_m < 12; $cc_m++ ) {
		$cc_f = gmdate( 'Y-m-01', strtotime( $cc_s0 . ' ' . $cc_m . ' months' ) );
		$cc_t = gmdate( 'Y-m-t', strtotime( $cc_f ) );
		$cc_o = array();
		foreach ( Calendar::occurrences( $cc_f, $cc_t ) as $o ) {
			if ( 'course' === $o['kind'] && (int) $o['activity_id'] === $cc_id ) {
				$cc_o[] = (string) $o['date'];
			}
		}
		$cc_o = array_values( array_unique( $cc_o ) );
		sort( $cc_o );
		if ( $cc_o !== CourseCalendar::dates( $cc_acts->get( $cc_id ), $cc_f, $cc_t ) ) {
			$cc_diff[] = $cc_id . ' ' . $cc_f;
		}
	}
}
asem_ok( ! $cc_diff, 'calendario corsi: il calendario generale e le quote leggono le stesse date' . ( $cc_diff ? ': ' . implode( ', ', array_slice( $cc_diff, 0, 5 ) ) : '' ) );
