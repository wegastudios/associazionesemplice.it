<?php
/**
 * Primo mese in proporzione, per scelta del corso (di partenza accesa per i corsi mensili, anche quelli che c'erano): vale per chi si iscrive dopo;
 * le iscrizioni già fatte, i mesi dovuti e gli anni chiusi non cambiano di un centesimo.
 */

defined( 'ABSPATH' ) || exit;

use AssociazioneSemplice\Db;
use AssociazioneSemplice\Plugin;
use AssociazioneSemplice\Settings;
use AssociazioneSemplice\Trials;

global $wpdb;
wp_set_current_user( 1 );
$fm_acts = Plugin::activities();
$fm_n    = 0;
$fm_man  = function () use ( &$fm_n ): int {
	$fm_n++;
	return (int) Plugin::people()->create( array( 'type' => 'ordinary', 'card_number' => '77' . $fm_n, 'first_name' => 'Primo', 'last_name' => 'Mese ' . $fm_n, 'birth_date' => '1985-01-01' ) );
};
$fm_lab = Settings::social_year( '2031-05-13' )->label();
$fm_mk  = function ( string $name, array $extra = array() ) use ( $fm_acts, $fm_lab ): int {
	return (int) $fm_acts->create( array_merge( array( 'name' => $name, 'social_year' => $fm_lab, 'kind' => 'course', 'billing' => 'monthly', 'fee_cents' => 4000, 'lesson_slots' => array( array( 'type' => 'weekly', 'day' => 2, 'start' => '18:00', 'end' => '19:00' ) ) ), $extra ) );
};

// ---------- La scelta di partenza ----------
$fm_keep = Trials::$first_month_fake;
Trials::$first_month_fake = '';
$fm_def = $fm_mk( 'Scelta di partenza' );
Trials::$first_month_fake = $fm_keep;
asem_ok( 'share' === $fm_acts->get( $fm_def )['first_month'], 'primo mese: un corso nuovo parte in proporzione' );

// ---------- Maggio 2031: martedì 6, 13, 20, 27 ----------
Db::$today_fake = '2031-05-13';
$fm_a = $fm_mk( 'Martedì in proporzione', array( 'first_month' => 'share' ) );
$fm_b = $fm_mk( 'Martedì intero', array( 'first_month' => 'full' ) );
$fm_p = $fm_man();
asem_ok( 3000 === $fm_acts->course_month_fee( $fm_acts->get( $fm_a ), Plugin::people()->get( $fm_p ), '2031-05' ) && 4000 === $fm_acts->course_month_fee( $fm_acts->get( $fm_b ), Plugin::people()->get( $fm_p ), '2031-05' ), 'primo mese: il giorno di una lezione quella lezione si paga (3 su 4)' );
$fm_acts->enroll( $fm_a, $fm_p, '2031-05' );
$fm_acts->enroll( $fm_b, $fm_p, '2031-05' );
asem_ok( 3000 === (int) $fm_acts->enrollment_left( $fm_a, $fm_p, '2031-05' ) && 4000 === (int) $fm_acts->enrollment_left( $fm_a, $fm_p, '2031-06' ) && 4000 === (int) $fm_acts->enrollment_left( $fm_b, $fm_p, '2031-05' ), 'primo mese: in proporzione solo il primo mese, solo dove il corso lo prevede' );
$fm_acts->update( $fm_a, array( 'first_month' => 'full' ) );
asem_ok( 3000 === (int) $fm_acts->enrollment_left( $fm_a, $fm_p, '2031-05' ), 'primo mese: cambiare la scelta del corso non cambia chi è già iscritto' );
$fm_acts->update( $fm_a, array( 'first_month' => 'share' ) );
Db::$today_fake = '2031-05-21';
$fm_acts->enroll( $fm_a, $fm_p, '2031-05' );
$fm_acts->enroll( $fm_b, $fm_p, '2031-05' );
asem_ok( 3000 === (int) $fm_acts->enrollment_left( $fm_a, $fm_p, '2031-05' ) && 4000 === (int) $fm_acts->enrollment_left( $fm_b, $fm_p, '2031-05' ), 'primo mese: riconfermare l\'iscrizione più tardi non cambia il dovuto' );

// ---------- Iscrizione di prima, senza giorno di partenza: intera, anche riconfermata ----------
$fm_o = $fm_man();
$wpdb->insert( Db::t( 'enrollments' ), array( 'activity_id' => $fm_a, 'person_id' => $fm_o, 'start_month' => '2031-05', 'created_at' => Db::now() ) );
$fm_acts->enroll( $fm_a, $fm_o, '2031-05' );
asem_ok( 4000 === (int) $fm_acts->enrollment_left( $fm_a, $fm_o, '2031-05' ), 'primo mese: un\'iscrizione senza giorno di partenza resta intera' );

