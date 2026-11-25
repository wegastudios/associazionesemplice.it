<?php
/**
 * Corsi a lezioni effettive: il dovuto di un mese è prezzo × lezioni previste; il mese si fissa una volta sola (primo incasso, promemoria, giorno 1) e il
 * prezzo si fissa per persona; i corsi di prima non cambiano. E il mese in corso è dovuto dalla prima lezione vera, festivi compresi.
 */

defined( 'ABSPATH' ) || exit;

use AssociazioneSemplice\CourseMonths;
use AssociazioneSemplice\Db;
use AssociazioneSemplice\LessonExceptions;
use AssociazioneSemplice\Plugin;
use AssociazioneSemplice\Settings;

global $wpdb;
wp_set_current_user( 1 );
$cl_acts = Plugin::activities();
$cl_n    = 0;
$cl_man  = function ( string $type = 'ordinary' ) use ( &$cl_n ): int {
	$cl_n++;
	return (int) Plugin::people()->create( array( 'type' => 'ordinary', 'card_number' => '66' . $cl_n, 'first_name' => 'Lezione', 'last_name' => 'Persona ' . $cl_n, 'birth_date' => '1985-01-01' ) );
};
$cl_lab = Settings::social_year( '2031-05-13' )->label();
$cl_mk  = function ( string $name, array $extra = array() ) use ( $cl_acts, $cl_lab ): int {
	return (int) $cl_acts->create( array_merge( array( 'name' => $name, 'social_year' => $cl_lab, 'kind' => 'course', 'billing' => 'lessons', 'fee_cents' => 1000, 'lesson_slots' => array( array( 'type' => 'weekly', 'day' => 2, 'start' => '18:00', 'end' => '19:00' ) ) ), $extra ) );
};
$cl_left = function ( int $a, int $p, string $m ) use ( $cl_acts ): int {
	return (int) $cl_acts->enrollment_left( $a, $p, $m );
};
$cl_sum = function ( int $aid, int $pid ) use ( $cl_acts ): array {
	foreach ( $cl_acts->status_for_activity( $aid ) as $s ) {
		if ( (int) $s['enrollment']['person_id'] === $pid ) {
			return $s['summary'];
		}
	}
	return array();
};
$cl_due = function ( int $aid, int $pid, string $m ) use ( $cl_sum ): int {
	foreach ( $cl_sum( $aid, $pid )['months'] ?? array() as $x ) {
		if ( $x['month'] === $m ) {
			return (int) $x['due'];
		}
	}
	return 0;
};
$cl_fixed = function ( int $aid, string $m ) use ( $wpdb ): int {
	return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Db::t( 'course_months' ) . ' WHERE activity_id = %d AND month = %s', $aid, $m ) );
};

// ---------- Previsto, fissato, mesi con più o meno lezioni ----------
Db::$today_fake = '2031-03-20'; // martedì: marzo 4, 11, 18, 25; aprile 1, 8, 15, 22, 29; maggio 6, 13, 20, 27
$cl_a = $cl_mk( 'Martedì a lezione' );
$cl_p = $cl_man();
$cl_acts->enroll( $cl_a, $cl_p, '2031-04' );
asem_ok( 5000 === $cl_left( $cl_a, $cl_p, '2031-04' ) && 4000 === $cl_left( $cl_a, $cl_p, '2031-05' ) && 0 === $cl_fixed( $cl_a, '2031-04' ), 'a lezioni: cinque lezioni costano più di quattro; prima del mese è previsto, non fissato' );
LessonExceptions::cancel( $cl_a, '2031-04-22' );
asem_ok( 4000 === $cl_left( $cl_a, $cl_p, '2031-04' ), 'a lezioni: prima che il mese sia fissato il calendario conta' );
LessonExceptions::move( $cl_a, '2031-04-29', '2031-05-02' );
asem_ok( 4000 === $cl_left( $cl_a, $cl_p, '2031-04' ) && 4000 === $cl_left( $cl_a, $cl_p, '2031-05' ), 'a lezioni: una lezione spostata al mese dopo si paga una volta sola, nel suo mese' );
Db::$today_fake = '2031-04-01';
asem_ok( 4000 === $cl_due( $cl_a, $cl_p, '2031-04' ) && 1 === $cl_fixed( $cl_a, '2031-04' ), 'a lezioni: il giorno 1 la prima lettura fissa il mese, senza cron' );
LessonExceptions::cancel( $cl_a, '2031-04-15' );
$cl_acts->update( $cl_a, array( 'fee_cents' => 2000 ) );
asem_ok( 4000 === $cl_due( $cl_a, $cl_p, '2031-04' ) && 4000 === $cl_left( $cl_a, $cl_p, '2031-04' ), 'a lezioni: fissato il mese, calendario e prezzo nuovi non lo cambiano' );
asem_ok( 8000 === $cl_left( $cl_a, $cl_p, '2031-05' ), 'a lezioni: il prezzo nuovo vale dai mesi non fissati' );
$cl_acts->update( $cl_a, array( 'fee_cents' => 1000 ) );

