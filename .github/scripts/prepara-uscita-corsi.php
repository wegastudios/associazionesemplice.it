<?php
/**
 * Dati per le prove nel browser delle schermate nuove dei corsi (workflow «Prove in sandbox», passo «Corsi nuovi nel browser»), a date finte del 2031:
 * corso con prova gratuita, corso a lezioni con lezioni annullate (chi ha pagato e chi no), importi a favore che scadranno, corso pagabile in moneta
 * con un socio e il figlio minorenne. Richiede il modulo mu «asem-data-prova.php» (ASEM_TESTS e la data dall'opzione asem_prova_oggi).
 * Stampa una riga JSON con gli id, le date delle due fasi e l'accesso del socio di prova (utente locale di wp-env, nessun dato vero).
 */
use AssociazioneSemplice\Credits;
use AssociazioneSemplice\Db;
use AssociazioneSemplice\Guardians;
use AssociazioneSemplice\LessonExceptions;
use AssociazioneSemplice\Plugin;
use AssociazioneSemplice\Regulation;
use AssociazioneSemplice\Settings;
use AssociazioneSemplice\Trials;

if ( ! defined( 'ASEM_TESTS' ) ) {
	WP_CLI::error( 'Manca il modulo della data finta (asem-data-prova.php).' );
}
wp_set_current_user( 1 );
$day = function ( string $d ) {
	Db::$today_fake = $d;
	update_option( 'asem_prova_oggi', $d, false );
	\AssociazioneSemplice\RequestMemo::forget();
	wp_cache_flush();
};
$day( '2031-05-02' );
$acts  = Plugin::activities();
$peo   = Plugin::people();
$label = Settings::social_year( '2031-05-14' )->label();
$cash  = Plugin::ledger()->default_account_for( 'cash' );
$tue   = array( array( 'type' => 'weekly', 'day' => 2, 'start' => '18:00', 'end' => '19:00' ) );
$n     = 0;
$man   = function ( string $first, string $last, array $extra = array() ) use ( &$n, $peo ): int {
	$n++;
	$id = (int) $peo->create( $extra + array( 'type' => 'ordinary', 'card_number' => '77' . $n, 'first_name' => $first, 'last_name' => $last, 'email' => 'prova.corsi' . $n . '@example.com', 'birth_date' => '1985-01-01' ) );
	foreach ( array( '2031-05-14', '2032-02-15' ) as $d ) {
		$peo->set_membership( $id, Settings::membership_year( $d )->label(), true );
	}
	return $id;
};
$pay = function ( int $aid, int $pid, string $month ) use ( $acts, $cash ) {
	$due = (int) $acts->enrollment_left( $aid, $pid, $month );
	Plugin::ledger()->record_receipt( array( 'date' => Db::today(), 'account_id' => (int) $cash['id'], 'method' => 'cash', 'person_id' => $pid, 'check_due' => true, 'lines' => array( array( 'category_id' => Plugin::ledger()->category_id_of_kind( 'activity_fee' ), 'amount_cents' => $due, 'activity_id' => $aid, 'competence_month' => $month ) ) ) );
};

// Corso a lezioni con quota dell'istruttore: Uno paga maggio, Due no; lezioni del 13 e del 27 annullate. Tre paga e ha l'importo a favore del 27.
$ins = (int) $peo->create( array( 'type' => 'volunteer', 'first_name' => 'Istruttrice', 'last_name' => 'Prova', 'email' => 'istruttrice.prova@example.com' ) );
$les = (int) $acts->create( array( 'name' => 'Yoga a lezioni (prova)', 'social_year' => $label, 'kind' => 'course', 'billing' => 'lessons', 'fee_cents' => 1000, 'starts_on' => '2031-05-01', 'instructor_person_id' => $ins, 'fund_mode' => 'percent', 'fund_value' => 5000, 'lesson_slots' => $tue ) );
$uno = $man( 'Uno', 'Pagato' );
$due = $man( 'Due', 'Nonpagato' );
$tre = $man( 'Tre', 'Scadenza' );
foreach ( array( $uno, $due, $tre ) as $p ) {
	$acts->enroll( $les, $p, '2031-05', '2031-05-01' );
}
$pay( $les, $uno, '2031-05' );
$pay( $les, $tre, '2031-05' );
LessonExceptions::cancel( $les, '2031-05-13' );
LessonExceptions::cancel( $les, '2031-05-27' );

