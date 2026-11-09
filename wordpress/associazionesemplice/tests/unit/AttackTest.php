<?php
use AssociazioneSemplice\ActivationToken;
use AssociazioneSemplice\Admin\Exports;
use AssociazioneSemplice\AttachmentRules;
use AssociazioneSemplice\CardToken;
use AssociazioneSemplice\ClientIp;
use AssociazioneSemplice\DocumentRules;
use AssociazioneSemplice\Ics;
use AssociazioneSemplice\LicenseClient;
use AssociazioneSemplice\LicenseRules;
use AssociazioneSemplice\SatispayRules;
use AssociazioneSemplice\SessionToken;
use AssociazioneSemplice\StripeWebhook;
use AssociazioneSemplice\Updater;
use AssociazioneSemplice\WebPush;
use AssociazioneSemplice\Xlsx;
use PHPUnit\Framework\TestCase;

/** Prove offensive sulle classi pure del plugin: token, firme, nomi di file, CSV, JWT, indirizzi. */
final class AttackTest extends TestCase {

	private static function b64u( string $s ): string {
		return rtrim( strtr( base64_encode( $s ), '+/', '-_' ), '=' );
	}

	// ---------- Push: indirizzi ammessi ----------

	public function test_push_endpoint_blocks_ssrf_and_lookalikes(): void {
		$bad = array(
			'http://fcm.googleapis.com/x', 'https://127.0.0.1/x', 'https://[::1]/x', 'https://localhost/x', 'https://169.254.169.254/latest/meta-data',
			'https://fcm.googleapis.com.evil.com/x', 'https://evilgoogleapis.com/x', 'https://googleapis.com.evil.it/x', 'https://evil.com/fcm.googleapis.com',
			'https://evil.com#@fcm.googleapis.com/', 'https://fcm.googleapis.com@evil.com/x', 'https://user:pw@fcm.googleapis.com/x', 'https://fcm.googleapis.com:8443/x',
			'https://fcm.googleapis.com:444/x', "https://fcm.googleapis.com\r\nHost: evil.com/", 'https://fcm.googleapis.com\\@evil.com/', 'ftp://fcm.googleapis.com/x',
			'//fcm.googleapis.com/x', 'javascript:alert(1)', '', 'https://', 'https://2130706433/x', 'https://0x7f000001/x',
			'https://' . str_repeat( 'a', 700 ) . '.googleapis.com/x', 'https://push.services.mozilla.com.evil.net/', 'https://notify.windows.com.evil/',
		);
		foreach ( $bad as $u ) {
			$this->assertFalse( WebPush::allowed_endpoint( $u ), 'accettato: ' . json_encode( $u ) );
		}
		foreach ( array( 'https://fcm.googleapis.com/fcm/send/abc', 'https://updates.push.services.mozilla.com/wpush/v2/x', 'https://web.push.apple.com/x', 'https://wns2-par02p.notify.windows.com/w/?token=x' ) as $u ) {
			$this->assertTrue( WebPush::allowed_endpoint( $u ), 'rifiutato un servizio vero: ' . $u );
		}
	}

	// ---------- Stripe ----------

	public function test_stripe_signature_fuzz(): void {
		$p = '{"id":"evt_1"}'; $s = 'whsec_x'; $t = 1700000000;
		$good = StripeWebhook::header( $p, $s, $t );
		$this->assertTrue( StripeWebhook::verify( $p, $good, $s, $t + 10 ) );
		$sig = StripeWebhook::sign( $p, $s, $t );
		$cases = array(
			'' => $p, 'x' => $p, ',,,' => $p, "t=$t" => $p, "v1=$sig" => $p, "t=$t,v1=" => $p, "t=abc,v1=$sig" => $p, "t=-1,v1=$sig" => $p, "t=$t,v1=" . strtoupper( $sig ) => $p,
			"t=$t,v0=$sig" => $p, "t=$t,v1=$sig " => $p . ' ', 't=' . ( $t + 9999 ) . ",v1=$sig" => $p,
		);
		foreach ( $cases as $h => $body ) {
			$this->assertFalse( StripeWebhook::verify( $body, (string) $h, $s, $t + 10 ), 'accettato: ' . $h );
		}
		$this->assertFalse( StripeWebhook::verify( $p, $good, '', $t ), 'segreto vuoto' );
		$this->assertFalse( StripeWebhook::verify( $p, $good, $s, $t + 100000 ), 'scaduta' );
		$this->assertFalse( StripeWebhook::verify( $p . "\n", $good, $s, $t ), 'corpo diverso' );
	}

