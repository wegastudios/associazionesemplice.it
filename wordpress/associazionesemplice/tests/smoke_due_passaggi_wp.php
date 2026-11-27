<?php
/**
 * Verifica in due passaggi per gli utenti WordPress e obbligo dell'ente (PR 2, decisioni del titolare 26/11/2026): login di WordPress fermo senza
 * codice, password per le applicazioni rifiutate, obbligo separato per dipendenti e segreteria e per i soci, pagina dell'attivazione subito,
 * spegnimento dell'amministratore dalla scheda, amministratore di WordPress non toccato, coesistenza con un altro plugin. Si carica da smoke.php.
 */

defined( 'ABSPATH' ) || exit;

use AssociazioneSemplice\Admin\TwoFactorPage;
use AssociazioneSemplice\Counters;
use AssociazioneSemplice\Db;
use AssociazioneSemplice\Limits;
use AssociazioneSemplice\MemberLogin;
use AssociazioneSemplice\MemberSession;
use AssociazioneSemplice\Plugin;
use AssociazioneSemplice\Settings;
use AssociazioneSemplice\TwoFactor;
use AssociazioneSemplice\TwoFactorWp;

global $wpdb;

$dw_totp = function ( string $b32, int $ts ): string { // la formula della RFC 6238, scritta qui a parte
	$map  = array_flip( str_split( 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567' ) );
	$bits = '';
	foreach ( str_split( $b32 ) as $ch ) {
		$bits .= str_pad( decbin( $map[ $ch ] ), 5, '0', STR_PAD_LEFT );
	}
	$key = '';
	foreach ( str_split( $bits, 8 ) as $b ) {
		if ( 8 === strlen( $b ) ) {
			$key .= chr( bindec( $b ) );
		}
	}
	$h = hash_hmac( 'sha1', pack( 'J', intdiv( $ts, 30 ) ), $key, true );
	$o = ord( substr( $h, -1 ) ) & 15;
	$v = unpack( 'N', substr( $h, $o, 4 ) )[1] & 0x7fffffff;
	return sprintf( '%06d', $v % 1000000 );
};
$dw_mail = array();
$dw_hook = function ( $null, $atts ) use ( &$dw_mail ) {
	$dw_mail[] = $atts;
	return true;
};
add_filter( 'pre_wp_mail', $dw_hook, 10, 2 );
$dw_flags = function ( int $staff, int $members ): void {
	$l = (array) Settings::get( 'limits' );
	$l['tfa_required_staff']   = $staff;
	$l['tfa_required_members'] = $members;
	Settings::update( array( 'limits' => $l ) );
};
$dw_audit = function ( string $action, int $uid ) use ( $wpdb ): int {
	return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Db::t( 'audit_log' ) . ' WHERE action = %s AND object_type = %s AND object_id = %d', $action, 'user', $uid ) );
};
$dw_now = 1950000010;
TwoFactor::$clock = $dw_now;
\AssociazioneSemplice\Employees::ensure_role();
$dw_pw    = 'Password segreteria 2fa';
$dw_sec   = (int) wp_insert_user( array( 'user_login' => 'dw_segreteria', 'user_pass' => $dw_pw, 'user_email' => 'dw-seg@example.test', 'first_name' => 'Sara', 'role' => Plugin::ROLE_SECRETARY ) );
$dw_emp   = (int) wp_insert_user( array( 'user_login' => 'dw_dipendente', 'user_pass' => wp_generate_password(), 'user_email' => 'dw-dip@example.test', 'role' => Plugin::ROLE_EMPLOYEE ) );
$dw_mem   = (int) wp_insert_user( array( 'user_login' => 'dw_socio', 'user_pass' => wp_generate_password(), 'user_email' => 'dw-socio@example.test', 'role' => Plugin::ROLE_MEMBER ) );
$dw_memp  = (int) Plugin::people()->create( array( 'type' => 'ordinary', 'first_name' => 'Mario', 'last_name' => 'Utente', 'email' => 'dw-socio-scheda@example.test' ) );
$wpdb->update( Db::t( 'people' ), array( 'wp_user_id' => $dw_mem ), array( 'id' => $dw_memp ) );
$dw_admin = (int) wp_insert_user( array( 'user_login' => 'dw_amministratore', 'user_pass' => wp_generate_password(), 'user_email' => 'dw-adm@example.test', 'role' => 'administrator' ) );
wp_cache_flush();
$dw_s = TwoFactor::user( $dw_sec );
asem_ok( $dw_s < 0 && $dw_sec === TwoFactor::user_of( $dw_s ) && 0 === TwoFactor::user_of( 5 ) && '' === TwoFactorWp::other_plugin(), 'due passaggi WordPress: il soggetto di un utente è un numero negativo, nessun altro plugin attivo' );

