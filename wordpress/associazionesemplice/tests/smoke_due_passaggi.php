<?php
/**
 * Verifica in due passaggi dell'area soci (TOTP RFC 6238, codici di riserva, ripristino via email: decisioni del titolare 26/11/2026). Spenta di partenza;
 * al login la sessione si apre solo dopo il codice; un codice già usato non vale; i codici di riserva valgono una volta; il link dell'email scade e si usa
 * una volta sola; i tentativi sono limitati; nel database, nella copia di sicurezza e nell'esportazione il segreto non è mai in chiaro. Si carica da smoke.php.
 */

defined( 'ABSPATH' ) || exit;

use AssociazioneSemplice\Counters;
use AssociazioneSemplice\Db;
use AssociazioneSemplice\Limits;
use AssociazioneSemplice\MemberLogin;
use AssociazioneSemplice\MemberSession;
use AssociazioneSemplice\Plugin;
use AssociazioneSemplice\TwoFactor;
use AssociazioneSemplice\Frontend\Actions;
use AssociazioneSemplice\Frontend\MemberLoginPage;
use AssociazioneSemplice\Frontend\Views;

global $wpdb;
wp_set_current_user( 0 ); // l'area soci con l'accesso proprio: nessun utente WordPress

// Il codice atteso, calcolato qui con la formula della RFC 6238 (indipendente dalla classe).
$dp_totp = function ( string $b32, int $ts ): string {
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

// ---------- Parte pura: base32, vettori della RFC 6238, finestra ±1 ----------
$dp_rfc = TwoFactor::base32_encode( '12345678901234567890' );
asem_ok( 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ' === $dp_rfc && '12345678901234567890' === TwoFactor::base32_decode( strtolower( $dp_rfc ) ) && null === TwoFactor::base32_decode( 'non base32!' ), 'due passaggi: base32 come la RFC 4648, anche in minuscolo' );
$dp_vec = array( 59 => '287082', 1111111109 => '081804', 1111111111 => '050471', 1234567890 => '005924', 2000000000 => '279037', 20000000000 => '353130' );
$dp_vok = true;
foreach ( $dp_vec as $dp_ts => $dp_want ) {
	$dp_vok = $dp_vok && $dp_want === TwoFactor::code_at( $dp_rfc, (int) $dp_ts ) && $dp_want === $dp_totp( $dp_rfc, (int) $dp_ts );
}
asem_ok( $dp_vok, 'due passaggi: i codici sono quelli dei vettori di prova della RFC 6238 (SHA1, 6 cifre, 30 secondi)' );
$dp_s   = TwoFactor::new_secret();
$dp_now = 1900000020;
$dp_st  = TwoFactor::step( $dp_now );
asem_ok( 1 === preg_match( '/^[A-Z2-7]{32}$/', $dp_s ) && $dp_s !== TwoFactor::new_secret(), 'due passaggi: segreto nuovo di 32 caratteri base32, diverso ogni volta' );
asem_ok(
	$dp_st === TwoFactor::match_step( $dp_s, $dp_totp( $dp_s, $dp_now ), $dp_now, 0 ) && $dp_st + 1 === TwoFactor::match_step( $dp_s, $dp_totp( $dp_s, $dp_now + 30 ), $dp_now, 0 )
	&& $dp_st - 1 === TwoFactor::match_step( $dp_s, $dp_totp( $dp_s, $dp_now - 30 ), $dp_now, 0 ),
	'due passaggi: vale il codice di adesso, quello di prima e quello dopo (finestra ±1)'
);
asem_ok( null === TwoFactor::match_step( $dp_s, $dp_totp( $dp_s, $dp_now + 60 ), $dp_now, 0 ) && null === TwoFactor::match_step( $dp_s, $dp_totp( $dp_s, $dp_now - 60 ), $dp_now, 0 ) && null === TwoFactor::match_step( $dp_s, '12345', $dp_now, 0 ), 'due passaggi: due passi più in là non vale, e nemmeno un codice di 5 cifre' );
asem_ok( null === TwoFactor::match_step( $dp_s, $dp_totp( $dp_s, $dp_now ), $dp_now, $dp_st ), 'due passaggi: un codice già usato (passo non più recente dell\'ultimo) non vale' );
$dp_uri = TwoFactor::otpauth_uri( $dp_s, str_repeat( 'Associazione Sportiva Dilettantistica ', 6 ), str_repeat( 'a', 150 ) . '@example.org' );
asem_ok( strlen( $dp_uri ) <= \AssociazioneSemplice\QrCode::MAX_BYTES && 0 === strpos( $dp_uri, 'otpauth://totp/' ) && false !== strpos( $dp_uri, 'secret=' . $dp_s ) && 0 === strpos( \AssociazioneSemplice\QrCode::svg( $dp_uri, 4, 'QR', false ), '<svg' ), 'due passaggi: l\'indirizzo per l\'app sta nel QR anche con nomi lunghi' );
$dp_bk = TwoFactor::new_backup_codes();
$dp_ok = 10 === count( $dp_bk ) && 10 === count( array_unique( $dp_bk ) );
foreach ( $dp_bk as $dp_c ) {
	$dp_ok = $dp_ok && 1 === preg_match( '/^\d{10}$/', $dp_c );
}
$dp_hs = array( TwoFactor::backup_hash( $dp_bk[0], 'segreto' ), TwoFactor::backup_hash( $dp_bk[1], 'segreto' ) );
asem_ok( $dp_ok && 1 === TwoFactor::backup_match( $dp_hs, TwoFactor::show_backup( $dp_bk[1] ), 'segreto' ) && null === TwoFactor::backup_match( $dp_hs, $dp_bk[2], 'segreto' ) && false === strpos( implode( '', $dp_hs ), $dp_bk[0] ), 'due passaggi: 10 codici di riserva di 10 cifre, salvati solo come impronta con sale' );
asem_ok( 5 === Limits::get( 'member_tfa_fail_per_10min' ), 'due passaggi: limite di 5 codici sbagliati ogni 10 minuti, nei Limiti' );

// ---------- Un socio con l'accesso proprio: spenta di partenza ----------
$dp_mail = array();
$dp_hook = function ( $null, $atts ) use ( &$dp_mail ) {
	$dp_mail[] = $atts;
	return true;
};
add_filter( 'pre_wp_mail', $dp_hook, 10, 2 );
$dp_addr = 'dora.passaggi@example.org';
$dp_pw   = 'Password lunga 2fa';
$dp_p    = (int) Plugin::people()->create( array( 'type' => 'ordinary', 'first_name' => 'Dora', 'last_name' => 'Passaggi', 'email' => $dp_addr ) );
asem_ok( '' === MemberLogin::set_password( $dp_p, $dp_pw ), 'due passaggi: il socio sceglie la password' );
$dp_sess = function () use ( $wpdb, &$dp_p ): int {
	return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Db::t( 'member_sessions' ) . ' WHERE person_id = %d', $dp_p ) );
};
$dp_out = function () use ( $wpdb, &$dp_p ): void {
	MemberSession::destroy();
	$wpdb->delete( Db::t( 'member_sessions' ), array( 'person_id' => $dp_p ) );
	unset( $_COOKIE[ MemberSession::COOKIE ] );
};
$dp_raw = function ( int $pid ) use ( $wpdb ): array {
	return (array) $wpdb->get_row( $wpdb->prepare( 'SELECT tfa_secret, tfa_backup, tfa_on_at, tfa_last_step FROM ' . Db::t( 'people' ) . ' WHERE id = %d', $pid ), ARRAY_A );
};
$dp_audit = function ( string $action, int $pid ) use ( $wpdb ): array {
	return $wpdb->get_col( $wpdb->prepare( 'SELECT details FROM ' . Db::t( 'audit_log' ) . ' WHERE action = %s AND object_id = %d ORDER BY id', $action, $pid ) ) ?: array();
};
$dp_last = function ( string $action, int $pid ) use ( $dp_audit ): string {
	$rows = $dp_audit( $action, $pid );
	return $rows ? (string) $rows[ count( $rows ) - 1 ] : '';
};
$dp_failkey = 'asem_mt_tfa_' . $dp_p;
TwoFactor::$clock = $dp_now;
$dp_out();
asem_ok( ! TwoFactor::is_on( $dp_p ) && $dp_p === MemberLogin::login_with_password( $dp_addr, $dp_pw ) && 1 === $dp_sess() && null === TwoFactor::pending(), 'due passaggi: spenta di partenza, la password apre subito la sessione' );
$dp_v0 = Views::section_two_factor( Plugin::people()->get( $dp_p ) );
asem_ok( false !== strpos( $dp_v0, 'Verifica in due passaggi' ) && false !== strpos( $dp_v0, 'spenta' ) && false !== strpos( $dp_v0, 'value="asem_front_tfa_start"' ), 'due passaggi: in «Il mio profilo» è spenta e c\'è il pulsante per accenderla' );