	// ---------- Token QR e attivazione ----------

	public function test_card_and_activation_tokens_resist_forgery_and_confusion(): void {
		$sec = 'segreto';
		$tok = CardToken::make( 5, $sec );
		$this->assertFalse( CardToken::valid( 5, strtoupper( $tok ), $sec ) === true && strtoupper( $tok ) !== $tok );
		$this->assertFalse( CardToken::valid( 5, $tok . 'x', $sec ) );
		$this->assertFalse( CardToken::valid( 5, substr( $tok, 0, -1 ), $sec ) );
		$this->assertFalse( CardToken::valid( 5, $tok, $sec . ' ' ) );
		foreach ( array( "5.$tok\n", "5.$tok ", " 5.$tok", "05.$tok", "5.$tok.", "5..$tok", "-5.$tok", "5.0$tok", "5.$tok\0" ) as $p ) {
			$r = CardToken::parse( $p );
			$this->assertTrue( null === $r || ( 5 === $r[0] && $r[1] === $tok ), 'parse ambiguo: ' . json_encode( $p ) );
		}
		// biglietto vs tessera: non intercambiabili
		$tk = CardToken::ticket_make( 3, 5, $sec );
		$this->assertFalse( CardToken::valid( 5, $tk, $sec ) );
		$this->assertFalse( CardToken::ticket_valid( 3, 6, $tk, $sec ) );
		$this->assertFalse( CardToken::ticket_valid( 4, 5, $tk, $sec ) );
		// attivazione: scopo diverso, scadenza, persona
		$exp = 1800000000;
		$p = ActivationToken::param( 9, $exp, $sec, 'activate' );
		$this->assertSame( 'ok', ActivationToken::check( $p, $sec, $exp - 1 ) );
		$this->assertSame( 'ok', ActivationToken::check( $p, $sec, $exp ) );
		$this->assertSame( 'expired', ActivationToken::check( $p, $sec, $exp + 1 ) );
		$this->assertSame( 'invalid', ActivationToken::check( $p, $sec, $exp - 1, 'reset' ), 'scopo diverso' );
		$this->assertSame( 'invalid', ActivationToken::check( '10.' . $exp . '.' . explode( '.', $p )[2], $sec, 0 ), 'altra persona' );
		$this->assertSame( 'invalid', ActivationToken::check( '9.' . ( $exp + 5 ) . '.' . explode( '.', $p )[2], $sec, 0 ), 'scadenza spostata' );
		$this->assertSame( 'invalid', ActivationToken::check( '9.1800000000.' . str_repeat( 'a', 24 ), $sec, 0 ) );
		foreach ( array( '', '9', '9.1.1', "9.$exp.zzzz", "9.$exp.", str_repeat( '9', 5000 ) ) as $x ) {
			$this->assertSame( 'invalid', ActivationToken::check( $x, $sec, 0 ) );
		}
	}

