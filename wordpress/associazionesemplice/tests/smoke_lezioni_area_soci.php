<?php
/**
 * Corsi a lezioni nell'area soci: quante lezioni e quanto costa questo mese e il prossimo (per l'iscritto e per chi chiede l'iscrizione), e i mesi
 * da pagare con il numero delle lezioni. Il socio capisce perché un mese costa più di un altro.
 */

defined( 'ABSPATH' ) || exit;

use AssociazioneSemplice\Db;
use AssociazioneSemplice\Frontend\Views;
use AssociazioneSemplice\Money;
use AssociazioneSemplice\Plugin;
use AssociazioneSemplice\Settings;

wp_set_current_user( 1 );
Db::$today_fake = '2031-05-13';
$la_acts = Plugin::activities();
$la_lab  = Settings::social_year( '2031-05-13' )->label();
$la_c    = (int) $la_acts->create( array( 'name' => 'Lezioni nell\'area soci', 'social_year' => $la_lab, 'kind' => 'course', 'billing' => 'lessons', 'fee_cents' => 1000, 'starts_on' => '2031-05-01', 'lesson_slots' => array( array( 'type' => 'weekly', 'day' => 2, 'start' => '18:00', 'end' => '19:00' ) ) ) );
$la_mk   = function ( string $last ) use ( $la_lab ): array {
	$id = (int) Plugin::people()->create( array( 'type' => 'ordinary', 'first_name' => 'Area', 'last_name' => $last, 'birth_date' => '1985-01-01' ) );
	Plugin::people()->set_membership( $id, Settings::membership_year( '2031-05-13' )->label(), true );
	return Plugin::people()->get( $id );
};
$la_p = $la_mk( 'Iscritta' );
$la_acts->enroll( $la_c, (int) $la_p['id'], '2031-05', '2031-05-01' );
$la_may = $la_acts->course_month_fee( $la_acts->get( $la_c ), $la_p, '2031-05' );
$la_jun = $la_acts->course_month_fee( $la_acts->get( $la_c ), $la_p, '2031-06' );
$la_n   = function ( int $cents ): string {
	$n = (int) round( $cents / 1000 );
	return $n . ( 1 === $n ? ' lezione' : ' lezioni' );
};
$la_txt = Views::lesson_months_text( $la_acts->get( $la_c ), $la_p );
asem_ok( false !== strpos( $la_txt, 'Questo mese: ' . $la_n( $la_may ) . ', ' . Money::format( $la_may ) ) && false !== strpos( $la_txt, 'Il mese prossimo: ' . $la_n( $la_jun ) . ', ' . Money::format( $la_jun ) ), 'lezioni nell\'area soci: questo mese e il prossimo, lezioni e importo (' . $la_txt . ')' );
$la_sec = Views::section_activities( $la_p );
asem_ok( false !== stripos( $la_sec, 'maggio 2031 (' . $la_n( $la_may ) . ')' ) && false !== strpos( $la_sec, 'Questo mese: ' ), 'lezioni nell\'area soci: i mesi da pagare con il numero delle lezioni e il riepilogo del mese' );
\AssociazioneSemplice\LessonExceptions::cancel( $la_c, '2031-05-20' ); // lezione annullata: esce dal dovuto, la riga e il riepilogo dicono lo stesso
$la_t2 = Views::lesson_months_text( $la_acts->get( $la_c ), $la_p );
$la_l2 = (int) $la_acts->enrollment_left( $la_c, (int) $la_p['id'], '2031-05' );
asem_ok( 1000 === $la_may - $la_l2 && false !== strpos( $la_t2, 'Questo mese: ' . $la_n( $la_l2 ) . ', ' . Money::format( $la_l2 ) ), 'lezioni nell\'area soci: con una lezione annullata la riga toglie quella lezione, come il dovuto (' . $la_t2 . ')' );
$la_q   = $la_mk( 'Interessata' );
$la_tq  = Views::lesson_months_text( $la_acts->get( $la_c ), $la_q );
asem_ok( false !== strpos( $la_tq, 'Questo mese: ' ) && false !== strpos( $la_tq, 'Il mese prossimo: ' ), 'lezioni nell\'area soci: anche chi non è ancora iscritto vede lezioni e importo prima di chiedere (' . $la_tq . ')' );
$la_m = (int) $la_acts->create( array( 'name' => 'Mensile nell\'area soci', 'social_year' => $la_lab, 'kind' => 'course', 'billing' => 'monthly', 'fee_cents' => 4000, 'lesson_slots' => array( array( 'type' => 'weekly', 'day' => 2, 'start' => '18:00', 'end' => '19:00' ) ) ) );
asem_ok( '' === Views::lesson_months_text( $la_acts->get( $la_m ), $la_p ), 'lezioni nell\'area soci: per i corsi mensili la riga non c\'è' );
Db::$today_fake = '';
