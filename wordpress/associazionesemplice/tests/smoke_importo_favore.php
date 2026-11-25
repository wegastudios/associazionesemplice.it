<?php
/**
 * Corsi a lezioni: lezione annullata in un mese già fissato. Esce dal dovuto; chi aveva pagato ha l'importo a favore, che si scala dal rinnovo, si
 * restituisce o si chiude scaduto; il recupero concordato la lascia dovuta. Quota dell'istruttore: ripresa all'annullamento, rimessa allo scalo.
 */

defined( 'ABSPATH' ) || exit;

use AssociazioneSemplice\CourseCredits;
use AssociazioneSemplice\Db;
use AssociazioneSemplice\LessonExceptions;
use AssociazioneSemplice\Plugin;
use AssociazioneSemplice\Settings;

global $wpdb;
wp_set_current_user( 1 );
$cf_acts = Plugin::activities();
$cf_n    = 0;
$cf_man  = function ( string $email = '' ) use ( &$cf_n ): int {
	$cf_n++;
	return (int) Plugin::people()->create( array( 'type' => 'ordinary', 'card_number' => '55' . $cf_n, 'first_name' => 'Favore', 'last_name' => 'Persona ' . $cf_n, 'email' => $email, 'birth_date' => '1985-01-01' ) );
};
$cf_lab  = Settings::social_year( '2031-05-13' )->label();
$cf_ins  = $cf_man();
$cf_c    = (int) $cf_acts->create( array( 'name' => 'Martedì, lezioni annullate', 'social_year' => $cf_lab, 'kind' => 'course', 'billing' => 'lessons', 'fee_cents' => 1000, 'instructor_person_id' => $cf_ins, 'fund_mode' => 'percent', 'fund_value' => 5000, 'lesson_slots' => array( array( 'type' => 'weekly', 'day' => 2, 'start' => '18:00', 'end' => '19:00' ) ) ) );
$cf_cash = Plugin::ledger()->default_account_for( 'cash' );
$cf_pay  = function ( int $pid, string $month, int $cents ) use ( $cf_cash, $cf_c ) {
	Plugin::ledger()->record_receipt( array( 'date' => current_time( 'Y-m-d' ), 'account_id' => (int) $cf_cash['id'], 'method' => 'cash', 'person_id' => $pid, 'check_due' => true, 'lines' => array( array( 'category_id' => Plugin::ledger()->category_id_of_kind( 'activity_fee' ), 'amount_cents' => $cents, 'activity_id' => $cf_c, 'competence_month' => $month ) ) ) );
};
$cf_left = function ( int $pid, string $m ) use ( $cf_acts, $cf_c ): int {
	return (int) $cf_acts->enrollment_left( $cf_c, $pid, $m );
};
$cf_fund = function () use ( $cf_c ): int {
	$id = Plugin::funds()->instructor_fund_id( $cf_c );
	$f  = $id ? Plugin::funds()->get( $id ) : null;
	return $f ? (int) $f['balance'] : 0;
};
$cf_mails = array();
$cf_hook  = function ( $r, $atts ) use ( &$cf_mails ) {
	$cf_mails[] = wp_json_encode( $atts['to'] ) . ' ' . $atts['subject'] . ' ' . $atts['message'];
	return true;
};
add_filter( 'pre_wp_mail', $cf_hook, 1, 2 );
$cf_rem = Settings::get( 'reminders_enabled' );
Settings::update( array( 'reminders_enabled' => 1 ) );

// ---------- Maggio 2031 (martedì 6, 13, 20, 27): chi ha pagato e chi no ----------
Db::$today_fake = '2031-05-02';
$cf_p = $cf_man( 'favore.pagato@example.com' );
$cf_q = $cf_man( 'favore.nonpagato@example.com' );
foreach ( array( $cf_p, $cf_q ) as $cf_x ) {
	$cf_acts->enroll( $cf_c, $cf_x, '2031-05', '2031-05-01' );
}
$cf_pay( $cf_p, '2031-05', 4000 );
$cf_f0 = $cf_fund();
asem_ok( 2000 === $cf_f0, 'importo a favore: la quota dell\'istruttore è accantonata sull\'incasso (' . $cf_f0 . ')' );
LessonExceptions::cancel( $cf_c, '2031-05-13' );
asem_ok( 2 === count( CourseCredits::of_activity( $cf_c ) ), 'importo a favore: una riga per ogni iscritto per cui la lezione contava' );
asem_ok( 0 === $cf_left( $cf_p, '2031-05' ) && 3000 === $cf_left( $cf_q, '2031-05' ), 'importo a favore: la lezione esce dal dovuto del mese, per chi ha pagato e per chi no' );
asem_ok( 1500 === $cf_fund(), 'importo a favore: la quota dell\'istruttore su quella lezione torna all\'associazione, solo per chi aveva pagato' );
$cf_got = implode( "\n", $cf_mails );
asem_ok( false !== strpos( $cf_got, 'favore.pagato' ) && false !== strpos( $cf_got, '10,00' ) && false === strpos( $cf_got, 'favore.nonpagato' ), 'importo a favore: avviso a chi aveva pagato, con importo e scadenza; a chi non aveva pagato no' );
$cf_pr = null;
foreach ( CourseCredits::of_activity( $cf_c ) as $cf_r ) {
	if ( (int) $cf_r['person_id'] === $cf_p ) {
		$cf_pr = $cf_r;
	}
}
asem_ok( $cf_pr && 1000 === CourseCredits::available( $cf_pr ), 'importo a favore: 10,00 a favore di chi aveva pagato' );

