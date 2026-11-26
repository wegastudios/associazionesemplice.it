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
		if ( asem_legacy_public_dates( $cc_lessons, $cc_row, $cc_ex, $cc_f, $cc_t ) !== CourseCalendar::dates( $cc_row, $cc_f, $cc_t ) || CourseCalendar::dates( $cc_row, $cc_f, $cc_t ) !== ActivityReset::course_dates( $cc_row, $cc_f, $cc_t ) ) {
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

// rimborso di un mese interrotto con date uniche in altri mesi: contano solo le lezioni di quel mese (prima contavano tutte le date uniche del corso)
$cc_sg = function ( string $d ): array {
	return array( 'type' => 'single', 'date' => $d, 'start' => '18:00', 'end' => '19:00' );
};
$cc_m1 = gmdate( 'Y-m', strtotime( $cc_s0 . ' +1 month' ) );
$cc_m2 = gmdate( 'Y-m', strtotime( $cc_s0 . ' +2 months' ) );
$cc_r  = $cc_mk( 'Rimborso, date uniche', array( $cc_sg( $cc_m1 . '-05' ), $cc_sg( $cc_m1 . '-12' ), $cc_sg( $cc_m1 . '-19' ), $cc_sg( $cc_m1 . '-26' ), $cc_sg( $cc_m2 . '-03' ), $cc_sg( $cc_m2 . '-10' ) ) );
$cc_c  = array( 'today' => $cc_m1 . '-13', 'now' => '10:00', 'held' => array( $cc_m1 . '-05', $cc_m1 . '-12' ), 'sessions' => array(), 'course' => $cc_acts->get( $cc_r ) );
$cc_v  = array( 'amount_cents' => 4000, 'session_id' => 0, 'competence_month' => $cc_m1, 'person_id' => 0 );
asem_ok( 2000 === ActivityReset::refund_cents( $cc_v, $cc_c ), 'rimborso: due lezioni su quattro, anche se il corso ha date uniche in altri mesi (prima 26,67)' );
$cc_one = $cc_mk( 'Rimborso, date uniche in un mese solo', array( $cc_sg( $cc_m1 . '-05' ), $cc_sg( $cc_m1 . '-12' ) ) );
asem_ok( 2000 === ActivityReset::refund_cents( $cc_v, array( 'held' => array( $cc_m1 . '-05' ), 'today' => $cc_m1 . '-06', 'course' => $cc_acts->get( $cc_one ) ) + $cc_c ), 'rimborso: con le date uniche in un mese solo come prima' );
$cc_w  = $cc_mk( 'Rimborso, settimanale', array( $cc_wk( 3 ) ) );
$cc_wd = CourseCalendar::dates( $cc_acts->get( $cc_w ), $cc_m1 . '-01', gmdate( 'Y-m-t', strtotime( $cc_m1 . '-01' ) ) );
asem_ok( (int) round( 4000 * ( count( $cc_wd ) - 1 ) / count( $cc_wd ) ) === ActivityReset::refund_cents( $cc_v, array( 'held' => array( $cc_wd[0] ), 'today' => gmdate( 'Y-m-d', strtotime( $cc_wd[0] . ' +1 day' ) ), 'course' => $cc_acts->get( $cc_w ) ) + $cc_c ), 'rimborso: settimanale come prima' );