	public function test_csrf_token_is_bound_to_session_action_and_time(): void {
		$sec = 'segreto'; $sess = SessionToken::hash( SessionToken::generate() ); $now = 1700000000;
		$t = SessionToken::csrf( $sess, 'asem_x', $now + 100, $sec );
		$this->assertTrue( SessionToken::check_csrf( $t, $sess, 'asem_x', $now, $sec ) );
		$this->assertFalse( SessionToken::check_csrf( $t, $sess, 'asem_y', $now, $sec ), 'altra azione' );
		$this->assertFalse( SessionToken::check_csrf( $t, SessionToken::hash( 'altra' ), 'asem_x', $now, $sec ), 'altra sessione' );
		$this->assertFalse( SessionToken::check_csrf( $t, $sess, 'asem_x', $now + 101, $sec ), 'scaduto' );
		$this->assertFalse( SessionToken::check_csrf( $t, $sess, 'asem_x', $now, 'altro' ), 'altro segreto' );
		foreach ( array( '', '1', '1.', '.x', '999999.' . str_repeat( 'a', 64 ), str_repeat( 'a', 100000 ) ) as $bad ) {
			$this->assertFalse( SessionToken::check_csrf( $bad, $sess, 'asem_x', $now, $sec ), json_encode( substr( $bad, 0, 20 ) ) );
		}
		$this->assertFalse( SessionToken::is_well_formed( '' ) );
		$this->assertFalse( SessionToken::is_well_formed( "' OR 1=1" ) );
		$this->assertTrue( SessionToken::is_well_formed( SessionToken::generate() ) );
		$this->assertNotSame( SessionToken::generate(), SessionToken::generate() );
		$this->assertMatchesRegularExpression( '/^\d{6}$/', SessionToken::code() );
		$this->assertNotSame( SessionToken::hash_code( '123456', 1, $sec ), SessionToken::hash_code( '123456', 2, $sec ), 'il codice è legato alla persona' );
	}

	// ---------- File ----------

	public function test_dangerous_uploads_are_rejected(): void {
		$names = array(
			'shell.php', 'shell.PHP', 'shell.phtml', 'shell.php5', 'shell.phar', 'shell.pht', 'a.php.jpg', 'a.jpg.php', 'a.php.', 'a.php ', "a.php\0.jpg", 'a.php%00.jpg', '.htaccess', '.user.ini',
			'x.html', 'x.htm', 'x.svg', 'x.xhtml', 'x.js', 'x.exe', 'x.sh', 'x.cgi', 'x.pl', 'x.asp', 'x.aspx', 'x.jsp', 'x.swf', 'x.xml', 'x.shtml', 'web.config',
		);
		foreach ( $names as $n ) {
			$this->assertNotNull( AttachmentRules::check( $n, 100, 'text/x-php' ), 'accettato come allegato: ' . json_encode( $n ) );
			$this->assertNotNull( DocumentRules::check( $n, 100, 'text/html' ), 'accettato come documento: ' . json_encode( $n ) );
		}
		foreach ( array( array( 'ok.pdf', 'application/x-httpd-php' ), array( 'ok.pdf', 'text/html' ), array( 'ok.jpg', 'text/x-php' ), array( 'ok.png', 'image/svg+xml' ) ) as $c ) {
			$this->assertNotNull( AttachmentRules::check( $c[0], 100, $c[1] ), 'contenuto non coerente con l\'estensione: ' . $c[0] . ' ' . $c[1] );
		}
		$this->assertNotNull( AttachmentRules::check( 'ok.pdf', AttachmentRules::MAX_BYTES + 1, 'application/pdf' ) );
	}

	public function test_file_names_cannot_traverse_or_inject_headers(): void {
		foreach ( array( '../../wp-config.php', '..\\..\\wp-config.php', '/etc/passwd', 'C:\\Windows\\win.ini', "a\r\nSet-Cookie: x=1.pdf", "a\x00.pdf", "\u{202E}fdp.exe", '<script>.pdf', 'a|b?.pdf', str_repeat( 'é', 500 ) . '.pdf' ) as $n ) {
			$d = AttachmentRules::display_name( $n );
			$this->assertDoesNotMatchRegularExpression( '#[/\\\\\x00-\x1F\x7F<>:"|?*]#u', $d, json_encode( $n ) . ' → ' . json_encode( $d ) );
			$this->assertLessThanOrEqual( 125, strlen( $d ) );
			$this->assertNotSame( '', $d );
			$dl = DocumentRules::download_name( $n, $n );
			$this->assertDoesNotMatchRegularExpression( '/[\r\n\x00"\\\\\/]/', $dl, 'download_name: ' . json_encode( $dl ) );
		}
		foreach ( array( '../a', 'a/../b', str_repeat( 'a', 31 ), str_repeat( 'g', 32 ), strtoupper( str_repeat( 'a', 32 ) ), str_repeat( 'a', 32 ) . "\n", '' ) as $n ) {
			$this->assertSame( '', AttachmentRules::stored_name( $n ), json_encode( $n ) );
		}
		$this->assertSame( str_repeat( 'a', 32 ), AttachmentRules::stored_name( str_repeat( 'a', 32 ) ) );
	}

