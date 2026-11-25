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