// ---------- Accensione dal pannello, segreto nelle usermeta, cifrato ----------
wp_set_current_user( $dw_sec );
$dw_body0 = TwoFactorPage::body( wp_get_current_user() );
asem_ok( false !== strpos( $dw_body0, 'value="asem_tfa_wp_start"' ) && false !== strpos( $dw_body0, 'spenta' ), 'due passaggi WordPress: la pagina del pannello offre l\'accensione, spenta di partenza' );
TwoFactorPage::start( array() );
$dw_secret = TwoFactor::pending_secret( $dw_s );
$dw_meta   = (string) get_user_meta( $dw_sec, 'asem_tfa_secret', true );
$dw_body1  = TwoFactorPage::body( wp_get_current_user() );
asem_ok( 32 === strlen( $dw_secret ) && 0 === strpos( $dw_meta, 'enc1:' ) && false === strpos( $dw_meta, $dw_secret ) && false !== strpos( $dw_body1, '<svg' ) && false !== strpos( $dw_body1, TwoFactor::grouped( $dw_secret ) ) && false !== strpos( $dw_body1, 'autocomplete="one-time-code"' ), 'due passaggi WordPress: QR e chiave da copiare, segreto cifrato nelle usermeta' );
$dw_at = function ( int $off ) use ( $dw_totp, &$dw_secret, $dw_now ): string {
	return $dw_totp( $dw_secret, $dw_now + $off );
};
TwoFactorPage::confirm( array( 'tfa_code' => $dw_at( 0 ) ) );
preg_match_all( '#<code>(\d{5}-\d{5})</code>#', TwoFactorPage::body( wp_get_current_user() ), $dw_m );
$dw_codes = $dw_m[1];
asem_ok( TwoFactor::is_on( $dw_s ) && 10 === count( $dw_codes ) && 1 === $dw_audit( 'user.tfa_on', $dw_sec ) && false !== strpos( TwoFactorPage::body( wp_get_current_user() ), 'accesa' ), 'due passaggi WordPress: accesa con il primo codice, 10 codici di riserva una volta sola, riga nel registro' );
asem_ok( ! TwoFactor::is_on( TwoFactor::user( $dw_emp ) ) && ! TwoFactor::is_on( $dw_sec ), 'due passaggi WordPress: vale solo per quell\'utente (né per gli altri utenti né per la scheda con lo stesso numero)' );
wp_set_current_user( 0 );