// ---------- Una sola volta, anche con due richieste insieme ----------
$wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO ' . Db::t( 'course_months' ) . ' (activity_id, month, dates, source, fixed_at) VALUES (%d, %s, %s, %s, %s)', $cl_a, '2031-06', '2031-06-03,2031-06-10', 'payment', Db::now() ) );
\AssociazioneSemplice\RequestMemo::put( 'course_months', $cl_a, array(), array() ); // questa richiesta non sa ancora che un'altra ha fissato il mese
asem_ok( array( '2031-06-03', '2031-06-10' ) === CourseMonths::fix( $cl_acts->get( $cl_a ), '2031-06', 'payment' ) && 1 === $cl_fixed( $cl_a, '2031-06' ), 'a lezioni: chi arriva secondo usa le lezioni fissate dal primo' );

// ---------- A mese iniziato, prova, variante, nuovo iscritto a mese fissato, re-iscrizione ----------
Db::$today_fake = '2031-05-13';
$cl_b = $cl_mk( 'Martedì, a mese iniziato', array( 'trial_days' => 1, 'price_lists' => array( array( 'label' => 'Ridotto', 'cents' => 600 ) ) ) );
$cl_q = $cl_man();
asem_ok( 3000 === $cl_acts->course_month_fee( $cl_acts->get( $cl_b ), Plugin::people()->get( $cl_q ), '2031-05' ), 'a lezioni: a mese iniziato si propone ciò che resta' );
$cl_acts->enroll( $cl_b, $cl_q, '2031-05' );
asem_ok( 3000 === $cl_left( $cl_b, $cl_q, '2031-05' ) && 3000 === $cl_due( $cl_b, $cl_q, '2031-05' ) && 4000 === $cl_left( $cl_b, $cl_q, '2031-06' ), 'a lezioni: a mese iniziato si pagano le lezioni che restano, poi il mese pieno' );
$cl_r = $cl_man();
\AssociazioneSemplice\Trials::add( $cl_b, $cl_r, '2031-05-06' );
$cl_acts->enroll( $cl_b, $cl_r, '2031-05' );
asem_ok( 4000 === $cl_left( $cl_b, $cl_r, '2031-05' ), 'a lezioni: chi ha provato paga dalla lezione di prova' );
$cl_s = $cl_man();
$cl_acts->enroll( $cl_b, $cl_s, '2031-06' );
$cl_acts->set_enrollment_price( $cl_b, $cl_s, \AssociazioneSemplice\PriceLists::for_activity( $cl_acts->get( $cl_b ) )[0]['key'] );
asem_ok( 2400 === $cl_left( $cl_b, $cl_s, '2031-06' ), 'a lezioni: la variante è il prezzo di una lezione' );
Db::$today_fake = '2031-05-28';
$cl_t = $cl_man();
$cl_acts->enroll( $cl_b, $cl_t, '2031-05' );
asem_ok( 0 === $cl_left( $cl_b, $cl_t, '2031-05' ) && 0 === $cl_due( $cl_b, $cl_t, '2031-05' ), 'a lezioni: senza lezioni rimaste non si paga nulla' );
$wpdb->delete( Db::t( 'enrollments' ), array( 'activity_id' => $cl_b, 'person_id' => $cl_q ) );
$cl_acts->enroll( $cl_b, $cl_q, '2031-05' );
asem_ok( 0 === $cl_left( $cl_b, $cl_q, '2031-05' ), 'a lezioni: iscrizione tolta senza versamenti e rifatta, si pagano le lezioni che restano adesso' );