// ---------- Fine mese, mese futuro, giorno scelto, variante, trimestre e unico, prova con mese intero ----------
Db::$today_fake = '2031-05-28';
$fm_q = $fm_man();
$fm_acts->enroll( $fm_a, $fm_q, '2031-05' );
asem_ok( 0 === (int) $fm_acts->enrollment_left( $fm_a, $fm_q, '2031-05' ) && 4000 === (int) $fm_acts->enrollment_left( $fm_a, $fm_q, '2031-06' ), 'primo mese: senza lezioni rimaste non si paga nulla' );
$fm_msg = new \ReflectionMethod( \AssociazioneSemplice\Admin\Actions::class, 'trial_message' );
$fm_msg->setAccessible( true );
asem_ok( false !== strpos( (string) $fm_msg->invoke( null, $fm_a, $fm_q, '2031-05' ), 'non ci sono più lezioni' ) && false !== strpos( (string) $fm_msg->invoke( null, $fm_a, $fm_p, '2031-05' ), '30,00' ), 'primo mese: chi iscrive legge quanto si paga' );
$fm_r = $fm_man();
$fm_acts->enroll( $fm_a, $fm_r, '2031-06' );
asem_ok( 4000 === (int) $fm_acts->enrollment_left( $fm_a, $fm_r, '2031-06' ), 'primo mese: da un mese futuro si paga intero' );
$fm_s = $fm_man();
$fm_acts->enroll( $fm_a, $fm_s, '2031-05', '2031-05-20' );
$fm_t = $fm_man();
$fm_acts->enroll( $fm_a, $fm_t, '2031-05', '2031-05-01' );
asem_ok( 2000 === (int) $fm_acts->enrollment_left( $fm_a, $fm_s, '2031-05' ) && 4000 === (int) $fm_acts->enrollment_left( $fm_a, $fm_t, '2031-05' ), 'primo mese: la segreteria può indicare il giorno di partenza' );
// giorno di partenza: una data vera, dell'anno del corso, in un anno aperto; chi sceglie un giorno diverso da oggi resta nel registro
$fm_bad = 0;
$fm_bp  = $fm_man();
foreach ( array( '2031-05-99', '2031-02-30', '20310520', '2028-05-20', '2034-05-20', 'ieri' ) as $fm_bd ) {
	$fm_bad += '' !== (string) asem_throws( function () use ( $fm_acts, $fm_a, $fm_bp, $fm_bd ) { $fm_acts->enroll( $fm_a, $fm_bp, '2031-05', $fm_bd ); } ) ? 1 : 0;
}
$fm_by = \AssociazioneSemplice\FiscalYears::year_of( '2031-05-20' );
\AssociazioneSemplice\FiscalYears::ensure( $fm_by );
$wpdb->update( Db::t( 'fiscal_years' ), array( 'status' => 'closed' ), array( 'year' => $fm_by ) );
\AssociazioneSemplice\RequestMemo::forget();
wp_cache_flush();
$fm_bad += '' !== (string) asem_throws( function () use ( $fm_acts, $fm_a, $fm_bp ) { $fm_acts->enroll( $fm_a, $fm_bp, '2031-05', '2031-05-20' ); } ) ? 1 : 0;
$wpdb->delete( Db::t( 'fiscal_years' ), array( 'year' => $fm_by ) );
\AssociazioneSemplice\RequestMemo::forget();
wp_cache_flush();
asem_ok( 7 === $fm_bad && 0 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Db::t( 'enrollments' ) . ' WHERE person_id = %d', $fm_bp ) ), 'primo mese: date non valide, di un altro anno o di un anno chiuso si rifiutano (' . $fm_bad . ' su 7)' );
$fm_log = function ( int $pid ) use ( $wpdb ): array {
	foreach ( $wpdb->get_col( 'SELECT details FROM ' . Db::t( 'audit_log' ) . " WHERE action = 'activity.enrolled' ORDER BY id DESC LIMIT 60" ) as $j ) {
		$x = (array) json_decode( (string) $j, true );
		if ( $pid === (int) ( $x['person_id'] ?? 0 ) ) {
			return $x;
		}
	}
	return array();
};
asem_ok( '2031-05-20' === ( $fm_log( $fm_s )['pay_from'] ?? '' ) && ! isset( $fm_log( $fm_q )['pay_from'] ), 'primo mese: un giorno scelto diverso da oggi resta nel registro azioni' );
// iscrizione tolta (gli incassi restano) e rifatta a fine mese: mai meno del versato, mai soldi in più
$fm_cash = Plugin::ledger()->default_account_for( 'cash' );
foreach ( array( 4000 => 4000, 2000 => 2000, 1500 => 2000 ) as $fm_pay => $fm_due ) {
	$fm_rp = $fm_man();
	$wpdb->insert( Db::t( 'transactions' ), array( 'tx_date' => '2031-05-06', 'type' => 'income', 'amount_cents' => $fm_pay, 'account_id' => (int) $fm_cash['id'], 'method' => 'cash', 'category_id' => Plugin::ledger()->category_id_of_kind( 'activity_fee' ), 'activity_id' => $fm_a, 'person_id' => $fm_rp, 'competence_month' => '2031-05', 'created_at' => Db::now() ) );
	$fm_tid = (int) $wpdb->insert_id;
	$fm_acts->enroll( $fm_a, $fm_rp, '2031-05' );
	$fm_left = (int) $fm_acts->enrollment_left( $fm_a, $fm_rp, '2031-05' );
	$fm_sum  = null;
	foreach ( $fm_acts->status_for_activity( $fm_a ) as $fm_st2 ) {
		if ( (int) $fm_st2['enrollment']['person_id'] === $fm_rp ) {
			$fm_sum = $fm_st2['summary'];
		}
	}
	$wpdb->delete( Db::t( 'transactions' ), array( 'id' => $fm_tid ) );
	asem_ok( $fm_tid > 0 && $fm_due - $fm_pay === $fm_left && $fm_sum && (int) $fm_sum['balance'] <= 0, 'primo mese: con ' . $fm_pay . ' già versati e rifatta a fine mese, resta da pagare ' . ( $fm_due - $fm_pay ) );
}
// letture della cassa con trenta corsi
for ( $fm_i = 0; $fm_i < 30; $fm_i++ ) {
	$fm_mk( 'Letture cassa ' . $fm_i, array( 'first_month' => 'share' ) );
}
$fm_many = array_filter( $fm_acts->for_year( $fm_lab ), function ( $x ) { return 'course' === $x['kind']; } );
$fm_pers = Plugin::people()->get( $fm_man() );
\AssociazioneSemplice\RequestMemo::forget();
$fm_q0 = $wpdb->num_queries;
foreach ( $fm_many as $fm_row ) {
	$fm_acts->course_month_fee( $fm_row, $fm_pers, '2031-05' );
}
$fm_q0 = $wpdb->num_queries - $fm_q0;
asem_ok( count( $fm_many ) >= 30 && $fm_q0 <= 6, 'primo mese: la cassa con ' . count( $fm_many ) . ' corsi legge poche volte (' . $fm_q0 . ')' );
foreach ( array( 'quarterly', 'once' ) as $fm_bill ) {
	$fm_c = $fm_mk( 'Martedì ' . $fm_bill, array( 'billing' => $fm_bill, 'first_month' => 'share' ) );
	$fm_u = $fm_man();
	$fm_acts->enroll( $fm_c, $fm_u, '2031-05' );
	asem_ok( 4000 === (int) $fm_acts->enrollment_left( $fm_c, $fm_u, '2031-05' ), 'primo mese: con ' . $fm_bill . ' resta intero' );
}
$fm_v = $fm_mk( 'Martedì con variante', array( 'first_month' => 'share', 'price_lists' => array( array( 'label' => 'Ridotto', 'cents' => 2000 ) ) ) );
$fm_w = $fm_man();
$fm_acts->enroll( $fm_v, $fm_w, '2031-05', '2031-05-20' );
$fm_acts->set_enrollment_price( $fm_v, $fm_w, \AssociazioneSemplice\PriceLists::for_activity( $fm_acts->get( $fm_v ) )[0]['key'] );
asem_ok( 1000 === (int) $fm_acts->enrollment_left( $fm_v, $fm_w, '2031-05' ), 'primo mese: la proporzione vale sulla variante della persona' );
$fm_x = $fm_man();
$fm_acts->update( $fm_b, array( 'trial_days' => 1 ) );
Trials::add( $fm_b, $fm_x, '2031-05-27' );
$fm_acts->enroll( $fm_b, $fm_x, '2031-05' );
asem_ok( 4000 === (int) $fm_acts->enrollment_left( $fm_b, $fm_x, '2031-05' ), 'primo mese: con «mese intero» paga intero anche chi ha fatto la prova' );
$fm_z = $fm_mk( 'Senza orari', array( 'first_month' => 'share', 'lesson_slots' => array() ) );
$fm_y = $fm_man();
$fm_acts->enroll( $fm_z, $fm_y, '2031-05' );
asem_ok( (int) round( 4000 * 4 / 31 ) === (int) $fm_acts->enrollment_left( $fm_z, $fm_y, '2031-05' ), 'primo mese: senza orari si conta a giorni' );
Db::$today_fake = '';