// ---------- Accensione: QR, chiave da copiare, conferma del primo codice ----------
Actions::do_tfa_start( array() );
$dp_sec = TwoFactor::pending_secret( $dp_p );
$dp_r   = $dp_raw( $dp_p );
asem_ok( 32 === strlen( $dp_sec ) && ! TwoFactor::is_on( $dp_p ) && 0 === strpos( (string) $dp_r['tfa_secret'], 'enc1:' ) && false === strpos( (string) $dp_r['tfa_secret'], $dp_sec ) && empty( $dp_r['tfa_on_at'] ), 'due passaggi: il segreto si salva cifrato, e finché il primo codice non è confermato non vale' );
asem_ok( $dp_sec === TwoFactor::start_setup( $dp_p ), 'due passaggi: ricaricare la pagina non cambia il QR' );
$dp_v1 = Views::section_two_factor( Plugin::people()->get( $dp_p ) );
asem_ok( false !== strpos( $dp_v1, '<svg' ) && false !== strpos( $dp_v1, TwoFactor::grouped( $dp_sec ) ) && false !== strpos( $dp_v1, 'value="asem_front_tfa_confirm"' ) && false !== strpos( $dp_v1, 'autocomplete="one-time-code"' ) && false !== strpos( $dp_v1, 'inputmode="numeric"' ), 'due passaggi: QR, chiave da copiare e campo del codice con tastiera numerica' );
$dp_at  = function ( int $off ) use ( $dp_totp, &$dp_sec, $dp_now ): string {
	return $dp_totp( $dp_sec, $dp_now + $off );
};
$dp_bad = function ( int $off ) use ( $dp_at ): string { // un codice che non è nessuno dei tre della finestra
	$w = array( $dp_at( $off - 30 ), $dp_at( $off ), $dp_at( $off + 30 ) );
	for ( $i = 0; $i < 10; $i++ ) {
		$c = sprintf( '%06d', ( (int) $dp_at( $off ) + 1 + $i ) % 1000000 );
		if ( ! in_array( $c, $w, true ) ) {
			return $c;
		}
	}
	return '000000';
};
asem_ok( null !== asem_throws( function () use ( $dp_bad ) {
	Actions::do_tfa_confirm( array( 'tfa_code' => $dp_bad( 0 ) ) );
} ) && ! TwoFactor::is_on( $dp_p ), 'due passaggi: con un codice sbagliato non si accende' );
Actions::do_tfa_confirm( array( 'tfa_code' => $dp_at( 0 ) ) );
$dp_v2 = Views::section_two_factor( Plugin::people()->get( $dp_p ) );
$dp_v3 = Views::section_two_factor( Plugin::people()->get( $dp_p ) );
preg_match_all( '#<code>(\d{5}-\d{5})</code>#', $dp_v2, $dp_m );
$dp_codes = $dp_m[1];
$dp_r     = $dp_raw( $dp_p );
$dp_plain = true;
foreach ( $dp_codes as $dp_c ) {
	$dp_plain = $dp_plain && false === strpos( (string) $dp_r['tfa_backup'], $dp_c ) && false === strpos( (string) $dp_r['tfa_backup'], TwoFactor::normalize( $dp_c ) );
}
asem_ok( TwoFactor::is_on( $dp_p ) && 10 === count( $dp_codes ) && 0 === preg_match( '#<code>\d{5}-\d{5}</code>#', $dp_v3 ) && false !== strpos( $dp_v3, 'ancora da usare: <strong>10</strong>' ), 'due passaggi: accesa, i 10 codici di riserva si vedono una volta sola' );
asem_ok( $dp_plain && 0 === strpos( (string) $dp_r['tfa_secret'], 'enc1:' ) && false === strpos( wp_json_encode( $dp_r ), $dp_sec ), 'due passaggi: nel database né il segreto né i codici di riserva in chiaro' );
asem_ok( 1 === count( $dp_audit( 'member.tfa_on', $dp_p ) ) && 1 === count( $dp_audit( 'member.tfa_failed', $dp_p ) ), 'due passaggi: accensione e codice sbagliato nel registro azioni' );