// ---------- L'incasso fissa il mese; non si incassa più del dovuto ----------
$cl_d    = $cl_mk( 'Martedì, incasso in anticipo' );
$cl_u    = $cl_man();
$cl_cash = Plugin::ledger()->default_account_for( 'cash' );
$cl_acts->enroll( $cl_d, $cl_u, '2031-06' );
$cl_pay = function ( int $cents ) use ( $cl_cash, $cl_d, $cl_u ) {
	Plugin::ledger()->record_receipt( array( 'date' => current_time( 'Y-m-d' ), 'account_id' => (int) $cl_cash['id'], 'method' => 'cash', 'person_id' => $cl_u, 'check_due' => true, 'lines' => array( array( 'category_id' => Plugin::ledger()->category_id_of_kind( 'activity_fee' ), 'amount_cents' => $cents, 'activity_id' => $cl_d, 'competence_month' => '2031-06' ) ) ) );
};
asem_ok( '' !== (string) asem_throws( function () use ( $cl_pay ) { $cl_pay( 4100 ); } ), 'a lezioni: non si incassa più del dovuto' );
$cl_pay( 4000 );
asem_ok( 1 === $cl_fixed( $cl_d, '2031-06' ) && 0 === $cl_left( $cl_d, $cl_u, '2031-06' ), 'a lezioni: il primo incasso fissa il mese, anche in anticipo' );
asem_ok( '' !== (string) asem_throws( function () use ( $cl_acts, $cl_d ) { $cl_acts->update( $cl_d, array( 'billing' => 'monthly' ) ); } ) && 'lessons' === $cl_acts->get( $cl_d )['billing'], 'a lezioni: con incassi il modo di pagamento non si cambia' );
asem_ok( '' !== (string) asem_throws( function () use ( $cl_mk ) { $cl_mk( 'Senza orari', array( 'lesson_slots' => array() ) ); } ), 'a lezioni: servono i giorni delle lezioni' );

