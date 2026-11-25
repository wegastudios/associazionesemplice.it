<?php
/**
 * Giorni senza lezione (festività nazionali, patrono, chiusure): spenti per i corsi esistenti, per scelta del corso; calendario, quote e registro
 * leggono le stesse date; una lezione con le presenze o confermata a mano resta.
 */

defined( 'ABSPATH' ) || exit;

use AssociazioneSemplice\ActivityReset;
use AssociazioneSemplice\Calendar;
use AssociazioneSemplice\CourseCalendar;
use AssociazioneSemplice\Db;
use AssociazioneSemplice\LessonExceptions;
use AssociazioneSemplice\Plugin;
use AssociazioneSemplice\RedDays;
use AssociazioneSemplice\Settings;

global $wpdb;
$rr_acts = Plugin::activities();
asem_ok( 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Db::t( 'activities' ) . ' WHERE red_days <> 0' ), 'giorni rossi: spenti per tutti i corsi che c\'erano già' );
$rr_year = Settings::social_year( '2031-06-01' );
$rr_mk   = function ( string $name, array $extra = array() ) use ( $rr_acts, $rr_year ): int {
	return (int) $rr_acts->create( array_merge( array( 'name' => $name, 'social_year' => $rr_year->label(), 'kind' => 'course', 'fee_cents' => 3000, 'lesson_slots' => array( array( 'type' => 'weekly', 'day' => 1, 'start' => '18:00', 'end' => '19:00' ) ) ), $extra ) );
};
$rr_off = $rr_mk( 'Lunedì con festivi' );
$rr_on  = $rr_mk( 'Lunedì senza festivi', array( 'red_days' => 1 ) );
asem_ok( 0 === (int) $rr_acts->get( $rr_off )['red_days'] && 1 === (int) $rr_acts->get( $rr_on )['red_days'], 'giorni rossi: la scelta è del corso, di partenza spenta' );
// aprile 2031: Pasqua il 13, Lunedì dell'Angelo il 14 (lunedì)
$rr_d = function ( int $id ) use ( $rr_acts ): array {
	return CourseCalendar::dates( $rr_acts->get( $id ), '2031-04-01', '2031-04-30' );
};
asem_ok( array( '2031-04-07', '2031-04-14', '2031-04-21', '2031-04-28' ) === $rr_d( $rr_off ) && array( '2031-04-07', '2031-04-21', '2031-04-28' ) === $rr_d( $rr_on ), 'giorni rossi: il Lunedì dell\'Angelo salta solo per il corso che lo sceglie' );
Settings::update( array( 'patron_day' => '21/04', 'closures' => "27/04/2031 - 29/04/2031 Ponte" ) );
asem_ok( '04-21' === Settings::get( 'patron_day' ) && array( '2031-04-07' ) === $rr_d( $rr_on ) && 4 === count( $rr_d( $rr_off ) ), 'giorni rossi: patrono e chiusure' );
asem_ok( '' !== (string) asem_throws( function () { Settings::update( array( 'closures' => 'Natale' ) ); } ) && '27/04/2031 - 29/04/2031 Ponte' === Settings::get( 'closures' ), 'giorni rossi: una riga sbagliata si rifiuta e non cambia nulla' );
$rr_occ = function ( int $id ): array {
	$out = array();
	foreach ( Calendar::occurrences( '2031-04-01', '2031-04-30' ) as $o ) {
		if ( 'course' === $o['kind'] && (int) $o['activity_id'] === $id ) {
			$out[] = (string) $o['date'];
		}
	}
	sort( $out );
	return $out;
};
asem_ok( $rr_d( $rr_on ) === $rr_occ( $rr_on ) && $rr_d( $rr_off ) === $rr_occ( $rr_off ) && $rr_d( $rr_on ) === ActivityReset::course_dates( $rr_acts->get( $rr_on ), '2031-04-01', '2031-04-30' ), 'giorni rossi: calendario, quote e rimborsi leggono le stesse date' );
asem_ok( array( '2031-04-14', '2031-04-21', '2031-04-28' ) === array_keys( CourseCalendar::red_lessons( $rr_acts->get( $rr_on ), '2031-04-01', '2031-04-30' ) ) && array() === CourseCalendar::red_lessons( $rr_acts->get( $rr_off ), '2031-04-01', '2031-04-30' ), 'giorni rossi: l\'elenco delle lezioni saltate' );
// confermata a mano, poi rimessa; annullata su un giorno confermato
LessonExceptions::keep( $rr_on, '2031-04-14' );
asem_ok( in_array( '2031-04-14', $rr_d( $rr_on ), true ) && $rr_d( $rr_on ) === $rr_occ( $rr_on ), 'giorni rossi: «Tieni la lezione» la rimette in calendario' );
LessonExceptions::restore( $rr_on, '2031-04-14' );
asem_ok( ! in_array( '2031-04-14', $rr_d( $rr_on ), true ), 'giorni rossi: ripristinata, salta di nuovo' );
// con le presenze segnate resta, anche se il giorno diventa festivo dopo
if ( \AssociazioneSemplice\Install::table_exists( Db::t( 'attendance' ) ) ) {
	$rr_p = (int) Plugin::people()->create( array( 'type' => 'ordinary', 'card_number' => '9931', 'first_name' => 'Rita', 'last_name' => 'Presente', 'birth_date' => '1980-01-01' ) );
	$wpdb->insert( Db::t( 'attendance' ), array( 'activity_id' => $rr_on, 'person_id' => $rr_p, 'lesson_date' => '2031-04-21', 'present' => 1, 'marked_at' => Db::now() ) );
	asem_ok( in_array( '2031-04-21', $rr_d( $rr_on ), true ), 'giorni rossi: una lezione con le presenze resta' );
}
// una data unica scritta dalla segreteria resta anche se festiva
$rr_one = $rr_mk( 'Data unica festiva', array( 'red_days' => 1, 'lesson_slots' => array( array( 'type' => 'single', 'date' => '2031-04-25', 'start' => '18:00', 'end' => '19:00' ) ) ) );
asem_ok( array( '2031-04-25' ) === $rr_d( $rr_one ), 'giorni rossi: una data unica resta' );
// modulo del corso e conferma dal pannello
$rr_m = new \ReflectionMethod( \AssociazioneSemplice\Admin\Actions::class, 'lesson_exception' );
$rr_m->setAccessible( true );
$rr_m->invoke( null, array( 'activity_id' => $rr_on, 'date' => '2031-04-28', 'mode' => 'keep' ) );
asem_ok( in_array( '2031-04-28', $rr_d( $rr_on ), true ), 'giorni rossi: conferma dal pannello' );
asem_render( array( \AssociazioneSemplice\Admin\SettingsPage::class, 'render' ), '27/04/2031 - 29/04/2031 Ponte' );
asem_render( array( \AssociazioneSemplice\Admin\ActivitiesPage::class, 'render_detail' ), 'Nessuna lezione nei giorni festivi e di chiusura', array( 'id' => $rr_on ) );
Settings::update( array( 'patron_day' => '', 'closures' => '' ) );