	// ---------- CSV / calendario ----------

	public function test_csv_formula_injection_is_neutralised(): void {
		foreach ( array( '=1+1', '=cmd|\' /C calc\'!A0', '+SUM(1)', '-2+3', '@SUM(1)', "\t=1", "\r=1", '=HYPERLINK("http://evil","x")', '-cmd|x', '+cmd', '@import', ' =1+1', "  +SUM(1)", "\xC2\xA0=1+1", "\xE2\x80\x8B=1+1", "\xEF\xBB\xBF=1+1", "\n=1+1", "\xEF\xBC\x9D1+1" ) as $v ) {
			$n = Exports::neutralize( $v );
			$this->assertSame( "'", substr( $n, 0, 1 ), 'formula non neutralizzata: ' . json_encode( $v ) );
		}
		foreach ( array( '100', '-12,50', '+39 333 1234567', '1.234,56', 'Mario Rossi', '' ) as $v ) {
			$this->assertSame( $v, Exports::neutralize( $v ), 'valore normale alterato: ' . $v );
		}
		$line = Exports::line( array( 'a,b', "x\"y", "=1+1", "multi\nline" ) );
		$this->assertStringNotContainsString( "\n=", $line );
	}

	public function test_ics_fields_cannot_inject_properties(): void {
		$e = Ics::escape( "Evento\r\nATTENDEE:mailto:evil@x.it\nEND:VEVENT" );
		$this->assertStringNotContainsString( "\n", $e );
		$this->assertStringNotContainsString( "\r", $e );
		$ev = Ics::event( array( 'uid' => 'asem-1@esempio.it', 'summary' => "T\r\nX-EVIL:1", 'date' => '2030-01-01', 'start' => '10:00', 'end' => '11:00', 'location' => "L\nX-EVIL:2", 'description' => "D\rX-EVIL:3" ), 'Europe/Rome', '20300101T000000Z' );
		$lines = preg_split( '/\r\n/', $ev );
		foreach ( $lines as $l ) {
			$this->assertDoesNotMatchRegularExpression( '/^X-EVIL/', $l, 'proprietà iniettata: ' . $l );
		}
	}

	// ---------- Licenze ----------

	private function jwt( array $head, array $claims, ?string $secretKey = null ): string {
		$h = self::b64u( json_encode( $head ) ); $c = self::b64u( json_encode( $claims ) );
		return $h . '.' . $c . '.' . self::b64u( sodium_crypto_sign_detached( $h . '.' . $c, $secretKey ) );
	}