// ---------- Due connessioni vere: la lettura dentro una transazione vede la riga dell'altra ----------
$cl_g  = $cl_mk( 'Martedì, due connessioni' );
$cl_g2 = $cl_man();
$cl_acts->enroll( $cl_g, $cl_g2, '2031-06' );
$cl_db2 = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
$wpdb->query( 'SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ' );
$wpdb->query( 'START TRANSACTION' );
$wpdb->get_var( 'SELECT COUNT(*) FROM ' . Db::t( 'course_months' ) ); // da qui l'istantanea di questa transazione è ferma
$wpdb->get_var( 'SELECT COUNT(*) FROM ' . Db::t( 'course_month_dues' ) );
$cl_b1 = $cl_db2->query( $cl_db2->prepare( 'INSERT INTO ' . Db::t( 'course_months' ) . ' (activity_id, month, dates, source, fixed_at) VALUES (%d, %s, %s, %s, %s)', $cl_g, '2031-06', '2031-06-03,2031-06-10,2031-06-17', 'payment', Db::now() ) );
$cl_e1 = $cl_db2->last_error;
$cl_b2 = $cl_db2->query( $cl_db2->prepare( 'INSERT INTO ' . Db::t( 'course_month_dues' ) . ' (activity_id, person_id, month, price_cents, lessons, due_cents, created_at) VALUES (%d, %d, %s, 1000, 3, 3000, %s)', $cl_g, $cl_g2, '2031-06', Db::now() ) );
$cl_iso = (string) $wpdb->get_var( 'SELECT @@transaction_isolation' ) . ' ' . (string) $wpdb->get_var( 'SELECT VERSION()' );
$cl_old   = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Db::t( 'course_months' ) . ' WHERE activity_id = %d', $cl_g ) );
$cl_busy  = '';
$cl_dates = array();
$cl_here  = 0;
try {
	$cl_dates = CourseMonths::fix( $cl_acts->get( $cl_g ), '2031-06', 'payment' );
	$cl_here  = $cl_left( $cl_g, $cl_g2, '2031-06' );
} catch ( \InvalidArgumentException $e ) {
	$cl_busy = $e->getMessage(); // il database non lascia leggere la riga dell'altra connessione in questa transazione: ci si ferma e si riprova
}
$wpdb->query( 'ROLLBACK' );
\AssociazioneSemplice\RequestMemo::forget();
$cl_retry = count( CourseMonths::fix( $cl_acts->get( $cl_g ), '2031-06', 'payment' ) );
asem_ok( 0 === $cl_old && ( ( 3 === count( $cl_dates ) && 3000 === $cl_here ) || CourseMonths::busy_message() === $cl_busy ) && 3 === $cl_retry && 3000 === $cl_left( $cl_g, $cl_g2, '2031-06' ) && 1 === $cl_fixed( $cl_g, '2031-06' ), 'a lezioni: due connessioni, mai un numero diverso da quello dell\'altra: o lo legge o si ferma e al nuovo tentativo lo legge (' . $cl_old . ', ' . count( $cl_dates ) . ', ' . $cl_here . ', ' . ( '' === $cl_busy ? 'letto' : 'fermato' ) . '; B: ' . var_export( $cl_b1, true ) . ' ' . $cl_e1 . ' ' . var_export( $cl_b2, true ) . '; ' . $cl_iso . ')' );

// ---------- Ricalcola il mese: calendario sistemato dopo la prima apertura ----------
Db::$today_fake = '2031-05-28';
$cl_h  = $cl_mk( 'Martedì, calendario da sistemare' );
$cl_h1 = $cl_man();
$cl_acts->enroll( $cl_h, $cl_h1, '2031-05', '2031-05-01' );
asem_ok( 4000 === $cl_due( $cl_h, $cl_h1, '2031-05' ) && 1 === $cl_fixed( $cl_h, '2031-05' ), 'a lezioni: la prima apertura fissa quattro lezioni' );
$cl_acts->update( $cl_h, array( 'lesson_slots' => array( array( 'type' => 'weekly', 'day' => 2, 'start' => '18:00', 'end' => '19:00' ), array( 'type' => 'weekly', 'day' => 5, 'start' => '18:00', 'end' => '19:00' ) ) ) );
asem_ok( 4000 === $cl_due( $cl_h, $cl_h1, '2031-05' ) && 9 === CourseMonths::recalc( $cl_h, '2031-05' ) && 9000 === $cl_due( $cl_h, $cl_h1, '2031-05' ), 'a lezioni: «Ricalcola» rifà le lezioni con il calendario di adesso' );
asem_ok( 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Db::t( 'audit_log' ) . " WHERE action = 'course.month_recalculated' AND object_id = %d", $cl_h ) ), 'a lezioni: il ricalcolo resta nel registro azioni' );
$cl_render = asem_render( array( \AssociazioneSemplice\Admin\ActivitiesPage::class, 'render_detail' ), 'Ricalcola', array( 'id' => $cl_h ) );
$wpdb->insert( Db::t( 'transactions' ), array( 'tx_date' => '2031-05-28', 'type' => 'income', 'amount_cents' => 100, 'account_id' => (int) $cl_cash['id'], 'method' => 'cash', 'category_id' => Plugin::ledger()->category_id_of_kind( 'activity_fee' ), 'activity_id' => $cl_h, 'person_id' => $cl_h1, 'competence_month' => '2031-05', 'created_at' => Db::now() ) );
$cl_tid = (int) $wpdb->insert_id;
asem_ok( '' !== (string) asem_throws( function () use ( $cl_h ) { CourseMonths::recalc( $cl_h, '2031-05' ); } ), 'a lezioni: con un versamento non si ricalcola' );
$wpdb->delete( Db::t( 'transactions' ), array( 'id' => $cl_tid ) );
asem_ok( '' !== (string) asem_throws( function () use ( $cl_h ) { CourseMonths::recalc( $cl_h, '2031-06' ); } ) && '' !== (string) asem_throws( function () { CourseMonths::recalc( 0, '2031-05' ); } ), 'a lezioni: non si ricalcola un mese non fissato o un corso che non c\'è' );
asem_ok( array( \AssociazioneSemplice\EmployeeRules::COURSES ) === \AssociazioneSemplice\Employees::activities_need( array( 'activity_id' => $cl_h ), 'asem_course_month_recalc' )['all'] && in_array( 'asem_course_month_recalc', \AssociazioneSemplice\EmployeeRules::activity_actions(), true ), 'a lezioni: «Ricalcola» chiede il permesso Corsi' );