// ---------- Login di WordPress fermo senza codice ----------
$dw_login = wp_signon( array( 'user_login' => 'dw_segreteria', 'user_password' => $dw_pw ) );
asem_ok( is_wp_error( $dw_login ) && TwoFactorWp::REQUIRED === $dw_login->get_error_code() && 0 === get_current_user_id(), 'due passaggi WordPress: con la password giusta il login si ferma e chiede il codice' );
$dw_bad = wp_signon( array( 'user_login' => 'dw_segreteria', 'user_password' => 'sbagliata 123' ) );
asem_ok( is_wp_error( $dw_bad ) && TwoFactorWp::REQUIRED !== $dw_bad->get_error_code(), 'due passaggi WordPress: con la password sbagliata nessun passaggio a metà' );
$dw_tok  = TwoFactorWp::begin( $dw_sec, true );
$dw_form = TwoFactorWp::form_html( $dw_tok, admin_url(), '' );
asem_ok( false !== strpos( $dw_form, 'name="asem_tfa_code"' ) && false !== strpos( $dw_form, 'autocomplete="one-time-code"' ) && false !== strpos( $dw_form, 'inputmode="numeric"' ) && false !== strpos( $dw_form, 'action=asem_tfa' ), 'due passaggi WordPress: modulo del codice nella pagina di accesso (tastiera numerica, completamento dei codici)' );
TwoFactor::$clock = $dw_now + 60;
list( $dw_r1 ) = TwoFactorWp::finish( $dw_tok, '000000' === $dw_at( 60 ) ? '111111' : '000000' );
list( $dw_r2, $dw_u2, $dw_rem ) = TwoFactorWp::finish( $dw_tok, $dw_at( 60 ) );
list( $dw_r3 ) = TwoFactorWp::finish( $dw_tok, $dw_at( 60 ) );
asem_ok( 'wrong' === $dw_r1 && 'ok' === $dw_r2 && $dw_sec === $dw_u2 && true === $dw_rem && 'expired' === $dw_r3 && 1 === $dw_audit( 'user.tfa_login', $dw_sec ), 'due passaggi WordPress: codice sbagliato no, giusto sì (con «Ricordami»), poi il passaggio a metà non vale più' );
$dw_tok2 = TwoFactorWp::begin( $dw_sec, false );
list( $dw_r4 ) = TwoFactorWp::finish( $dw_tok2, $dw_codes[0] );
list( $dw_r5 ) = TwoFactorWp::finish( TwoFactorWp::begin( $dw_sec, false ), $dw_codes[0] );
asem_ok( 'ok' === $dw_r4 && 'wrong' === $dw_r5 && 9 === TwoFactor::backup_left( $dw_s ), 'due passaggi WordPress: un codice di riserva fa entrare una volta sola' );
Counters::delete( 'asem_mt_tfa_' . $dw_s );

// ---------- Password per le applicazioni rifiutate ----------
asem_ok( false === TwoFactorWp::app_passwords_available( true, get_userdata( $dw_sec ) ) && true === TwoFactorWp::app_passwords_available( true, get_userdata( $dw_emp ) ), 'due passaggi WordPress: con la verifica accesa le password per le applicazioni non si possono creare' );
do_action( 'application_password_did_authenticate', get_userdata( $dw_sec ), array() ); // come fa WordPress quando una password per le applicazioni è giusta
$dw_api  = TwoFactorWp::authenticate( get_userdata( $dw_sec ), 'dw_segreteria', 'abcd efgh ijkl mnop qrst uvwx' );
$dw_api2 = TwoFactorWp::authenticate( get_userdata( $dw_emp ), 'dw_dipendente', 'abcd efgh ijkl mnop qrst uvwx' );
asem_ok( is_wp_error( $dw_api ) && TwoFactorWp::API_REFUSED === $dw_api->get_error_code() && $dw_api2 instanceof \WP_User && 1 === $dw_audit( 'user.tfa_api_refused', $dw_sec ), 'due passaggi WordPress: password per le applicazioni rifiutata con la verifica accesa, valida per chi non l\'ha' );