// ---------- Codice giusto, sbagliato, riusato; finestra ±1 ----------
Counters::delete( $dp_failkey );
asem_ok( 'wrong' === TwoFactor::check( $dp_p, $dp_at( 0 ) ), 'due passaggi: il codice usato per accendere non vale una seconda volta' );
asem_ok( 'ok' === TwoFactor::check( $dp_p, $dp_at( 30 ) ) && 'wrong' === TwoFactor::check( $dp_p, $dp_at( 30 ) ), 'due passaggi: il codice del passo dopo vale, una volta sola' );
TwoFactor::$clock = $dp_now + 120;
asem_ok( 'ok' === TwoFactor::check( $dp_p, $dp_at( 90 ) ) && 'wrong' === TwoFactor::check( $dp_p, $dp_at( 180 ) ), 'due passaggi: il passo prima vale, due passi dopo no' );
Counters::delete( $dp_failkey );

// ---------- Login che si ferma prima della sessione ----------
$dp_out();
asem_ok( $dp_p === MemberLogin::login_with_password( $dp_addr, $dp_pw ) && 0 === $dp_sess() && null === MemberSession::current() && $dp_p === (int) ( TwoFactor::pending()['p'] ?? 0 ) && ! empty( $_COOKIE[ TwoFactor::COOKIE ] ), 'due passaggi: dopo la password nessuna sessione, solo il passaggio a metà' );
$dp_page = MemberLoginPage::page( '', '', '', '', '' );
asem_ok( false !== strpos( $dp_page, 'name="tfa_code"' ) && false !== strpos( $dp_page, 'autocomplete="one-time-code"' ) && false !== strpos( $dp_page, 'inputmode="numeric"' ) && false !== strpos( $dp_page, 'value="tfa_recover"' ) && false === strpos( $dp_page, 'type="password"' ), 'due passaggi: la pagina di accesso chiede il codice (tastiera numerica, completamento dei codici) e offre il link via email' );
TwoFactor::$clock = $dp_now + 300;
asem_ok( 'wrong' === TwoFactor::finish_login( $dp_bad( 300 ) ) && 0 === $dp_sess(), 'due passaggi: con il codice sbagliato la sessione non si apre' );
asem_ok( 'ok' === TwoFactor::finish_login( $dp_at( 300 ) ) && 1 === $dp_sess() && null === TwoFactor::pending() && empty( $_COOKIE[ TwoFactor::COOKIE ] ), 'due passaggi: con il codice giusto si apre la sessione e il passaggio a metà sparisce' );
$dp_logins = $dp_audit( 'member.login', $dp_p );
asem_ok( false !== strpos( (string) end( $dp_logins ), 'password+tfa' ), 'due passaggi: nel registro l\'accesso con password e codice' );
asem_ok( 'expired' === TwoFactor::finish_login( $dp_at( 330 ) ), 'due passaggi: senza il passaggio a metà il codice da solo non fa entrare' );