// Corso con prova gratuita (primo mese in proporzione): Prova fa la prova e non si iscrive, Iscritta la fa e si iscrive dal giorno della prova.
$day( '2031-05-14' );
$tri = (int) $acts->create( array( 'name' => 'Danza con prova (prova)', 'social_year' => $label, 'kind' => 'course', 'billing' => 'monthly', 'first_month' => 'share', 'fee_cents' => 6000, 'trial_days' => 1, 'starts_on' => '2031-05-01', 'lesson_slots' => $tue ) );
$pr1 = $man( 'Prova', 'Senzaiscrizione' );
$pr2 = $man( 'Iscritta', 'Dopolaprova' );
Trials::add( $tri, $pr1, '2031-05-13' );
Trials::add( $tri, $pr2, '2031-05-13' );
$acts->enroll( $tri, $pr2, '2031-05', '2031-05-13' );

// Corso pagabile in moneta: il socio e il figlio minorenne, con il saldo del socio.
$coin = (int) $acts->create( array( 'name' => 'Nuoto in moneta (prova)', 'social_year' => $label, 'kind' => 'course', 'billing' => 'monthly', 'first_month' => 'full', 'fee_cents' => 4000, 'starts_on' => '2031-05-01', 'lesson_slots' => $tue ) );
Credits::set_activity_price( $coin, 40 );
$pass = wp_generate_password( 20, false );
$uid  = (int) wp_insert_user( array( 'user_login' => 'socio.prova.corsi', 'user_email' => 'socio.prova.corsi@example.com', 'user_pass' => $pass, 'role' => Plugin::ROLE_MEMBER ) ); // come i veri soci: niente barra di WordPress
$mem  = $man( 'Socio', 'Moneta', array( 'email' => 'socio.prova.corsi@example.com' ) );
$kid  = $man( 'Figlio', 'Moneta', array( 'email' => '', 'birth_date' => '2020-03-01' ) );
Db::db()->update( Db::t( 'people' ), array( 'wp_user_id' => $uid, 'privacy_consent_at' => Db::now() ), array( 'id' => $mem ) );
Regulation::accept( $mem );
Guardians::link( $kid, $mem );
$acts->enroll( $coin, $mem, '2031-05' );
$acts->enroll( $coin, $kid, '2031-05' );
Credits::adjust( $mem, 100, 'Saldo di prova' );
$acts->enroll( $les, $mem, '2031-05', '2031-05-01' ); // corso a lezioni: nell'area soci «Questo mese: N lezioni, importo»

// Corso per più persone: il socio chiede l'iscrizione per sé, il figlio e un altro socio (cognome e tessera); la segreteria approva tutte.
$grp   = (int) $acts->create( array( 'name' => 'Yoga per più persone (prova)', 'social_year' => $label, 'kind' => 'course', 'billing' => 'monthly', 'first_month' => 'full', 'fee_cents' => 3000, 'starts_on' => '2031-05-01', 'lesson_slots' => $tue ) );
$other = $man( 'Altro', 'Iscrivibile' );
\AssociazioneSemplice\PublicEvents::ensure( $grp );
\AssociazioneSemplice\PublicEvents::publish_pages( array( $grp ) );

$exp = \AssociazioneSemplice\CourseCredits::expires_on( '2031-05-27' );
$day( '2031-05-14' ); // fase 1: le pagine si guardano a questa data
echo wp_json_encode(
	array(
		'phase1'  => '2031-05-14',
		'phase2'  => gmdate( 'Y-m-d', strtotime( $exp . ' +1 day' ) ),
		'lessons' => $les,
		'trial'   => $tri,
		'coin'    => $coin,
		'uno'     => 'Uno Pagato',
		'due'     => 'Due Nonpagato',
		'area'    => \AssociazioneSemplice\Gatekeeper::area_url(),
		'member'  => array( 'login' => 'socio.prova.corsi', 'pass' => $pass ),
		'coins'   => \AssociazioneSemplice\CoinName::plural(),
		'group'   => array( 'id' => $grp, 'page' => \AssociazioneSemplice\PublicEvents::page_url( $grp ), 'last' => 'Iscrivibile', 'card' => (string) $peo->get( $other )['card_number'] ),
	)
) . "\n";
