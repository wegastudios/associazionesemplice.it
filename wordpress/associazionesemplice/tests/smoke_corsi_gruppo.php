<?php
/**
 * Iscrizione ai corsi per più persone dall'area soci (decisioni del titolare 26/11/2026): un invio = un gruppo di richieste, tutte o nessuna; la segreteria le
 * approva una per una o con «Approva tutte»; per un altro socio adulto chi iscrive paga solo il primo mese (paid_by fino a paid_by_until), poi paga lui;
 * l'altro socio può ritirarsi; gli ospiti nuovi restano anche se la richiesta è respinta; nessun oracolo sugli altri soci. Si carica da smoke.php.
 */

defined( 'ABSPATH' ) || exit;

use AssociazioneSemplice\ActivityService;
use AssociazioneSemplice\Db;
use AssociazioneSemplice\EventRequests;
use AssociazioneSemplice\Plugin;
use AssociazioneSemplice\Settings;
use AssociazioneSemplice\Frontend\Actions;

global $wpdb;
wp_set_current_user( 1 );
Db::$today_fake = '2031-05-13';
Settings::update( array( 'limits' => array( 'member_book_per_10min' => 500, 'member_other_fail_per_10min' => 50, 'ip_other_fail_per_hour' => 500 ), 'rules_block_booking' => 0 ) );

$cg_acts = Plugin::activities();
$cg_peo  = Plugin::people();
$cg_lab  = Settings::social_year( '2031-05-13' )->label();
$cg_mem  = Settings::membership_year( '2031-05-13' )->label();
$cg_new  = function ( string $name ) use ( $cg_acts, $cg_lab ): int {
	return (int) $cg_acts->create( array( 'name' => $name, 'social_year' => $cg_lab, 'kind' => 'course', 'fee_cents' => 4000, 'first_month' => 'full', 'lesson_slots' => array( array( 'type' => 'weekly', 'day' => 2, 'start' => '18:00', 'end' => '19:00' ) ) ) );
};
$cg_member = function ( string $first, string $last, string $card, bool $user = false ) use ( $cg_peo, $cg_mem, $wpdb ): array {
	$id = (int) $cg_peo->create( array( 'type' => 'ordinary', 'card_number' => $card, 'first_name' => $first, 'last_name' => $last, 'email' => strtolower( $first ) . '.corsi.gruppo@example.com', 'birth_date' => '1980-05-05' ) );
	$cg_peo->set_membership( $id, $cg_mem, true );
	$uid = 0;
	if ( $user ) {
		$uid = (int) wp_insert_user( array( 'user_login' => 'cg_' . strtolower( wp_generate_password( 8, false ) ), 'user_pass' => wp_generate_password(), 'user_email' => 'cg.' . strtolower( wp_generate_password( 8, false ) ) . '@example.org', 'role' => 'subscriber' ) );
		$wpdb->update( Db::t( 'people' ), array( 'wp_user_id' => $uid, 'address' => 'Via Prova 2', 'city' => 'Roma', 'zip' => '00100', 'province' => 'RM', 'tax_code' => 'CRSGRP80E05H501' . strtoupper( substr( $card, -1 ) ), 'phone' => '333 22' . $card ), array( 'id' => $id ) );
		wp_cache_flush();
	}
	return array( $id, $uid );
};
$cg_requests = function ( int $aid ) use ( $wpdb ): array {
	return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Db::t( 'event_requests' ) . ' WHERE activity_id = %d ORDER BY id', $aid ), ARRAY_A ) ?: array();
};
$cg_fail = function ( array $post ): string {
	return (string) asem_throws( function () use ( $post ) {
		Actions::do_course_request( $post );
	} );
};

list( $cg_booker, $cg_buid ) = $cg_member( 'Carla', 'Iscrive', '8101', true );
list( $cg_other, $cg_ouid )  = $cg_member( 'Gino', 'Iscritto', '8102', true );
list( $cg_shy )              = $cg_member( 'Rino', 'Riservato', '8103' );
$wpdb->update( Db::t( 'people' ), array( 'no_booking_by_others' => 1 ), array( 'id' => $cg_shy ) );
wp_cache_flush();
$cg_c = $cg_new( 'Corso di gruppo' );
wp_set_current_user( $cg_buid );
asem_ok( $cg_booker === (int) \AssociazioneSemplice\Access::current_person()['id'], 'corsi di gruppo: chi iscrive è il socio collegato' );