// ---------- Si scala dal rinnovo; dopo, la lezione non si rimette ----------
asem_ok( 3000 === $cf_left( $cf_p, '2031-06' ) && 4000 === $cf_left( $cf_q, '2031-06' ), 'importo a favore: il mese dopo si paga meno di quanto a favore' );
$cf_pay( $cf_p, '2031-06', 3000 );
asem_ok( 0 === $cf_left( $cf_p, '2031-06' ) && 0 === CourseCredits::available( $cf_pr ) && 1 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Db::t( 'course_credit_uses' ) ), 'importo a favore: con l\'incasso del rinnovo lo scalo resta scritto' );
asem_ok( 1500 + 3000 * 0.5 + 500 === (float) $cf_fund(), 'importo a favore: allo scalo la quota torna all\'istruttore (' . $cf_fund() . ')' );
asem_ok( '' !== (string) asem_throws( function () use ( $cf_c ) { LessonExceptions::restore( $cf_c, '2031-05-13' ); } ), 'importo a favore: scalato, la lezione non si rimette in calendario' );

// ---------- Recupero concordato, tolto; restituzione; chi non ha pagato non ha nulla da restituire ----------
LessonExceptions::cancel( $cf_c, '2031-05-20' );
$cf_r2 = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Db::t( 'course_credits' ) . ' WHERE activity_id = %d AND person_id = %d AND lesson_date = %s', $cf_c, $cf_p, '2031-05-20' ), ARRAY_A );
$cf_fa = $cf_fund();
CourseCredits::set_makeup( (int) $cf_r2['id'], $cf_c, '2031-05-27' );
asem_ok( 0 === CourseCredits::available( $cf_r2 ) && $cf_fa + 500 === $cf_fund(), 'importo a favore: con il recupero la lezione resta dovuta e la quota torna all\'istruttore' );
asem_ok( '' !== (string) asem_throws( function () use ( $cf_r2, $cf_c ) { CourseCredits::set_makeup( (int) $cf_r2['id'], $cf_c, '2031-05-28' ); } ), 'importo a favore: il recupero va in un giorno con lezione' );
CourseCredits::set_makeup( (int) $cf_r2['id'], $cf_c, '' );
asem_ok( 1000 === CourseCredits::available( $cf_r2 ) && $cf_fa === $cf_fund(), 'importo a favore: recupero saltato, torna l\'importo a favore e la quota si riprende una volta sola' );
asem_ok( 1000 === CourseCredits::close( (int) $cf_r2['id'], $cf_c, 'refunded' ) && 0 === CourseCredits::available( $cf_r2 ), 'importo a favore: restituito a mano' );
$cf_q2 = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Db::t( 'course_credits' ) . ' WHERE activity_id = %d AND person_id = %d AND lesson_date = %s', $cf_c, $cf_q, '2031-05-20' ), ARRAY_A );
asem_ok( '' !== (string) asem_throws( function () use ( $cf_q2, $cf_c ) { CourseCredits::close( (int) $cf_q2['id'], $cf_c, 'refunded' ); } ) && 2000 === $cf_left( $cf_q, '2031-05' ), 'importo a favore: chi non aveva pagato deve meno, ma non ha nulla da restituire' );