// ---------- Leggere senza un dovuto reale non scrive ----------
$cl_i     = $cl_mk( 'Martedì, solo letture' );
$cl_count = function () use ( $wpdb ): int {
	return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Db::t( 'course_months' ) ) + (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Db::t( 'course_month_dues' ) );
};
$cl_c0 = $cl_count();
wp_set_current_user( 0 );
foreach ( array( '2031-03', '2031-05', '2031-06' ) as $cl_mm ) {
	$cl_acts->course_month_fee( $cl_acts->get( $cl_i ), array( 'id' => 0, 'type' => 'ordinary' ), $cl_mm );
	CourseMonths::peek( $cl_acts->get( $cl_i ), $cl_mm );
	CourseMonths::first_lesson( $cl_acts->get( $cl_i ), $cl_mm );
}
do_shortcode( '[associazionesemplice_attivita]' );
wp_set_current_user( 1 );
asem_render( array( \AssociazioneSemplice\Admin\ActivitiesPage::class, 'render_detail' ), 'Lezioni del mese', array( 'id' => $cl_i ) );
asem_ok( $cl_c0 === $cl_count(), 'a lezioni: visite pubbliche, proposte e scheda senza iscritti non fissano nulla' );

// ---------- Uscita a metà mese, passaggio da ospite a socio ----------
$cl_j  = $cl_mk( 'Martedì, uscite', array( 'guest_fee_cents' => 1500 ) );
$cl_j1 = $cl_man();
$cl_acts->enroll( $cl_j, $cl_j1, '2031-05', '2031-05-01' );
$cl_j2 = (int) Plugin::people()->create( array( 'type' => 'guest', 'host_person_id' => $cl_j1, 'first_name' => 'Ospite', 'last_name' => 'Lezione', 'phone' => '333 4440099' ) );
$cl_acts->enroll( $cl_j, $cl_j2, '2031-05', '2031-05-01' );
asem_ok( 4000 === $cl_due( $cl_j, $cl_j1, '2031-05' ) && 6000 === $cl_due( $cl_j, $cl_j2, '2031-05' ), 'a lezioni: socio e ospite, stesso numero di lezioni e prezzo diverso' );
$wpdb->update( Db::t( 'enrollments' ), array( 'end_month' => '2031-05' ), array( 'activity_id' => $cl_j, 'person_id' => $cl_j1 ) );
$wpdb->update( Db::t( 'people' ), array( 'type' => 'ordinary' ), array( 'id' => $cl_j2 ) );
wp_cache_flush();
Db::$today_fake = '2031-06-10';
asem_ok( 4000 === $cl_due( $cl_j, $cl_j1, '2031-05' ) && 0 === $cl_due( $cl_j, $cl_j1, '2031-06' ), 'a lezioni: chi esce a fine maggio deve maggio e non giugno' );
asem_ok( 6000 === $cl_due( $cl_j, $cl_j2, '2031-05' ) && 4000 === $cl_due( $cl_j, $cl_j2, '2031-06' ), 'a lezioni: da ospite a socio il mese fissato resta, il dopo cambia' );
Db::$today_fake = '2031-05-28';