// ---------- Il modulo nell'area soci ----------
$cg_html = \AssociazioneSemplice\Frontend\Views::course_request( $cg_acts->get( $cg_c ) );
asem_ok( false !== strpos( $cg_html, 'name="course_group"' ) && false !== strpos( $cg_html, 'Iscrivi ospiti nuovi' ) && false !== strpos( $cg_html, 'Iscrivi altri soci' ) && false !== strpos( $cg_html, 'name="other_card[]"' ), 'corsi di gruppo: il modulo ha me, ospiti nuovi e altri soci' );

// ---------- Nessun oracolo sugli altri soci: stessa frase, nessuna richiesta ----------
$cg_base = array( 'activity_id' => $cg_c, 'course_group' => 1, 'me' => 1, 'new_guest_first' => array( 'Olga' ), 'new_guest_last' => array( 'Ospite' ), 'new_guest_phone' => array( '333 9998101' ), 'new_guest_email' => array( '' ) );
$cg_e1   = $cg_fail( $cg_base + array( 'other_last' => array( 'Iscritto' ), 'other_card' => array( '9999' ) ) );
$cg_e2   = $cg_fail( $cg_base + array( 'other_last' => array( 'Riservato' ), 'other_card' => array( '8103' ) ) );
$cg_e3   = $cg_fail( $cg_base + array( 'other_last' => array( 'Sbagliato' ), 'other_card' => array( '8102' ) ) );
asem_ok( Actions::OTHER_MEMBER_COURSE_FAIL === $cg_e1 && $cg_e1 === $cg_e2 && $cg_e2 === $cg_e3, 'corsi di gruppo: tessera sbagliata, socio che non si fa iscrivere e cognome sbagliato danno la stessa frase (' . $cg_e1 . ')' );
asem_ok( array() === $cg_requests( $cg_c ) && array() === $cg_peo->guests_of( $cg_booker ), 'corsi di gruppo: dopo un rifiuto nessuna richiesta e nessun ospite creato' );

// ---------- Il gruppo: tre richieste insieme ----------
$cg_msg = Actions::do_course_request( $cg_base + array( 'other_last' => array( 'iscritto' ), 'other_card' => array( '8102' ) ) );
$cg_rq  = $cg_requests( $cg_c );
$cg_gk  = array_unique( array_column( $cg_rq, 'group_key' ) );
$cg_gu  = $cg_peo->guests_of( $cg_booker );
$cg_gid = $cg_gu ? (int) $cg_gu[0]['id'] : 0;
asem_ok( 3 === count( $cg_rq ) && 1 === count( $cg_gk ) && 16 === strlen( (string) $cg_gk[0] ) && false !== strpos( $cg_msg, '3 persone' ), 'corsi di gruppo: un invio, tre richieste con lo stesso gruppo (' . $cg_msg . ')' );
$cg_by = array();
foreach ( $cg_rq as $r ) {
	$cg_by[ (int) $r['person_id'] ] = (int) $r['booked_by'];
}
asem_ok( $cg_gid && 0 === $cg_by[ $cg_booker ] && $cg_booker === $cg_by[ $cg_gid ] && $cg_booker === $cg_by[ $cg_other ], 'corsi di gruppo: le richieste per altre persone ricordano chi le ha inviate' );
asem_ok( false !== strpos( (string) $cg_fail( array( 'activity_id' => $cg_c, 'course_group' => 1, 'me' => 1 ) ), 'già iscritto o c\'è già una richiesta' ), 'corsi di gruppo: una seconda richiesta per la stessa persona è rifiutata col nome' );
asem_ok( Actions::OTHER_MEMBER_COURSE_FAIL === $cg_fail( array( 'activity_id' => $cg_c, 'course_group' => 1, 'other_last' => array( 'Iscritto' ), 'other_card' => array( '8102' ) ) ), 'corsi di gruppo: l\'altro socio già in attesa dà la stessa frase di sempre' );
$cg_box = EventRequests::admin_box( $cg_c, admin_url( 'admin.php?page=asem-activity&id=' . $cg_c ) );
asem_ok( false !== strpos( $cg_box, 'Approva tutte' ) && false !== strpos( $cg_box, 'chiesta da Carla Iscrive' ) && false !== strpos( $cg_box, 'Olga Ospite (ospite)' ), 'corsi di gruppo: la segreteria vede il gruppo, chi l\'ha chiesto e «Approva tutte»' );
asem_ok( null !== asem_throws( function () use ( $cg_gk ) {
	EventRequests::approve_group( (string) $cg_gk[0], 999999 );
} ), 'corsi di gruppo: «Approva tutte» vale solo per il corso indicato' );