	public function test_jwt_attestation_attacks(): void {
		$kp = sodium_crypto_sign_keypair(); $sk = sodium_crypto_sign_secretkey( $kp ); $pk = sodium_crypto_sign_publickey( $kp );
		$kp2 = sodium_crypto_sign_keypair(); $sk2 = sodium_crypto_sign_secretkey( $kp2 );
		$jwks = array( 'keys' => array( array( 'kid' => 'k1', 'kty' => 'OKP', 'crv' => 'Ed25519', 'x' => self::b64u( $pk ) ) ) );
		$now = 1800000000;
		$claims = array( 'iss' => 'wega', 'aud' => 'asem', 'exp' => $now + 600, 'bid' => 'esempio.it' );
		$ok = fn( $jwt, $dom = 'esempio.it', $iss = 'wega', $aud = 'asem' ) => LicenseClient::verify_attestation( $jwt, $jwks, $iss, $aud, $dom, $now );
		$this->assertNotNull( $ok( $this->jwt( array( 'alg' => 'EdDSA', 'kid' => 'k1' ), $claims, $sk ) ), 'caso valido' );
		$this->assertNull( $ok( $this->jwt( array( 'alg' => 'EdDSA', 'kid' => 'k1' ), $claims, $sk2 ) ), 'firmato da un\'altra chiave' );
		$this->assertNull( $ok( $this->jwt( array( 'alg' => 'EdDSA', 'kid' => 'sconosciuto' ), $claims, $sk ) ), 'kid sconosciuto' );
		$this->assertNull( $ok( $this->jwt( array( 'alg' => 'none', 'kid' => 'k1' ), $claims, $sk ) ), 'alg none' );
		$this->assertNull( $ok( $this->jwt( array( 'alg' => 'HS256', 'kid' => 'k1' ), $claims, $sk ) ), 'alg HS256' );
		$this->assertNull( $ok( $this->jwt( array( 'alg' => 'eddsa', 'kid' => 'k1' ), $claims, $sk ) ), 'alg minuscolo' );
		$this->assertNull( $ok( $this->jwt( array( 'alg' => 'EdDSA' ), $claims, $sk ) ), 'senza kid' );
		$this->assertNull( $ok( $this->jwt( array( 'alg' => 'EdDSA', 'kid' => 'k1' ), array( 'exp' => $now - 1 ) + $claims, $sk ) ), 'scaduto' );
		$this->assertNull( $ok( $this->jwt( array( 'alg' => 'EdDSA', 'kid' => 'k1' ), array( 'exp' => $now ) + $claims, $sk ) ), 'exp = adesso' );
		$no_exp = $claims; unset( $no_exp['exp'] );
		$this->assertNull( $ok( $this->jwt( array( 'alg' => 'EdDSA', 'kid' => 'k1' ), $no_exp, $sk ) ), 'senza exp' );
		$this->assertNull( $ok( $this->jwt( array( 'alg' => 'EdDSA', 'kid' => 'k1' ), array( 'nbf' => $now + 3600 ) + $claims, $sk ) ), 'nbf nel futuro' );
		$this->assertNull( $ok( $this->jwt( array( 'alg' => 'EdDSA', 'kid' => 'k1' ), $claims, $sk ), 'esempio.it', 'altro', 'asem' ), 'issuer' );
		$this->assertNull( $ok( $this->jwt( array( 'alg' => 'EdDSA', 'kid' => 'k1' ), $claims, $sk ), 'esempio.it', 'wega', 'altro-prodotto' ), 'audience' );
		foreach ( array( 'altro.it', 'esempio.it.evil.com', 'evil-esempio.it', 'xesempio.it', 'esempio.com', '' ) as $d ) {
			$this->assertNull( $ok( $this->jwt( array( 'alg' => 'EdDSA', 'kid' => 'k1' ), $claims, $sk ), $d ), 'dominio ' . $d );
		}
		$this->assertNotNull( $ok( $this->jwt( array( 'alg' => 'EdDSA', 'kid' => 'k1' ), $claims, $sk ), 'staging.esempio.it' ), 'i sottodomini sono ammessi' );
		// manomissione dopo la firma
		$j = $this->jwt( array( 'alg' => 'EdDSA', 'kid' => 'k1' ), $claims, $sk ); [ $h, $c, $s ] = explode( '.', $j );
		$evil = self::b64u( json_encode( array( 'exp' => $now + 99999999 ) + $claims ) );
		$this->assertNull( $ok( "$h.$evil.$s" ), 'claim modificati' );
		foreach ( array( '', '.', '..', 'a.b', 'a.b.c.d', "$h.$c.", "$h.$c." . self::b64u( str_repeat( 'x', 63 ) ), str_repeat( 'A', 100000 ) ) as $junk ) {
			$this->assertNull( $ok( $junk ), 'spazzatura' );
		}
		$this->assertNull( LicenseClient::verify_attestation( $j, null, 'wega', 'asem', 'esempio.it', $now ), 'senza JWKS' );
		$this->assertNull( LicenseClient::verify_attestation( $j, array( 'keys' => array() ), 'wega', 'asem', 'esempio.it', $now ), 'JWKS vuoto' );
		$this->assertNull( LicenseClient::verify_attestation( $j, array( 'keys' => array( array( 'kid' => 'k1', 'kty' => 'RSA', 'crv' => 'Ed25519', 'x' => self::b64u( $pk ) ) ) ), 'wega', 'asem', 'esempio.it', $now ), 'kty sbagliato' );
	}