// ---------- Obbligo dell'ente: dipendenti e segreteria, soci, separati ----------
$dw_flags( 1, 0 );
$dw_e = get_userdata( $dw_emp );
asem_ok( TwoFactorWp::required_for_user( $dw_e ) && TwoFactorWp::user_must_setup( $dw_e ) && ! TwoFactorWp::user_must_setup( get_userdata( $dw_sec ) ) && ! TwoFactorWp::required_for_user( get_userdata( $dw_mem ) ), 'due passaggi WordPress: obbligo per dipendenti e segreteria, non per i soci' );
asem_ok( ! TwoFactorWp::required_for_user( get_userdata( $dw_admin ) ) && ! TwoFactorWp::user_must_setup( get_userdata( $dw_admin ) ), 'due passaggi WordPress: l\'amministratore di WordPress non è toccato dall\'obbligo' );
asem_ok( TwoFactorWp::setup_url() === TwoFactorWp::login_redirect( admin_url(), '', $dw_e ) && admin_url() === TwoFactorWp::login_redirect( admin_url(), '', get_userdata( $dw_admin ) ), 'due passaggi WordPress: chi deve accenderla va subito alla pagina per farlo, l\'amministratore no' );
asem_ok( is_wp_error( TwoFactorWp::authenticate( $dw_e, 'dw_dipendente', 'abcd efgh ijkl mnop qrst uvwx' ) ), 'due passaggi WordPress: con l\'obbligo le password per le applicazioni sono rifiutate anche a chi non l\'ha ancora accesa' );
wp_set_current_user( $dw_emp );
asem_ok( false !== strpos( TwoFactorPage::body( $dw_e ), 'L\'associazione richiede la verifica' ), 'due passaggi WordPress: la pagina dice che l\'associazione la richiede' );
wp_set_current_user( $dw_sec );
asem_ok( null !== asem_throws( function () {
	TwoFactorPage::off( array( 'tfa_code' => '123456' ) );
} ) && TwoFactor::is_on( $dw_s ), 'due passaggi WordPress: con l\'obbligo chi la ha non la può spegnere' );
$dw_flags( 0, 1 );
asem_ok( ! TwoFactorWp::required_for_user( $dw_e ) && TwoFactorWp::required_for_user( get_userdata( $dw_mem ) ) && TwoFactorWp::user_must_setup( get_userdata( $dw_mem ) ), 'due passaggi WordPress: obbligo per i soci (anche con utente WordPress), non per i dipendenti' );
// socio con l'accesso proprio: vede solo l'attivazione, le altre azioni dell'area soci sono rifiutate
wp_set_current_user( 0 );
$dw_ownp = (int) Plugin::people()->create( array( 'type' => 'ordinary', 'first_name' => 'Olga', 'last_name' => 'Obbligata', 'email' => 'dw-olga@example.test' ) );
$wpdb->update( Db::t( 'people' ), array( 'wp_user_id' => null ), array( 'id' => $dw_ownp ) );
MemberLogin::set_password( $dw_ownp, 'Password di Olga 1' );
MemberSession::destroy();
asem_ok( $dw_ownp === MemberLogin::login_with_password( 'dw-olga@example.test', 'Password di Olga 1' ) && TwoFactorWp::viewer_must_setup(), 'due passaggi WordPress: con l\'obbligo per i soci, il socio entrato deve accenderla' );
$dw_area = \AssociazioneSemplice\Frontend\Views::profile();
asem_ok( false !== strpos( $dw_area, 'value="asem_front_tfa_start"' ) && false !== strpos( $dw_area, 'richiede la verifica' ) && false === strpos( $dw_area, 'value="asem_front_profile"' ), 'due passaggi WordPress: nell\'area soci vede solo l\'attivazione, non il resto' );
TwoFactor::$clock = $dw_now + 120;
$dw_olga = TwoFactor::start_setup( $dw_ownp );
TwoFactor::confirm_setup( $dw_ownp, $dw_totp( $dw_olga, $dw_now + 120 ) );
TwoFactor::take_new_codes( $dw_ownp );
asem_ok( ! TwoFactorWp::viewer_must_setup() && false !== strpos( \AssociazioneSemplice\Frontend\Views::profile(), 'value="asem_front_profile"' ), 'due passaggi WordPress: accesa, l\'area soci torna com\'era' );
MemberSession::destroy();
$dw_flags( 0, 0 );

