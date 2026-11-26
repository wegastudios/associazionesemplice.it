<?php
/**
 * Mensilità di un corso pagata nella moneta interna: per intero, mai mista con gli euro, sotto blocco, niente in prima nota, quota dell'istruttore sul
 * valore in euro; genitore per il figlio minore; lezione annullata, iscrizione cancellata: torna moneta. Solo con le funzioni Pro.
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\AssociazioneSemplice\CourseCoins' ) || ! \AssociazioneSemplice\Edition::has( 'credits' ) ) {
	return;
}

use AssociazioneSemplice\CourseCoins;
use AssociazioneSemplice\Credits;
use AssociazioneSemplice\Db;
use AssociazioneSemplice\Plugin;
use AssociazioneSemplice\Settings;

global $wpdb;
wp_set_current_user( 1 );
$cm_acts = Plugin::activities();
$cm_peo  = Plugin::people();
$cm_lab  = Settings::social_year( '2031-05-13' )->label();
$cm_n    = 0;
$cm_man  = function ( array $extra = array() ) use ( &$cm_n, $cm_peo, $cm_lab ): int {
	$cm_n++;
	$id = (int) $cm_peo->create( $extra + array( 'type' => 'ordinary', 'card_number' => '44' . $cm_n, 'first_name' => 'Moneta', 'last_name' => 'Persona ' . $cm_n, 'email' => 'moneta' . $cm_n . '@example.com', 'birth_date' => '1985-01-01' ) );
	$cm_peo->set_membership( $id, Settings::membership_year()->label(), true );
	$cm_peo->set_membership( $id, Settings::membership_year( '2031-05-13' )->label(), true );
	return $id;
};
$cm_ins = (int) $cm_peo->create( array( 'type' => 'volunteer', 'first_name' => 'Istruttore', 'last_name' => 'Moneta', 'email' => 'istruttore.moneta@example.com' ) );
$cm_c   = (int) $cm_acts->create( array( 'name' => 'Corso in moneta', 'social_year' => $cm_lab, 'kind' => 'course', 'billing' => 'monthly', 'first_month' => 'full', 'fee_cents' => 4000, 'instructor_person_id' => $cm_ins, 'fund_mode' => 'percent', 'fund_value' => 5000, 'lesson_slots' => array( array( 'type' => 'weekly', 'day' => 2, 'start' => '18:00', 'end' => '19:00' ) ) ) );
Credits::set_activity_price( $cm_c, 40 );
$cm_left = function ( int $pid, string $m ) use ( $cm_acts, $cm_c ): int {
	return (int) $cm_acts->enrollment_left( $cm_c, $pid, $m );
};
$cm_tx = function () use ( $wpdb ): string {
	return (string) wp_json_encode( $wpdb->get_row( 'SELECT COUNT(*) n, SUM(amount_cents) s FROM ' . Db::t( 'transactions' ), ARRAY_A ) );
};
Db::$today_fake = '2031-05-13';

// ---------- Il mese intero in moneta: chiuso, nessun incasso, quota dell'istruttore sul valore in euro ----------
$cm_m = $cm_man();
$cm_acts->enroll( $cm_c, $cm_m, '2031-05' );
Credits::adjust( $cm_m, 100, 'prova' );
$cm_t0 = $cm_tx();
asem_ok( 40 === CourseCoins::price( $cm_acts->get( $cm_c ), $cm_peo->get( $cm_m ), '2031-05' ), 'moneta: il mese costa 40 (prezzo del corso)' );
asem_ok( 40 === CourseCoins::pay( $cm_c, $cm_m, '2031-05', $cm_m ) && 60 === Credits::balance( $cm_m ) && 0 === $cm_left( $cm_m, '2031-05' ), 'moneta: pagato il mese, saldo scalato, niente da pagare in euro' );
asem_ok( $cm_t0 === $cm_tx(), 'moneta: in prima nota non entra nulla' );
$cm_fid = Plugin::funds()->instructor_fund_id( $cm_c );
asem_ok( $cm_fid && 2000 === (int) Plugin::funds()->get( $cm_fid )['balance'], 'moneta: la quota dell\'istruttore si accantona sul valore in euro (20,00)' );
asem_ok( '' !== (string) asem_throws( function () use ( $cm_c, $cm_m ) { CourseCoins::pay( $cm_c, $cm_m, '2031-05', $cm_m ); } ) && 60 === Credits::balance( $cm_m ), 'moneta: lo stesso mese non si paga due volte' );
$cm_cash = Plugin::ledger()->default_account_for( 'cash' );
asem_ok( '' !== (string) asem_throws( function () use ( $cm_cash, $cm_c, $cm_m ) { Plugin::ledger()->record_receipt( array( 'date' => current_time( 'Y-m-d' ), 'account_id' => (int) $cm_cash['id'], 'method' => 'cash', 'person_id' => $cm_m, 'check_due' => true, 'lines' => array( array( 'category_id' => Plugin::ledger()->category_id_of_kind( 'activity_fee' ), 'amount_cents' => 4000, 'activity_id' => $cm_c, 'competence_month' => '2031-05' ) ) ) ); } ), 'moneta: un mese pagato in moneta non accetta euro' );

// ---------- Niente misto, saldo insufficiente, ospiti, checkout aperto, anno chiuso ----------
$cm_acts->enroll( $cm_c, $cm_m, '2031-05' );
Plugin::ledger()->record_receipt( array( 'date' => current_time( 'Y-m-d' ), 'account_id' => (int) $cm_cash['id'], 'method' => 'cash', 'person_id' => $cm_m, 'check_due' => true, 'lines' => array( array( 'category_id' => Plugin::ledger()->category_id_of_kind( 'activity_fee' ), 'amount_cents' => 1000, 'activity_id' => $cm_c, 'competence_month' => '2031-06' ) ) ) );
asem_ok( '' !== (string) asem_throws( function () use ( $cm_c, $cm_m ) { CourseCoins::pay( $cm_c, $cm_m, '2031-06', $cm_m ); } ) && 60 === Credits::balance( $cm_m ), 'moneta: un mese già pagato in parte in euro non si completa in moneta' );
$cm_poor = $cm_man();
$cm_acts->enroll( $cm_c, $cm_poor, '2031-05' );
Credits::adjust( $cm_poor, 10, 'poco' );
asem_ok( '' !== (string) asem_throws( function () use ( $cm_c, $cm_poor ) { CourseCoins::pay( $cm_c, $cm_poor, '2031-05', $cm_poor ); } ) && 10 === Credits::balance( $cm_poor ) && 4000 === $cm_left( $cm_poor, '2031-05' ), 'moneta: saldo insufficiente, nulla cambia' );
$cm_g = (int) $cm_peo->create( array( 'type' => 'guest', 'host_person_id' => $cm_m, 'first_name' => 'Ospite', 'last_name' => 'Moneta', 'phone' => '333 4440055' ) );
$cm_acts->enroll( $cm_c, $cm_g, '2031-05' );
asem_ok( '' !== (string) asem_throws( function () use ( $cm_c, $cm_g ) { CourseCoins::pay( $cm_c, $cm_g, '2031-05', $cm_g ); } ), 'moneta: gli ospiti non pagano in moneta' );
$cm_o = $cm_man();
$cm_acts->enroll( $cm_c, $cm_o, '2031-05' );
Credits::adjust( $cm_o, 100, 'prova' );
if ( \AssociazioneSemplice\Install::table_exists( Db::t( 'payments' ) ) ) {
	$wpdb->insert( Db::t( 'payments' ), array( 'public_id' => 'prova-moneta', 'provider' => 'stripe', 'status' => 'pending', 'amount_cents' => 4000, 'payer_person_id' => $cm_o, 'payer_user_id' => 0, 'items' => wp_json_encode( array( array( 'key' => 'm:' . $cm_c . ':' . $cm_o . ':2031-05' ) ) ), 'created_at' => Db::now() ) );
	asem_ok( '' !== (string) asem_throws( function () use ( $cm_c, $cm_o ) { CourseCoins::pay( $cm_c, $cm_o, '2031-05', $cm_o ); } ) && 100 === Credits::balance( $cm_o ), 'moneta: con un pagamento online aperto sullo stesso mese si rifiuta' );
	$wpdb->delete( Db::t( 'payments' ), array( 'public_id' => 'prova-moneta' ) );
}
$cm_fy = \AssociazioneSemplice\FiscalYears::year_of( '2031-05-01' );
\AssociazioneSemplice\FiscalYears::ensure( $cm_fy );
$wpdb->update( Db::t( 'fiscal_years' ), array( 'status' => 'closed' ), array( 'year' => $cm_fy ) );
\AssociazioneSemplice\RequestMemo::forget();
wp_cache_flush();
$cm_cl = (string) asem_throws( function () use ( $cm_c, $cm_o ) { CourseCoins::pay( $cm_c, $cm_o, '2031-05', $cm_o ); } );
$wpdb->update( Db::t( 'fiscal_years' ), array( 'status' => 'open' ), array( 'year' => $cm_fy ) );
\AssociazioneSemplice\RequestMemo::forget();
wp_cache_flush();
asem_ok( '' !== $cm_cl && 100 === Credits::balance( $cm_o ), 'moneta: in un anno fiscale chiuso non si paga' );

// ---------- Genitore per il figlio minore; chi non è genitore no ----------
$cm_par = $cm_man( array( 'birth_date' => '1980-01-01' ) );
$cm_kid = $cm_man( array( 'birth_date' => gmdate( 'Y-m-d', strtotime( '-10 years' ) ), 'email' => '' ) );
\AssociazioneSemplice\Guardians::link( $cm_kid, $cm_par );
$cm_acts->enroll( $cm_c, $cm_kid, '2031-05' );
Credits::adjust( $cm_par, 50, 'prova' );
asem_ok( '' !== (string) asem_throws( function () use ( $cm_c, $cm_kid, $cm_o ) { CourseCoins::pay( $cm_c, $cm_kid, '2031-05', $cm_o ); } ) && 100 === Credits::balance( $cm_o ), 'moneta: chi non è genitore non paga per il minore' );
asem_ok( 40 === CourseCoins::pay( $cm_c, $cm_kid, '2031-05', $cm_par ) && 10 === Credits::balance( $cm_par ) && 0 === $cm_left( $cm_kid, '2031-05' ), 'moneta: il genitore paga il mese del figlio dal suo saldo' );
$cm_html = (string) apply_filters( 'asem_pay_section_extra', '', $cm_peo->get( $cm_o ) );
asem_ok( false !== strpos( $cm_html, 'Paga 40 ' ) && false !== strpos( $cm_html, 'Corso in moneta' ), 'moneta: nell\'area soci il mese si paga con un pulsante' );

// ---------- Iscrizione cancellata: torna la moneta, la quota esce dal fondo ----------
$cm_fb = (int) Plugin::funds()->get( $cm_fid )['balance'];
\AssociazioneSemplice\CourseCoins::registration_deleted( $cm_c, $cm_kid );
asem_ok( 50 === Credits::balance( $cm_par ) && 2000 === $cm_fb - (int) Plugin::funds()->get( $cm_fid )['balance'] && 4000 === $cm_left( $cm_kid, '2031-05' ), 'moneta: iscrizione cancellata, i 40 tornano al genitore e la quota esce dal fondo' );

// ---------- Corso a lezioni: lezione annullata in un mese pagato in moneta, torna la moneta di quella lezione ----------
$cm_l = (int) $cm_acts->create( array( 'name' => 'Lezioni in moneta', 'social_year' => $cm_lab, 'kind' => 'course', 'billing' => 'lessons', 'fee_cents' => 1000, 'lesson_slots' => array( array( 'type' => 'weekly', 'day' => 2, 'start' => '18:00', 'end' => '19:00' ) ) ) );
Credits::set_activity_price( $cm_l, 10 );
$cm_q = $cm_man();
Credits::adjust( $cm_q, 100, 'prova' );
Db::$today_fake = '2031-05-02';
$cm_acts->enroll( $cm_l, $cm_q, '2031-05', '2031-05-01' );
asem_ok( 40 === CourseCoins::pay( $cm_l, $cm_q, '2031-05', $cm_q ) && 60 === Credits::balance( $cm_q ), 'moneta: a lezioni, quattro lezioni da 10' );
\AssociazioneSemplice\LessonExceptions::cancel( $cm_l, '2031-05-20' );
asem_ok( 70 === Credits::balance( $cm_q ) && 0 === (int) $cm_acts->enrollment_left( $cm_l, $cm_q, '2031-05' ), 'moneta: lezione annullata, tornano 10 e il mese resta pagato' );
$cm_sum = null;
foreach ( $cm_acts->status_for_activity( $cm_l ) as $cm_s ) {
	if ( (int) $cm_s['enrollment']['person_id'] === $cm_q ) {
		$cm_sum = $cm_s['summary'];
	}
}
asem_ok( $cm_sum && 0 === (int) $cm_sum['balance'], 'moneta: nessun importo a favore in euro per un mese pagato in moneta' );
asem_ok( in_array( 'asem_course_credit_payments', \AssociazioneSemplice\Backup::tables(), true ) && isset( \AssociazioneSemplice\Privacy::export( $cm_q )['mensilita_moneta_interna'] ), 'moneta: nella copia e nei dati scaricati' );
Db::$today_fake = '';