// ---------- Scadenza: fine del primo mese dell'anno fiscale dopo; si chiude dalla Bacheca ----------
$cf_s = $cf_man();
$cf_acts->enroll( $cf_c, $cf_s, '2031-05', '2031-05-01' );
$cf_pay( $cf_s, '2031-05', 2000 ); // il 13 e il 20 sono già tolti: due lezioni
LessonExceptions::cancel( $cf_c, '2031-05-27' );
$cf_sr = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Db::t( 'course_credits' ) . ' WHERE activity_id = %d AND person_id = %d AND lesson_date = %s', $cf_c, $cf_s, '2031-05-27' ), ARRAY_A );
$cf_exp = CourseCredits::expires_on( '2031-05-27' );
asem_ok( $cf_sr && 1000 === CourseCredits::available( $cf_sr ) && substr( $cf_exp, 0, 4 ) === (string) ( \AssociazioneSemplice\FiscalYears::year_of( '2031-05-27' ) + 1 ), 'importo a favore: vale fino al ' . $cf_exp );
asem_ok( '' !== (string) asem_throws( function () use ( $cf_sr, $cf_c ) { CourseCredits::close( (int) $cf_sr['id'], $cf_c, 'expired' ); } ), 'importo a favore: prima della scadenza non si chiude come scaduto' );
Db::$today_fake = gmdate( 'Y-m-d', strtotime( $cf_exp . ' -10 days' ) );
$cf_mails = array();
\AssociazioneSemplice\Reminders::run( Db::today() );
asem_ok( false !== strpos( implode( "\n", $cf_mails ), 'in scadenza' ) || '' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT email FROM ' . Db::t( 'people' ) . ' WHERE id = %d', $cf_s ) ), 'importo a favore: avviso prima della scadenza (se c\'è un indirizzo)' );
Db::$today_fake = gmdate( 'Y-m-d', strtotime( $cf_exp . ' +1 day' ) );
$cf_list = CourseCredits::expired_open();
asem_ok( 1 === count( array_filter( $cf_list, function ( $r ) use ( $cf_s ) { return (int) $r['person_id'] === $cf_s; } ) ), 'importo a favore: scaduto, è nell\'elenco da chiudere' );
$cf_tx0 = (string) wp_json_encode( $wpdb->get_row( 'SELECT COUNT(*) n, SUM(amount_cents) s FROM ' . Db::t( 'transactions' ), ARRAY_A ) );
asem_render( array( \AssociazioneSemplice\Admin\DashboardPage::class, 'render' ), 'Importi a favore scaduti da chiudere' );
$cf_x = new \ReflectionMethod( \AssociazioneSemplice\Admin\Actions::class, 'course_credits_expire' );
$cf_x->setAccessible( true );
asem_ok( '' !== (string) asem_throws( function () use ( $cf_x ) { $cf_x->invoke( null, array() ); } ), 'importo a favore: senza la conferma non si chiude' );
$cf_x->invoke( null, array( 'confirm' => 1 ) );
asem_ok( array() === CourseCredits::expired_open() && 0 === $cf_left( $cf_s, '2031-05' ) && $cf_tx0 === (string) wp_json_encode( $wpdb->get_row( 'SELECT COUNT(*) n, SUM(amount_cents) s FROM ' . Db::t( 'transactions' ), ARRAY_A ) ), 'importo a favore: chiuso come contributo per le attività, il mese risulta pagato e la prima nota non cambia' );

// ---------- Mesi non fissati, chiusura dell'anno, dati scaricati, iscrizione cancellata ----------
Db::$today_fake = '2031-05-02';
LessonExceptions::cancel( $cf_c, '2031-07-08' );
asem_ok( 0 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Db::t( 'course_credits' ) . ' WHERE activity_id = %d AND lesson_date = %s', $cf_c, '2031-07-08' ) ), 'importo a favore: in un mese non ancora fissato la lezione esce da sola dal conteggio, nessuna riga' );
ob_start();
\AssociazioneSemplice\YearEndCheck::close_form( 2031 );
ob_get_clean();
asem_ok( false !== strpos( (string) wp_json_encode( \AssociazioneSemplice\Privacy::export( $cf_p ), JSON_UNESCAPED_UNICODE ), 'lezioni annullate' ) || isset( \AssociazioneSemplice\Privacy::export( $cf_p )['lezioni_annullate'] ), 'importo a favore: nei dati scaricati' );
asem_render( array( \AssociazioneSemplice\Admin\ActivitiesPage::class, 'render_detail' ), 'Lezioni annullate', array( 'id' => $cf_c ) );
$cf_fb = $cf_fund();
CourseCredits::registration_deleted( $cf_c, $cf_q );
asem_ok( 0 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Db::t( 'course_credits' ) . ' WHERE person_id = %d', $cf_q ) ) && $cf_fb === $cf_fund(), 'importo a favore: con l\'iscrizione cancellata le righe vanno via; chi non aveva pagato non aveva toccato il fondo' );
foreach ( array( 'asem_course_credits', 'asem_course_credit_uses' ) as $cf_t ) {
	asem_ok( in_array( $cf_t, \AssociazioneSemplice\Backup::tables(), true ) && in_array( $cf_t, \AssociazioneSemplice\Reset::tables( \AssociazioneSemplice\Reset::DATA ), true ), 'importo a favore: ' . $cf_t . ' nella copia e nell\'azzeramento' );
}
Db::$today_fake = '';
Settings::update( array( 'reminders_enabled' => $cf_rem ) );
remove_filter( 'pre_wp_mail', $cf_hook, 1 );