// ---------- Approva tutte: chi paga il primo mese ----------
wp_set_current_user( 1 );
$cg_am = EventRequests::approve_group( (string) $cg_gk[0], $cg_c );
$cg_eo = $cg_acts->enrollment_row( $cg_c, $cg_other );
asem_ok( 0 === strpos( $cg_am, 'Approvate 3 richieste su 3' ) && $cg_eo && $cg_booker === (int) $cg_eo['paid_by'] && '2031-05' === $cg_eo['paid_by_until'], 'corsi di gruppo: tutte approvate, il primo mese dell\'altro socio è di chi l\'ha iscritto (' . $cg_am . ')' );
$cg_eg = $cg_acts->enrollment_row( $cg_c, $cg_gid );
asem_ok( $cg_eg && empty( $cg_eg['paid_by'] ) && $cg_acts->enrollment_row( $cg_c, $cg_booker ) && empty( $cg_acts->enrollment_row( $cg_c, $cg_booker )['paid_by'] ), 'corsi di gruppo: per sé e per il proprio ospite nessun pagante a parte (come sempre)' );
asem_ok( $cg_booker === ActivityService::month_payer( $cg_eo, '2031-05' ) && 0 === ActivityService::month_payer( $cg_eo, '2031-06' ), 'corsi di gruppo: chi iscrive paga solo il primo mese' );

// ---------- I dovuti: il primo mese a chi ha iscritto, con la sola tessera ----------
$cg_kb = 'm:' . $cg_c . ':' . $cg_other . ':2031-05';
$cg_db = Plugin::payments()->dues_for( $cg_peo->get( $cg_booker ) );
$cg_do = Plugin::payments()->dues_for( $cg_peo->get( $cg_other ) );
asem_ok( isset( $cg_db[ $cg_kb ] ) && $cg_booker === (int) $cg_db[ $cg_kb ]['payer_id'] && 'socio con tessera n. 8102' === $cg_db[ $cg_kb ]['person_name'] && 4000 === (int) $cg_db[ $cg_kb ]['amount_cents'], 'corsi di gruppo: il mese dell\'altro socio è tra i dovuti di chi l\'ha iscritto, solo con la tessera' );
asem_ok( ! isset( $cg_do[ $cg_kb ] ), 'corsi di gruppo: l\'altro socio non trova il primo mese tra i suoi dovuti' );
asem_ok( isset( $cg_db[ 'm:' . $cg_c . ':' . $cg_gid . ':2031-05' ] ) && isset( $cg_db[ 'm:' . $cg_c . ':' . $cg_booker . ':2031-05' ] ), 'corsi di gruppo: il mese del proprio ospite e il proprio restano di chi iscrive' );
Db::$today_fake = '2031-06-03';
$cg_db6 = Plugin::payments()->dues_for( $cg_peo->get( $cg_booker ) );
$cg_do6 = Plugin::payments()->dues_for( $cg_peo->get( $cg_other ) );
$cg_k6  = 'm:' . $cg_c . ':' . $cg_other . ':2031-06';
asem_ok( isset( $cg_do6[ $cg_k6 ] ) && ! isset( $cg_db6[ $cg_k6 ] ) && isset( $cg_db6[ $cg_kb ] ), 'corsi di gruppo: dal secondo mese paga l\'altro socio; il primo resta a chi l\'ha iscritto' );
Db::$today_fake = '2031-05-13';