// ---------- Codici di riserva monouso (anche dal link via email) ----------
$dp_out();
Counters::delete( 'asem_ml_m_' . md5( $dp_addr ) );
MemberLogin::request( $dp_addr );
$dp_tok = 1 === preg_match( '/asem_ml=([A-Za-z0-9_-]{43})/', (string) end( $dp_mail )['message'], $dp_mm ) ? $dp_mm[1] : '';
asem_ok( '' !== $dp_tok && $dp_p === MemberLogin::login_with_token( $dp_tok ) && 0 === $dp_sess() && null !== TwoFactor::pending(), 'due passaggi: anche il link via email si ferma prima della sessione' );
asem_ok( 'ok' === TwoFactor::finish_login( $dp_codes[0] ) && 1 === $dp_sess() && 9 === TwoFactor::backup_left( $dp_p ) && 1 === count( $dp_audit( 'member.tfa_backup_used', $dp_p ) ), 'due passaggi: un codice di riserva fa entrare e si consuma' );
$dp_out();
MemberLogin::login_with_password( $dp_addr, $dp_pw );
asem_ok( 'wrong' === TwoFactor::finish_login( TwoFactor::normalize( $dp_codes[0] ) ) && 0 === $dp_sess() && 9 === TwoFactor::backup_left( $dp_p ), 'due passaggi: lo stesso codice di riserva non vale una seconda volta' );

// ---------- Limiti dei tentativi ----------
Counters::delete( $dp_failkey );
TwoFactor::$clock = $dp_now + 330;
$dp_wrong = 0;
for ( $dp_i = 0; $dp_i < 5; $dp_i++ ) {
	$dp_wrong += 'wrong' === TwoFactor::finish_login( $dp_bad( 330 ) ) ? 1 : 0;
}
asem_ok( 5 === $dp_wrong && 'blocked' === TwoFactor::finish_login( $dp_at( 330 ) ) && 0 === $dp_sess(), 'due passaggi: dopo 5 codici sbagliati anche quello giusto aspetta 10 minuti' );
Counters::delete( $dp_failkey );
asem_ok( 'ok' === TwoFactor::finish_login( $dp_at( 330 ) ) && 1 === $dp_sess(), 'due passaggi: finita l\'attesa il codice giusto fa entrare' );