	public function test_same_domain_lookalikes(): void {
		foreach ( array( array( 'esempio.it', 'esempio.it.evil.com' ), array( 'esempio.it', 'evil-esempio.it' ), array( 'esempio.it', 'altro.it' ), array( 'esempio.co.uk', 'altro.co.uk' ), array( '', '' ), array( 'esempio.it', '' ), array( '', 'esempio.it' ), array( 'co.uk', 'evil.co.uk' ), array( 'esempio.it', "esempio.it\n.evil.com" ) ) as $p ) {
			$this->assertFalse( LicenseRules::same_domain( $p[0], $p[1] ), json_encode( $p ) );
		}
		foreach ( array( array( 'esempio.it', 'www.esempio.it' ), array( 'ESEMPIO.IT', 'staging.esempio.it' ), array( 'esempio.co.uk', 'a.b.esempio.co.uk' ) ) as $p ) {
			$this->assertTrue( LicenseRules::same_domain( $p[0], $p[1] ), json_encode( $p ) );
		}
	}

	public function test_update_signature_attacks(): void {
		$kp = sodium_crypto_sign_keypair(); $pk = self::b64u( sodium_crypto_sign_publickey( $kp ) ); $sk = sodium_crypto_sign_secretkey( $kp );
		$kp2 = sodium_crypto_sign_keypair(); $sk2 = sodium_crypto_sign_secretkey( $kp2 );
		$sha = str_repeat( 'a', 64 );
		$sig = fn( $m, $k ) => self::b64u( sodium_crypto_sign_detached( $m, $k ) );
		$keys = array( 'k1' => $pk );
		$this->assertTrue( Updater::verify_signature( 'pro', '1.2.3', $sha, 'k1', $sig( "pro|1.2.3|$sha", $sk ), $keys ) );
		$this->assertFalse( Updater::verify_signature( 'pro', '1.2.3', $sha, 'k1', $sig( "pro|1.2.3|$sha", $sk2 ), $keys ), 'firma di un\'altra chiave' );
		$this->assertFalse( Updater::verify_signature( 'free', '1.2.3', $sha, 'k1', $sig( "pro|1.2.3|$sha", $sk ), $keys ), 'prodotto scambiato' );
		$this->assertFalse( Updater::verify_signature( 'pro', '1.2.4', $sha, 'k1', $sig( "pro|1.2.3|$sha", $sk ), $keys ), 'versione scambiata' );
		$this->assertFalse( Updater::verify_signature( 'pro', '1.2.3', str_repeat( 'b', 64 ), 'k1', $sig( "pro|1.2.3|$sha", $sk ), $keys ), 'impronta scambiata' );
		$this->assertFalse( Updater::verify_signature( 'pro', '1.2.3', strtoupper( $sha ), 'k1', $sig( "pro|1.2.3|" . strtoupper( $sha ), $sk ), $keys ), 'impronta in maiuscolo' );
		$this->assertFalse( Updater::verify_signature( 'pro', '1.2.3', $sha, 'k2', $sig( "pro|1.2.3|$sha", $sk ), $keys ), 'kid sconosciuto' );
		$this->assertFalse( Updater::verify_signature( 'pro', '1.2.3', $sha, 'k1', '', $keys ) );
		$this->assertFalse( Updater::verify_signature( 'pro', '1.2.3', $sha, 'k1', $sig( "pro|1.2.3|$sha", $sk ), array( 'k1' => '' ) ) );
		$this->assertFalse( Updater::verify_signature( 'pro', '1.2.3|x', substr( $sha, 0, 63 ), 'k1', 'AAAA', $keys ) );
	}