// ---------- Aggiornamento 79 → 80: la scelta c'è anche per i corsi di prima, i dovuti non cambiano, nemmeno con un anno chiuso ----------
$fm_snap = function () use ( $fm_acts, $wpdb ): string {
	$out = array();
	foreach ( $wpdb->get_col( 'SELECT id FROM ' . Db::t( 'activities' ) . " WHERE deleted_at IS NULL AND kind = 'course' ORDER BY id" ) as $id ) {
		foreach ( $fm_acts->status_for_activity( (int) $id ) as $s ) {
			$out[ $id . ':' . $s['enrollment']['person_id'] ] = array( $s['summary']['months'], $s['summary']['balance'], $fm_acts->enrollment_left( (int) $id, (int) $s['enrollment']['person_id'], (string) $s['enrollment']['start_month'] ) );
		}
	}
	ksort( $out );
	return (string) wp_json_encode( $out );
};
$fm_fy = \AssociazioneSemplice\FiscalYears::year_of( Db::today() );
\AssociazioneSemplice\FiscalYears::ensure( $fm_fy );
$fm_st = \AssociazioneSemplice\FiscalYears::get( $fm_fy )['status'];
$wpdb->update( Db::t( 'fiscal_years' ), array( 'status' => 'closed' ), array( 'year' => $fm_fy ) );
$fm_before = $fm_snap();
$fm_tx     = (string) wp_json_encode( $wpdb->get_row( 'SELECT COUNT(*) n, SUM(amount_cents) s FROM ' . Db::t( 'transactions' ), ARRAY_A ) );
$wpdb->query( 'ALTER TABLE ' . Db::t( 'activities' ) . ' DROP COLUMN first_month' );
update_option( \AssociazioneSemplice\Install::DB_VERSION_OPTION, '79' );
\AssociazioneSemplice\Install::maybe_upgrade();
\AssociazioneSemplice\RequestMemo::forget();
wp_cache_flush();
$fm_after = $fm_snap();
$wpdb->update( Db::t( 'fiscal_years' ), array( 'status' => $fm_st ), array( 'year' => $fm_fy ) );
\AssociazioneSemplice\RequestMemo::forget();
wp_cache_flush();
asem_ok( strlen( $fm_before ) > 500 && $fm_before === $fm_after && $fm_tx === (string) wp_json_encode( $wpdb->get_row( 'SELECT COUNT(*) n, SUM(amount_cents) s FROM ' . Db::t( 'transactions' ), ARRAY_A ) ), 'primo mese: con l\'aggiornamento i dovuti di ogni iscritto e la prima nota restano identici, anche con un anno chiuso' );
asem_ok( 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Db::t( 'activities' ) . " WHERE first_month <> 'share'" ), 'primo mese: dopo l\'aggiornamento la scelta è in proporzione anche per i corsi di prima' );
asem_render( array( \AssociazioneSemplice\Admin\ActivitiesPage::class, 'render_detail' ), 'Paga in proporzione alle lezioni che restano', array( 'id' => $fm_a ) );

