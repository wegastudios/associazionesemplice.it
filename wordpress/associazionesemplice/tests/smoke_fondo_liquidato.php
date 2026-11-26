<?php
/**
 * Lezione annullata quando la quota dell'istruttore è già stata liquidata (fondo estinto) o in parte liberata: la ripresa non va mai sotto zero,
 * la differenza resta scritta sulla lezione e la scheda del corso la mostra. L'importo a favore della persona non cambia.
 */

defined( 'ABSPATH' ) || exit;

use AssociazioneSemplice\CourseCredits;
use AssociazioneSemplice\Db;
use AssociazioneSemplice\LessonExceptions;
use AssociazioneSemplice\Plugin;
use AssociazioneSemplice\Settings;

global $wpdb;
wp_set_current_user( 1 );
Db::$today_fake = '2031-09-02';
$fl_acts = Plugin::activities();
$fl_lab  = Settings::social_year( '2031-09-02' )->label();
$fl_cash = Plugin::ledger()->default_account_for( 'cash' );
$fl_n    = 0;
$fl_case = function () use ( &$fl_n, $fl_acts, $fl_lab, $fl_cash ): array {
	$fl_n++;
	$ins = (int) Plugin::people()->create( array( 'type' => 'volunteer', 'first_name' => 'Istruttore', 'last_name' => 'Liquidato ' . $fl_n, 'email' => 'istruttore.liquidato' . $fl_n . '@example.com' ) );
	$aid = (int) $fl_acts->create( array( 'name' => 'Fondo liquidato ' . $fl_n, 'social_year' => $fl_lab, 'kind' => 'course', 'billing' => 'lessons', 'fee_cents' => 1000, 'instructor_person_id' => $ins, 'fund_mode' => 'percent', 'fund_value' => 5000, 'lesson_slots' => array( array( 'type' => 'weekly', 'day' => 2, 'start' => '18:00', 'end' => '19:00' ) ) ) );
	$pid = (int) Plugin::people()->create( array( 'type' => 'ordinary', 'card_number' => '66' . $fl_n, 'first_name' => 'Allievo', 'last_name' => 'Liquidato ' . $fl_n, 'birth_date' => '1985-01-01' ) );
	$fl_acts->enroll( $aid, $pid, '2031-09', '2031-09-01' );
	$due = (int) $fl_acts->enrollment_left( $aid, $pid, '2031-09' );
	Plugin::ledger()->record_receipt( array( 'date' => current_time( 'Y-m-d' ), 'account_id' => (int) $fl_cash['id'], 'method' => 'cash', 'person_id' => $pid, 'check_due' => true, 'lines' => array( array( 'category_id' => Plugin::ledger()->category_id_of_kind( 'activity_fee' ), 'amount_cents' => $due, 'activity_id' => $aid, 'competence_month' => '2031-09' ) ) ) );
	return array( 'aid' => $aid, 'pid' => $pid, 'fund' => Plugin::funds()->instructor_fund_id( $aid ), 'due' => $due );
};
$fl_row = function ( array $c, string $date ) use ( $wpdb ): ?array {
	$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Db::t( 'course_credits' ) . ' WHERE activity_id = %d AND person_id = %d AND lesson_date = %s', $c['aid'], $c['pid'], $date ), ARRAY_A );
	return $r ?: null;
};

// Fondo estinto (quota pagata all'istruttore): niente da riprendere, tutta la quota della lezione resta scritta come differenza.
$fl_a = $fl_case();
asem_ok( $fl_a['fund'] > 0 && (int) Plugin::funds()->get( $fl_a['fund'] )['balance'] > 0, 'fondo liquidato: la quota dell\'istruttore è accantonata sull\'incasso' );
Plugin::funds()->settle( $fl_a['fund'], (int) $fl_cash['id'], 'cash', '2031-09-02' );
$fl_tx0 = (string) wp_json_encode( $wpdb->get_row( 'SELECT COUNT(*) n, SUM(amount_cents) s FROM ' . Db::t( 'transactions' ), ARRAY_A ) );
$fl_ex  = (string) asem_throws( function () use ( $fl_a ) { LessonExceptions::cancel( $fl_a['aid'], '2031-09-16' ); } );
$fl_r   = $fl_row( $fl_a, '2031-09-16' );
asem_ok( '' === $fl_ex && $fl_r && 0 === (int) $fl_r['fund_released'] && 500 === (int) $fl_r['fund_short'], 'fondo liquidato: la lezione si annulla, nulla si riprende e la differenza (5,00) resta scritta' . ( '' !== $fl_ex ? ' [' . $fl_ex . ']' : '' ) );
asem_ok( 1000 === CourseCredits::available( $fl_r ) && $fl_tx0 === (string) wp_json_encode( $wpdb->get_row( 'SELECT COUNT(*) n, SUM(amount_cents) s FROM ' . Db::t( 'transactions' ), ARRAY_A ) ), 'fondo liquidato: l\'importo a favore della persona è intero e la prima nota non cambia' );
asem_render( array( \AssociazioneSemplice\Admin\ActivitiesPage::class, 'render_detail' ), 'quota istruttore già liquidata', array( 'id' => $fl_a['aid'] ) );

// Fondo in parte liberato: si riprende quel che c'è, mai sotto zero; il resto è differenza.
$fl_b = $fl_case();
$fl_bal = (int) Plugin::funds()->get( $fl_b['fund'] )['balance'];
Plugin::funds()->release( $fl_b['fund'], $fl_bal - 200, '2031-09-02', 'Prova: quota in parte liberata' );
LessonExceptions::cancel( $fl_b['aid'], '2031-09-16' );
$fl_r2 = $fl_row( $fl_b, '2031-09-16' );
asem_ok( $fl_r2 && 200 === (int) $fl_r2['fund_released'] && 300 === (int) $fl_r2['fund_short'] && 0 === (int) Plugin::funds()->get( $fl_b['fund'] )['balance'], 'fondo in parte liberato: si riprendono i 2,00 rimasti, il fondo resta a zero e 3,00 restano scritti come differenza' );
Db::$today_fake = '';