// ---------- Rimborso e promemoria ----------
$cl_rf = array( 'today' => '2031-05-14', 'now' => '10:00', 'held' => array( '2031-05-06', '2031-05-13' ), 'sessions' => array(), 'course' => $cl_acts->get( $cl_j ) );
$cl_rm = array( 'amount_cents' => 4000, 'session_id' => 0, 'competence_month' => '2031-05', 'person_id' => $cl_j1 );
asem_ok( 2000 === \AssociazioneSemplice\ActivityReset::refund_cents( $cl_rm, $cl_rf ), 'a lezioni: rimborso, due lezioni tenute su quattro' );
LessonExceptions::cancel( $cl_j, '2031-05-20' );
asem_ok( 2000 === \AssociazioneSemplice\ActivityReset::refund_cents( $cl_rm, $cl_rf ), 'a lezioni: rimborso sulle lezioni fissate, anche se il calendario cambia dopo' );
$cl_k     = $cl_mk( 'Martedì, promemoria' );
$cl_k1    = $cl_man();
$wpdb->update( Db::t( 'people' ), array( 'email' => 'lezione.promemoria@example.com' ), array( 'id' => $cl_k1 ) );
wp_cache_flush();
$cl_acts->enroll( $cl_k, $cl_k1, '2031-05', '2031-05-01' );
$cl_mails = array();
$cl_hook  = function ( $r, $atts ) use ( &$cl_mails ) {
	$cl_mails[] = wp_json_encode( $atts['to'], JSON_UNESCAPED_UNICODE ) . ' ' . $atts['message'];
	return true;
};
add_filter( 'pre_wp_mail', $cl_hook, 1, 2 );
$cl_set = array( 'reminders_enabled' => Settings::get( 'reminders_enabled' ), 'reminders_dues' => Settings::get( 'reminders_dues' ), 'reminders_membership' => Settings::get( 'reminders_membership' ), 'reminders_events' => Settings::get( 'reminders_events' ) );
Settings::update( array( 'reminders_enabled' => 1, 'reminders_dues' => 1, 'reminders_membership' => 0, 'reminders_events' => 0 ) );
\AssociazioneSemplice\Reminders::run( '2031-05-28' );
Settings::update( $cl_set );
remove_filter( 'pre_wp_mail', $cl_hook, 1 );
$cl_got = implode( "\n", array_filter( $cl_mails, function ( $m ) { return false !== strpos( $m, 'lezione.promemoria' ); } ) );
asem_ok( false !== strpos( $cl_got, '40,00' ) && 1 === $cl_fixed( $cl_k, '2031-06' ), 'a lezioni: il promemoria del mese dopo chiede le lezioni di quel mese e lo fissa' );

// ---------- Anno fiscale chiuso: si legge, non si scrive ----------
$cl_c  = $cl_mk( 'Martedì, anno chiuso' );
$cl_v  = $cl_man();
$cl_acts->enroll( $cl_c, $cl_v, '2031-05', '2031-05-01' );
$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Db::t( 'course_months' ) . ' WHERE activity_id = %d', $cl_c ) );
$cl_fy = \AssociazioneSemplice\FiscalYears::year_of( '2031-05-01' );
\AssociazioneSemplice\FiscalYears::ensure( $cl_fy );
$wpdb->update( Db::t( 'fiscal_years' ), array( 'status' => 'closed' ), array( 'year' => $cl_fy ) );
\AssociazioneSemplice\RequestMemo::forget();
wp_cache_flush();
$cl_read = $cl_left( $cl_c, $cl_v, '2031-05' );
$cl_rows = $cl_fixed( $cl_c, '2031-05' );
$wpdb->delete( Db::t( 'fiscal_years' ), array( 'year' => $cl_fy ) );
\AssociazioneSemplice\RequestMemo::forget();
wp_cache_flush();
asem_ok( 4000 === $cl_read && 0 === $cl_rows, 'a lezioni: in un anno chiuso si legge senza scrivere' );