// ---------- La data simulata e la scelta simulata valgono solo nelle prove; Bacheca: servono tutti e due i permessi ----------
asem_ok( defined( 'ASEM_TESTS' ) && false !== strpos( (string) file_get_contents( dirname( __DIR__ ) . '/includes/Db.php' ), "'' !== self::\$today_fake && defined( 'ASEM_TESTS' )" ), 'data simulata: senza ASEM_TESTS non ha effetto' );
$fm_tp = new \ReflectionMethod( \AssociazioneSemplice\Admin\DashboardPage::class, 'trials_pending' );
$fm_tp->setAccessible( true );
$fm_card = function () use ( $fm_tp ): string {
	ob_start();
	$fm_tp->invoke( null );
	return (string) ob_get_clean();
};
$fm_tc = (int) $fm_acts->create( array( 'name' => 'Prova in Bacheca', 'social_year' => Settings::social_year()->label(), 'kind' => 'course', 'billing' => 'monthly', 'fee_cents' => 4000, 'trial_days' => 1 ) );
Trials::add( $fm_tc, $fm_man(), Db::today() );
asem_ok( false !== strpos( $fm_card(), 'Prova in Bacheca' ), 'prova in Bacheca: l\'amministratore la vede' );
$fm_uid = (int) wp_insert_user( array( 'user_login' => 'dipendente.bacheca', 'user_email' => 'dipendente.bacheca@example.com', 'user_pass' => wp_generate_password(), 'role' => Plugin::ROLE_EMPLOYEE ) );
if ( $fm_uid ) {
	foreach ( array( array( 'courses' ), array( 'people' ) ) as $fm_den ) {
		\AssociazioneSemplice\Employees::set_denied( $fm_uid, $fm_den );
		wp_set_current_user( $fm_uid );
		$fm_seen = $fm_card();
		wp_set_current_user( 1 );
		asem_ok( '' === $fm_seen, 'prova in Bacheca: senza ' . $fm_den[0] . ' non compare' );
	}
	\AssociazioneSemplice\Employees::set_denied( $fm_uid, array() );
}
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $fm_uid );