	// ---------- Varie ----------

	public function test_satispay_payment_id_is_url_safe(): void {
		foreach ( array( '../../x', "a b", "a\nb", 'a/b?c=d', 'a%2e%2e', '<script>', "x'--", 'ok-123_A' ) as $id ) {
			$this->assertMatchesRegularExpression( '/^[A-Za-z0-9_\-]*$/', SatispayRules::clean_id( $id ) );
		}
	}

	public function test_client_ip_cannot_be_spoofed_without_trusted_proxy(): void {
		$this->assertSame( '1.2.3.4', ClientIp::pick( array( 'REMOTE_ADDR' => '1.2.3.4', 'HTTP_X_FORWARDED_FOR' => '6.6.6.6', 'HTTP_CF_CONNECTING_IP' => '7.7.7.7', 'HTTP_X_REAL_IP' => '8.8.8.8' ), false ) );
		$this->assertSame( '1.2.3.4', ClientIp::pick( array( 'REMOTE_ADDR' => '1.2.3.4', 'HTTP_X_FORWARDED_FOR' => '<script>' ), true ) !== '<script>' ? '1.2.3.4' : 'iniettato' );
		$this->assertFalse( ClientIp::in_range( 'non-un-ip', '10.0.0.0/8' ) );
		$this->assertFalse( ClientIp::in_range( '10.0.0.1', 'non-un-cidr' ) );
		$this->assertFalse( ClientIp::in_range( '10.0.0.1', '10.0.0.0/99' ) );
	}

	public function test_xlsx_bomb_and_junk_are_rejected_without_memory_blowup(): void {
		if ( ! class_exists( '\ZipArchive' ) ) {
			$this->markTestSkipped( 'zip mancante' );
		}
		$dir = sys_get_temp_dir() . '/asem-atk-' . bin2hex( random_bytes( 4 ) ); mkdir( $dir );
		$f = $dir . '/bomb.xlsx';
		$z = new \ZipArchive(); $z->open( $f, \ZipArchive::CREATE );
		$z->addFromString( '[Content_Types].xml', '<Types/>' );
		$z->addFromString( 'xl/worksheets/sheet1.xml', '<worksheet><sheetData>' . str_repeat( '<row><c><v>1</v></c></row>', 2200000 ) . '</sheetData></worksheet>' ); // ~58 MB decompressi
		$z->close();
		$threw = false;
		$before = memory_get_peak_usage( true );
		try { Xlsx::read( $f ); } catch ( \Throwable $e ) { $threw = true; }
		$this->assertTrue( $threw, 'XLSX "bomba" accettato' );
		$this->assertLessThan( 200 * 1048576, memory_get_peak_usage( true ) - $before, 'troppa memoria' );
		file_put_contents( $dir . '/junk.xlsx', random_bytes( 2000 ) );
		$threw = false; try { Xlsx::read( $dir . '/junk.xlsx' ); } catch ( \Throwable $e ) { $threw = true; }
		$this->assertTrue( $threw, 'file casuale accettato come XLSX' );
		// XXE
		$g = $dir . '/xxe.xlsx';
		$z = new \ZipArchive(); $z->open( $g, \ZipArchive::CREATE );
		$z->addFromString( '[Content_Types].xml', '<Types/>' );
		$z->addFromString( 'xl/sharedStrings.xml', '<?xml version="1.0"?><!DOCTYPE s [<!ENTITY x SYSTEM "file:///etc/passwd">]><sst><si><t>&x;</t></si></sst>' );
		$z->addFromString( 'xl/worksheets/sheet1.xml', '<worksheet><sheetData><row r="1"><c r="A1" t="s"><v>0</v></c></row></sheetData></worksheet>' );
		$z->close();
		$out = ''; try { $out = json_encode( Xlsx::read( $g ) ); } catch ( \Throwable $e ) { $out = ''; }
		$this->assertStringNotContainsString( 'root:', $out, 'XXE: lettura di file locali' );
		array_map( 'unlink', glob( $dir . '/*' ) ); rmdir( $dir );
	}
}