// ---------- Il mese in corso è dovuto dalla prima lezione vera ----------
$cl_e = (int) $cl_acts->create( array( 'name' => 'Giovedì con festivo', 'social_year' => $cl_lab, 'kind' => 'course', 'billing' => 'monthly', 'fee_cents' => 4000, 'first_month' => 'full', 'red_days' => 0, 'lesson_slots' => array( array( 'type' => 'weekly', 'day' => 4, 'start' => '18:00', 'end' => '19:00' ) ) ) );
Db::$today_fake = '2031-04-30';
$cl_acts->update( $cl_e, array( 'red_days' => 1 ) );
$cl_w = $cl_man();
$cl_acts->enroll( $cl_e, $cl_w, '2031-05' );
Db::$today_fake = '2031-05-02'; // il 1° maggio 2031 è giovedì e festivo: la prima lezione vera è l'8
$cl_x = $cl_sum( $cl_e, $cl_w );
Db::$today_fake = '2031-05-08';
$cl_y = $cl_sum( $cl_e, $cl_w );
Db::$today_fake = '';
asem_ok( 0 === (int) $cl_x['total_due'] && '2031-05-08' === ( $cl_x['upcoming']['date'] ?? '' ) && 4000 === (int) $cl_y['total_due'], 'scadenza: con la prima lezione in un giorno festivo il mese è dovuto dalla prima lezione vera' );

// ---------- Corsi di prima, copia, azzeramento, cancellazione ----------
asem_ok( 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Db::t( 'course_months' ) . ' m JOIN ' . Db::t( 'activities' ) . " a ON a.id = m.activity_id WHERE a.billing <> 'lessons'" ), 'a lezioni: i corsi con altri pagamenti non hanno mesi fissati' );
foreach ( array( 'asem_course_months', 'asem_course_month_dues' ) as $cl_tb ) {
	asem_ok( in_array( $cl_tb, \AssociazioneSemplice\Backup::tables(), true ) && in_array( $cl_tb, \AssociazioneSemplice\Reset::tables( \AssociazioneSemplice\Reset::DATA ), true ), 'a lezioni: ' . $cl_tb . ' è nella copia e nell\'azzeramento' );
}
asem_ok( false !== strpos( (string) wp_json_encode( \AssociazioneSemplice\Privacy::export( $cl_p ) ), 'Martedì a lezione' ) || false !== strpos( (string) wp_json_encode( \AssociazioneSemplice\Privacy::export( $cl_p ), JSON_UNESCAPED_UNICODE ), 'Martedì a lezione' ), 'a lezioni: nei dati scaricati' );
asem_render( array( \AssociazioneSemplice\Admin\ActivitiesPage::class, 'render_detail' ), 'Lezioni del mese', array( 'id' => $cl_a ) );
asem_ok( 'a lezione' === \AssociazioneSemplice\ActivityKind::fee_unit( 'course', 'lessons' ), 'a lezioni: la dicitura è «a lezione»' );

// ---------- «Riprova» nell'area soci: una frase chiara, non un errore tecnico ----------
$cl_fu = \AssociazioneSemplice\Flash::url( home_url( '/area/' ), 'asemf', '', CourseMonths::busy_message() );
parse_str( (string) wp_parse_url( $cl_fu, PHP_URL_QUERY ), $cl_fq );
$cl_og = $_GET;
$_GET  = $cl_fq;
$cl_fl = \AssociazioneSemplice\Frontend\Views::flash();
$_GET  = $cl_og;
asem_ok( false !== strpos( $cl_fl, 'riprova tra qualche secondo' ) && false === strpos( $cl_fl, 'Qualcosa non ha funzionato' ) && false === stripos( $cl_fl, 'database' ), 'a lezioni: nell\'area soci il socio legge «riprova tra qualche secondo»' );