// ---------- Spegnimento da parte dell'amministratore ----------
wp_set_current_user( $dw_emp );
asem_ok( null !== asem_throws( function () use ( $dw_sec ) {
	TwoFactorPage::admin_off( array( 'user_id' => $dw_sec ) );
} ) && TwoFactor::is_on( $dw_s ), 'due passaggi WordPress: solo l\'amministratore la spegne a un altro' );
wp_set_current_user( 1 );
$dw_n = count( $dw_mail );
asem_ok( null !== asem_throws( function () {
	TwoFactorPage::admin_off( array( 'user_id' => 1 ) );
} ), 'due passaggi WordPress: nessuno la spegne a sé stesso dalla scheda' );
asem_ok( false !== strpos( TwoFactorPage::user_panel( get_userdata( $dw_sec ), admin_url() ), 'asem_tfa_admin_off' ) && '' === TwoFactorPage::user_panel( get_userdata( $dw_emp ), admin_url() ), 'due passaggi WordPress: nella scheda del dipendente il pulsante c\'è solo se è accesa' );
TwoFactorPage::admin_off( array( 'user_id' => $dw_sec ) );
$dw_to = (string) ( is_array( end( $dw_mail )['to'] ) ? end( $dw_mail )['to'][0] : end( $dw_mail )['to'] );
asem_ok( ! TwoFactor::is_on( $dw_s ) && '' === (string) get_user_meta( $dw_sec, 'asem_tfa_secret', true ) && count( $dw_mail ) === $dw_n + 1 && 'dw-seg@example.test' === $dw_to && 1 <= (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Db::t( 'audit_log' ) . ' WHERE action = %s AND object_id = %d AND details LIKE %s', 'user.tfa_off', $dw_sec, '%admin%' ) ), 'due passaggi WordPress: spenta dall\'amministratore, con email all\'interessato e registro' );
$dw_flags( 1, 0 );
asem_ok( TwoFactorWp::user_must_setup( get_userdata( $dw_sec ) ), 'due passaggi WordPress: con l\'obbligo acceso la dovrà riaccendere al prossimo ingresso' );
$dw_flags( 0, 0 );
// socio con l'accesso proprio: dalla sua scheda
asem_ok( false !== strpos( TwoFactorPage::person_panel( Plugin::people()->get( $dw_ownp ) ), 'asem_tfa_admin_off' ), 'due passaggi WordPress: nella scheda del socio il pulsante «Spegni la verifica in due passaggi»' );
TwoFactorPage::admin_off( array( 'person_id' => $dw_ownp ) );
asem_ok( ! TwoFactor::is_on( $dw_ownp ) && 'dw-olga@example.test' === (string) ( is_array( end( $dw_mail )['to'] ) ? end( $dw_mail )['to'][0] : end( $dw_mail )['to'] ), 'due passaggi WordPress: spenta anche al socio dalla sua scheda, con email' );

// ---------- Utente cancellato: le usermeta se ne vanno con lui ----------
TwoFactor::$clock = $dw_now + 180;
$dw_es = TwoFactor::user( $dw_emp );
$dw_secret = TwoFactor::start_setup( $dw_es );
TwoFactor::confirm_setup( $dw_es, $dw_at( 180 ) );
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $dw_emp );
asem_ok( 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key LIKE %s", $dw_emp, 'asem_tfa_%' ) ), 'due passaggi WordPress: cancellato l\'utente, segreto e codici spariscono' );

// ---------- Coesistenza con un altro plugin di verifica in due passaggi ----------
$dw_secret = TwoFactor::start_setup( $dw_s );
TwoFactor::confirm_setup( $dw_s, $dw_at( 180 ) );
wp_set_current_user( 0 );
if ( ! class_exists( 'Two_Factor_Core', false ) ) {
	eval( 'class Two_Factor_Core {}' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- il plugin «Two Factor» finto: ne basta la classe principale (solo nella prova)
}
$dw_flags( 1, 1 );
$dw_co = TwoFactorWp::authenticate( get_userdata( $dw_sec ), 'dw_segreteria', $dw_pw ); // il filtro «authenticate» lascia passare: il secondo passaggio lo chiede l'altro plugin
asem_ok( 'Two Factor' === TwoFactorWp::other_plugin() && $dw_co instanceof \WP_User && ! TwoFactorWp::required_for_user( get_userdata( $dw_sec ) ), 'due passaggi WordPress: con un altro plugin attivo per gli utenti WordPress ci facciamo da parte' );
asem_ok( false !== strpos( \AssociazioneSemplice\Admin\LimitsPage::two_factor_note(), 'Two Factor' ), 'due passaggi WordPress: in Limiti e soglie si dice quale plugin la gestisce' );
asem_ok( TwoFactorWp::required_for_person( Plugin::people()->get( $dw_ownp ) ), 'due passaggi WordPress: per i soci con l\'accesso proprio la verifica resta nostra' );
$dw_flags( 0, 0 );

wp_set_current_user( 0 );
TwoFactor::$clock = null;
remove_filter( 'pre_wp_mail', $dw_hook, 10 );