// ---------- Area soci: chi è iscritto da un altro socio e chi ha iscritto ----------
wp_set_current_user( $cg_buid );
$cg_sb = \AssociazioneSemplice\Frontend\Views::section_activities( $cg_peo->get( $cg_booker ) );
asem_ok( false !== strpos( $cg_sb, 'Iscrizioni ai corsi fatte per altri soci' ) && false !== strpos( $cg_sb, 'socio con tessera n. 8102' ) && false === strpos( $cg_sb, 'Gino' ), 'corsi di gruppo: chi ha iscritto vede l\'iscrizione fatta per l\'altro socio, senza il nome' );
asem_ok( null !== asem_throws( function () use ( $cg_c ) {
	Actions::do_course_leave( array( 'activity_id' => $cg_c ) );
} ), 'corsi di gruppo: chi si è iscritto da sé non si ritira dall\'area soci (lo fa la segreteria)' );
wp_set_current_user( $cg_ouid );
$cg_so = \AssociazioneSemplice\Frontend\Views::section_activities( $cg_peo->get( $cg_other ) );
asem_ok( false !== strpos( $cg_so, 'Ti ha iscritto un altro socio' ) && false !== strpos( $cg_so, 'asem_front_course_leave' ), 'corsi di gruppo: l\'altro socio vede chi paga e può ritirarsi' );
$cg_lv = Actions::do_course_leave( array( 'activity_id' => $cg_c ) );
$cg_e2 = $cg_acts->enrollment_row( $cg_c, $cg_other );
asem_ok( '2031-05' === (string) $cg_e2['end_month'] && false !== strpos( $cg_lv, 'maggio 2031' ), 'corsi di gruppo: ritirato, resta fino al mese in corso (' . $cg_lv . ')' );
wp_set_current_user( $cg_buid );
asem_ok( isset( Plugin::payments()->dues_for( $cg_peo->get( $cg_booker ) )[ $cg_kb ] ), 'corsi di gruppo: dopo il ritiro il primo mese resta a chi l\'ha iscritto' );

// ---------- Una per una e il rifiuto: l'ospite nuovo resta ----------
$cg_c2 = $cg_new( 'Corso respinto' );
Actions::do_course_request( array( 'activity_id' => $cg_c2, 'course_group' => 1, 'me' => 1, 'new_guest_first' => array( 'Pia' ), 'new_guest_last' => array( 'Nuova' ), 'new_guest_phone' => array( '333 9998102' ), 'new_guest_email' => array( '' ) ) );
$cg_r2 = $cg_requests( $cg_c2 );
$cg_pi = 0;
foreach ( $cg_peo->guests_of( $cg_booker ) as $g ) {
	$cg_pi = 'Pia' === $g['first_name'] ? (int) $g['id'] : $cg_pi;
}
wp_set_current_user( 1 );
foreach ( $cg_r2 as $r ) {
	if ( $cg_pi === (int) $r['person_id'] ) {
		EventRequests::reject( (int) $r['id'] );
	} else {
		EventRequests::approve( (int) $r['id'] );
	}
}
asem_ok( 2 === count( $cg_r2 ) && $cg_pi && $cg_peo->get( $cg_pi ) && ! $cg_acts->enrollment_row( $cg_c2, $cg_pi ) && $cg_acts->enrollment_row( $cg_c2, $cg_booker ), 'corsi di gruppo: una approvata e una respinta; l\'ospite nuovo resta tra gli ospiti' );

// ---------- Il modulo di prima (una persona) funziona ancora ----------
$cg_c3 = $cg_new( 'Corso singolo' );
wp_set_current_user( $cg_buid );
Actions::do_course_request( array( 'activity_id' => $cg_c3, 'person_id' => $cg_booker ) );
$cg_r3 = $cg_requests( $cg_c3 );
asem_ok( 1 === count( $cg_r3 ) && empty( $cg_r3[0]['group_key'] ) && empty( $cg_r3[0]['booked_by'] ), 'corsi di gruppo: la richiesta per una persona sola resta come prima' );

wp_set_current_user( 1 );
Db::$today_fake = '';