// ---------- Spegnere e rifare i codici richiede un codice valido ----------
TwoFactor::$clock = $dp_now + 360;
asem_ok( null !== asem_throws( function () use ( $dp_bad ) {
	Actions::do_tfa_off( array( 'tfa_code' => $dp_bad( 360 ) ) );
} ) && TwoFactor::is_on( $dp_p ), 'due passaggi: senza un codice valido non si spegne' );
Actions::do_tfa_regen( array( 'tfa_code' => $dp_at( 360 ) ) );
preg_match_all( '#<code>(\d{5}-\d{5})</code>#', Views::section_two_factor( Plugin::people()->get( $dp_p ) ), $dp_m );
asem_ok( 10 === count( $dp_m[1] ) && 10 === TwoFactor::backup_left( $dp_p ) && 'wrong' === TwoFactor::check( $dp_p, $dp_codes[1] ) && 'ok' === TwoFactor::check( $dp_p, $dp_m[1][0] ), 'due passaggi: rifatti i codici di riserva, i vecchi non valgono più e i nuovi sì' );
Counters::delete( $dp_failkey );

// ---------- Ripristino via email: link che scade e si usa una volta sola ----------
$dp_out();
MemberLogin::login_with_password( $dp_addr, $dp_pw );
$dp_n = count( $dp_mail );
asem_ok( TwoFactor::request_recovery() && count( $dp_mail ) === $dp_n + 1 && $dp_addr === (string) ( is_array( end( $dp_mail )['to'] ) ? end( $dp_mail )['to'][0] : end( $dp_mail )['to'] ), 'due passaggi: dal passaggio a metà parte l\'email con il link, all\'indirizzo della scheda' );
$dp_rt = 1 === preg_match( '/asem_tfa_off=([A-Za-z0-9_-]{43})/', (string) end( $dp_mail )['message'], $dp_mm ) ? $dp_mm[1] : '';
asem_ok( '' !== $dp_rt && TwoFactor::recovery_valid( $dp_rt ) && 0 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Db::t( 'counters' ) . ' WHERE k LIKE %s', '%' . $wpdb->esc_like( $dp_rt ) . '%' ) ), 'due passaggi: il link vale e nel database c\'è solo la sua impronta' );
asem_ok( false !== strpos( MemberLoginPage::page( '', '', '', '', '', '', $dp_rt ), 'value="tfa_off"' ) && TwoFactor::is_on( $dp_p ), 'due passaggi: il link apre solo la conferma, non spegne da solo' );
if ( Counters::uses_table() ) {
	$wpdb->query( $wpdb->prepare( 'UPDATE ' . Db::t( 'counters' ) . ' SET expires = %d WHERE k = %s', time() - 1, 'asem_tfa_r_' . substr( hash( 'sha256', $dp_rt ), 0, 40 ) ) );
	asem_ok( ! TwoFactor::recovery_valid( $dp_rt ) && ! TwoFactor::use_recovery( $dp_rt ) && TwoFactor::is_on( $dp_p ), 'due passaggi: dopo 15 minuti il link non vale più' );
}
TwoFactor::request_recovery();
$dp_rt2 = 1 === preg_match( '/asem_tfa_off=([A-Za-z0-9_-]{43})/', (string) end( $dp_mail )['message'], $dp_mm ) ? $dp_mm[1] : '';
$dp_n   = count( $dp_mail );
asem_ok( '' !== $dp_rt2 && $dp_rt2 !== $dp_rt && TwoFactor::use_recovery( $dp_rt2 ) && ! TwoFactor::is_on( $dp_p ), 'due passaggi: con il link la verifica si spegne' );
$dp_r  = $dp_raw( $dp_p );
$dp_to = array();
foreach ( array_slice( $dp_mail, $dp_n ) as $dp_mx ) {
	$dp_to[] = is_array( $dp_mx['to'] ) ? (string) $dp_mx['to'][0] : (string) $dp_mx['to'];
}
asem_ok( null === $dp_r['tfa_secret'] && null === $dp_r['tfa_backup'] && null === $dp_r['tfa_on_at'] && 0 === (int) $dp_r['tfa_last_step'], 'due passaggi: spenta, segreto e codici sono tolti dalla scheda' );
asem_ok( in_array( \AssociazioneSemplice\Applications::notify_email(), $dp_to, true ) && in_array( $dp_addr, $dp_to, true ) && false !== strpos( $dp_last( 'member.tfa_off', $dp_p ), 'email' ), 'due passaggi: avviso alla segreteria, email all\'interessato e riga nel registro' );
asem_ok( ! TwoFactor::use_recovery( $dp_rt2 ), 'due passaggi: il link si usa una volta sola' );
$dp_out();
asem_ok( $dp_p === MemberLogin::login_with_password( $dp_addr, $dp_pw ) && 1 === $dp_sess() && null === TwoFactor::pending(), 'due passaggi: spenta, la password torna ad aprire subito la sessione' );

// ---------- Spenta dal profilo con un codice ----------
TwoFactor::$clock = $dp_now + 400;
$dp_sec = TwoFactor::start_setup( $dp_p );
TwoFactor::confirm_setup( $dp_p, $dp_at( 400 ) );
TwoFactor::$clock = $dp_now + 430;
Actions::do_tfa_off( array( 'tfa_code' => $dp_at( 430 ) ) );
asem_ok( ! TwoFactor::is_on( $dp_p ) && false !== strpos( $dp_last( 'member.tfa_off', $dp_p ), 'code' ), 'due passaggi: dal profilo si spegne con un codice valido' );

// ---------- Esportazione dei dati, copia di sicurezza, cancellazione e anonimizzazione ----------
TwoFactor::$clock = $dp_now + 500;
$dp_sec = TwoFactor::start_setup( $dp_p );
TwoFactor::confirm_setup( $dp_p, $dp_at( 500 ) );
TwoFactor::take_new_codes( $dp_p );
$dp_exp = wp_json_encode( \AssociazioneSemplice\Privacy::export( $dp_p ) );
asem_ok( true === \AssociazioneSemplice\Privacy::export( $dp_p )['verifica_in_due_passaggi_accesa'] && false === strpos( $dp_exp, 'tfa_' ) && false === strpos( $dp_exp, $dp_sec ) && false === strpos( $dp_exp, 'enc1:' ), 'due passaggi: l\'esportazione dei dati dice solo se è accesa' );
$dp_zip = \AssociazioneSemplice\Backup::export( false );
$dp_za  = new ZipArchive();
$dp_za->open( $dp_zip );
$dp_line = '';
foreach ( explode( "\n", (string) $dp_za->getFromName( 'tabelle/asem_people.jsonl' ) ) as $dp_l ) {
	$dp_row = json_decode( $dp_l, true );
	if ( is_array( $dp_row ) && (int) ( $dp_row['id'] ?? 0 ) === $dp_p ) {
		$dp_line = $dp_l;
	}
}
$dp_za->close();
wp_delete_file( $dp_zip );
asem_ok( '' !== $dp_line && false === strpos( $dp_line, 'tfa_' ) && false === strpos( $dp_line, (string) $dp_raw( $dp_p )['tfa_secret'] ), 'due passaggi: nella copia di sicurezza la scheda c\'è, ma senza segreto e codici' );
$dp_q = (int) Plugin::people()->create( array( 'type' => 'ordinary', 'first_name' => 'Quinto', 'last_name' => 'Passaggi', 'email' => 'quinto.passaggi@example.org' ) );
$dp_sec = TwoFactor::start_setup( $dp_q );
TwoFactor::confirm_setup( $dp_q, $dp_at( 500 ) );
Plugin::people()->delete( $dp_q );
$dp_rq = $dp_raw( $dp_q );
asem_ok( null === $dp_rq['tfa_secret'] && null === $dp_rq['tfa_backup'] && null === $dp_rq['tfa_on_at'], 'due passaggi: con la cancellazione della persona segreto e codici spariscono' );
$dp_out();
\AssociazioneSemplice\Privacy::anonymize( $dp_p );
$dp_r = $dp_raw( $dp_p );
asem_ok( null === $dp_r['tfa_secret'] && null === $dp_r['tfa_backup'] && null === $dp_r['tfa_on_at'] && 0 === (int) $dp_r['tfa_last_step'], 'due passaggi: con l\'anonimizzazione segreto e codici spariscono' );

TwoFactor::$clock = null;
TwoFactor::drop_pending();
remove_filter( 'pre_wp_mail', $dp_hook, 10 );
