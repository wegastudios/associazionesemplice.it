<?php
use AssociazioneSemplice\BrevoNewsletter;
use AssociazioneSemplice\BrevoSms;
use AssociazioneSemplice\ConsentRules;
use AssociazioneSemplice\KlarnaRules;
use AssociazioneSemplice\MailchimpNewsletter;
use AssociazioneSemplice\MailPoetNewsletter;
use AssociazioneSemplice\NewsletterRules;
use AssociazioneSemplice\PaymentConfig as P;
use AssociazioneSemplice\SatispayApi;
use AssociazioneSemplice\SatispayRules;
use AssociazioneSemplice\SmsRules;
use AssociazioneSemplice\StripeApi;
use AssociazioneSemplice\TwilioSms;
use PHPUnit\Framework\TestCase;

/** Blocco E: SMS, consensi, newsletter, Satispay e Klarna (regole pure e servizi con rete finta). */
final class BlockERulesTest extends TestCase {

	private function http( array $reply, ?array &$calls = null ): callable {
		return function ( string $method, string $url, array $headers, ?string $body ) use ( $reply, &$calls ): array {
			$calls[] = array( 'method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body );
			return $reply;
		};
	}

	private function person( array $over = array() ): array {
		return $over + array( 'id' => 1, 'type' => 'ordinary', 'email' => 'Anna@Example.it', 'first_name' => 'Anna', 'last_name' => 'Rossi', 'active_until' => '2026-12-31', 'suspended_at' => null, 'anonymized_at' => null );
	}

	// ---------- SMS ----------

	public function test_phone_numbers_become_e164(): void {
		$this->assertSame( '+393331234567', SmsRules::e164( '333 123 4567' ) );
		$this->assertSame( '+393331234567', SmsRules::e164( '0039 333-1234567' ) );
		$this->assertSame( '+447911123456', SmsRules::e164( '+44 7911 123456' ) );
		$this->assertSame( '', SmsRules::e164( '12' ) );
		$this->assertSame( '', SmsRules::e164( '' ) );
		$this->assertSame( '+39 ••• 567', SmsRules::mask( '+393331234567' ) );
	}

	public function test_sms_length_and_parts(): void {
		$this->assertSame( array( 'encoding' => 'GSM-7', 'units' => 4, 'parts' => 1 ), SmsRules::measure( 'Ciao' ) );
		$this->assertSame( 1, SmsRules::measure( str_repeat( 'a', 160 ) )['parts'] );
		$this->assertSame( 2, SmsRules::measure( str_repeat( 'a', 161 ) )['parts'] );
		$this->assertSame( 2, SmsRules::measure( '€' )['units'], 'l\'euro conta doppio' );
		$this->assertSame( 6, SmsRules::measure( 'èéùìòà' )['units'], 'le vocali accentate italiane minuscole stanno nell\'alfabeto GSM' );
		$u = SmsRules::measure( 'Ciao 😀' );
		$this->assertSame( 'UCS-2', $u['encoding'] );
		$this->assertSame( 1, $u['parts'] );
		$this->assertSame( 2, SmsRules::measure( str_repeat( 'a', 70 ) . '😀' )['parts'], '72 unità in UCS-2 non stanno in un solo SMS da 70' );
		$this->assertSame( 0, SmsRules::measure( '' )['parts'] );
	}

	public function test_text_rules(): void {
		$this->assertSame( 'Ciao Anna, da Pro Loco', SmsRules::personalize( "Ciao {nome},  da {associazione}\r\n", 'Anna', 'Pro Loco' ) );
		$this->assertNull( SmsRules::check_text( 'ok' ) );
		$this->assertNotNull( SmsRules::check_text( '  ' ) );
		$this->assertNotNull( SmsRules::check_text( str_repeat( 'x', SmsRules::MAX_CHARS + 1 ) ) );
		$this->assertSame( 'Pro Loco: Tessera in scadenza. La tua tessera scade il 31/12.', SmsRules::reminder_text( 'Pro Loco', 'Tessera in scadenza', "La tua tessera\nscade il 31/12." ) );
		$this->assertSame( 10, mb_strlen( SmsRules::truncate( str_repeat( 'a', 50 ), 10 ) ) );
	}

	public function test_sender_and_retries(): void {
		$this->assertSame( 'Associazion', SmsRules::clean_sender( 'Associazione Semplice APS' ) );
		$this->assertSame( 'ASSOC1', SmsRules::clean_sender( 'ASSOC-1' ) );
		$this->assertSame( '+393331234567', SmsRules::clean_sender( '+393331234567' ) );
		$this->assertSame( 15, SmsRules::retry_after_minutes( 1, false ) );
		$this->assertSame( 120, SmsRules::retry_after_minutes( 2, false ) );
		$this->assertNull( SmsRules::retry_after_minutes( 3, false ), 'dopo tre tentativi basta' );
		$this->assertNull( SmsRules::retry_after_minutes( 1, true ), 'errore definitivo: inutile riprovare' );
	}

	public function test_brevo_sms_send_and_check(): void {
		$calls = array();
		$sms   = new BrevoSms( $this->http( array( 'code' => 201, 'body' => '{"messageId":"m1","smsCount":2,"remainingCredits":40}' ), $calls ), array( 'sms_brevo_key' => 'k' ) );
		$r     = $sms->send( '+393331234567', 'Ciao', 'Assoc' );
		$this->assertTrue( $r['ok'] );
		$this->assertSame( 'm1', $r['ref'] );
		$this->assertSame( 2, $r['parts'] );
		$this->assertSame( 'k', $calls[0]['headers']['api-key'] );
		$body = json_decode( $calls[0]['body'], true );
		$this->assertSame( '393331234567', $body['recipient'], 'Brevo vuole il numero senza il +' );
		$this->assertSame( 'Assoc', $body['sender'] );
		$bad = new BrevoSms( $this->http( array( 'code' => 400, 'body' => '{"message":"Invalid phone number"}' ) ), array( 'sms_brevo_key' => 'k' ) );
		$r   = $bad->send( '+393331234567', 'Ciao', 'A' );
		$this->assertFalse( $r['ok'] );
		$this->assertTrue( $r['permanent'] );
		$busy = new BrevoSms( $this->http( array( 'code' => 503, 'body' => '' ) ), array( 'sms_brevo_key' => 'k' ) );
		$this->assertFalse( $busy->send( '+393331234567', 'Ciao', 'A' )['permanent'], 'un errore del server si riprova' );
		$chk = new BrevoSms( $this->http( array( 'code' => 200, 'body' => '{"plan":[{"type":"free","credits":10},{"type":"sms","credits":75}]}' ) ), array( 'sms_brevo_key' => 'k' ) );
		$c   = $chk->check();
		$this->assertTrue( $c['ok'] );
		$this->assertSame( '75', $c['credits'] );
		$this->assertNotSame( '', ( new BrevoSms( $this->http( array() ), array() ) )->missing() );
	}

	public function test_twilio_sms_send_and_check(): void {
		$calls = array();
		$sms   = new TwilioSms( $this->http( array( 'code' => 201, 'body' => '{"sid":"SM1","num_segments":"1"}' ), $calls ), array( 'sms_twilio_sid' => 'AC1', 'sms_twilio_token' => 't', 'sms_twilio_from' => '+15005550006' ) );
		$r     = $sms->send( '+393331234567', 'Ciao à', 'Assoc' );
		$this->assertTrue( $r['ok'] );
		$this->assertSame( 'SM1', $r['ref'] );
		$this->assertStringContainsString( '/Accounts/AC1/Messages.json', $calls[0]['url'] );
		$this->assertSame( 'Basic ' . base64_encode( 'AC1:t' ), $calls[0]['headers']['Authorization'] );
		parse_str( (string) $calls[0]['body'], $form );
		$this->assertSame( '+393331234567', $form['To'] );
		$this->assertSame( '+15005550006', $form['From'] );
		$bad = new TwilioSms( $this->http( array( 'code' => 400, 'body' => '{"code":21211,"message":"Invalid To"}' ) ), array( 'sms_twilio_sid' => 'AC1', 'sms_twilio_token' => 't' ) );
		$r   = $bad->send( '+39', 'x', 'Assoc' );
		$this->assertFalse( $r['ok'] );
		$this->assertTrue( $r['permanent'] );
		$this->assertTrue( ( new TwilioSms( $this->http( array() ), array( 'sms_twilio_sid' => 'AC1', 'sms_twilio_token' => 't' ) ) )->send( '+39333', 'x', '' )['permanent'], 'senza mittente è inutile riprovare' );
		$chk = new TwilioSms( $this->http( array( 'code' => 200, 'body' => '{"balance":"12.50","currency":"EUR"}' ) ), array( 'sms_twilio_sid' => 'AC1', 'sms_twilio_token' => 't' ) );
		$this->assertSame( '12.50 EUR', $chk->check()['credits'] );
	}

	// ---------- Consensi e newsletter ----------

	public function test_consent_is_valid_only_when_given_and_not_revoked_after(): void {
		$this->assertFalse( ConsentRules::is_granted( null ) );
		$this->assertFalse( ConsentRules::is_granted( array( 'granted_at' => null, 'revoked_at' => '2026-01-01 10:00:00' ) ) );
		$this->assertTrue( ConsentRules::is_granted( array( 'granted_at' => '2026-01-01 10:00:00', 'revoked_at' => null ) ) );
		$this->assertFalse( ConsentRules::is_granted( array( 'granted_at' => '2026-01-01 10:00:00', 'revoked_at' => '2026-02-01 10:00:00' ) ) );
		$this->assertTrue( ConsentRules::is_granted( array( 'granted_at' => '2026-03-01 10:00:00', 'revoked_at' => '2026-02-01 10:00:00' ) ), 'dato di nuovo dopo la revoca' );
		$this->assertTrue( ConsentRules::is_channel( 'sms' ) );
		$this->assertFalse( ConsentRules::is_channel( 'posta' ) );
		$this->assertSame( 'admin', ConsentRules::source( 'sconosciuta' ) );
	}

	public function test_newsletter_subscribes_only_with_consent_and_valid_email(): void {
		$today = '2026-06-15';
		$this->assertSame( NewsletterRules::SKIP, NewsletterRules::action( $this->person(), false, null, $today, false ), 'senza consenso non si iscrive' );
		$this->assertSame( NewsletterRules::SUBSCRIBE, NewsletterRules::action( $this->person(), true, null, $today, false ) );
		$this->assertSame( NewsletterRules::SKIP, NewsletterRules::action( $this->person( array( 'email' => 'non-una-email' ) ), true, null, $today, false ) );
		$this->assertSame( NewsletterRules::SKIP, NewsletterRules::action( $this->person( array( 'email' => '' ) ), true, null, $today, false ) );
		$this->assertSame( NewsletterRules::SKIP, NewsletterRules::action( $this->person( array( 'anonymized_at' => '2026-01-01' ) ), true, null, $today, false ) );
	}

	public function test_newsletter_removes_who_revokes_changes_email_or_is_anonymized(): void {
		$today   = '2026-06-15';
		$contact = NewsletterRules::contact( $this->person(), $today );
		$synced  = array( 'status' => 'subscribed', 'email' => 'anna@example.it', 'hash' => NewsletterRules::hash( $contact, false ) );
		$this->assertSame( NewsletterRules::KEEP, NewsletterRules::action( $this->person(), true, $synced, $today, false ), 'nulla è cambiato' );
		$this->assertSame( NewsletterRules::REMOVE, NewsletterRules::action( $this->person(), false, $synced, $today, false ), 'consenso revocato' );
		$this->assertSame( NewsletterRules::REMOVE, NewsletterRules::action( $this->person( array( 'email' => null, 'anonymized_at' => '2026-06-01' ) ), true, $synced, $today, false ), 'anonimizzata' );
		$this->assertSame( NewsletterRules::SUBSCRIBE, NewsletterRules::action( $this->person( array( 'email' => 'nuova@example.it' ) ), true, $synced, $today, false ), 'nuovo indirizzo' );
		$this->assertSame( NewsletterRules::SUBSCRIBE, NewsletterRules::action( $this->person( array( 'first_name' => 'Anna Maria' ) ), true, $synced, $today, false ), 'nome cambiato' );
		$this->assertSame( NewsletterRules::SUBSCRIBE, NewsletterRules::action( $this->person(), true, $synced, $today, true ), 'cambia ciò che si invia' );
		$this->assertSame( NewsletterRules::SKIP, NewsletterRules::action( $this->person(), false, array( 'status' => 'removed', 'email' => '', 'hash' => null ), $today, false ), 'già tolta' );
		$this->assertSame( NewsletterRules::REMOVE, NewsletterRules::action( $this->person(), false, array( 'status' => 'remove_err', 'email' => 'anna@example.it', 'hash' => null ), $today, false ), 'la rimozione non era riuscita: si riprova' );
	}

	public function test_newsletter_contact_and_card_state(): void {
		$today = '2026-06-15';
		$c     = NewsletterRules::contact( $this->person(), $today );
		$this->assertSame( 'anna@example.it', $c['email'] );
		$this->assertSame( 'Socio ordinario', $c['type'] );
		$this->assertSame( 'attiva', $c['card'] );
		$this->assertSame( 'scaduta', NewsletterRules::card_state( $this->person( array( 'active_until' => '2026-06-14' ) ), $today ) );
		$this->assertSame( 'sospesa', NewsletterRules::card_state( $this->person( array( 'suspended_at' => '2026-01-01' ) ), $today ) );
		$this->assertSame( 'nessuna', NewsletterRules::card_state( $this->person( array( 'type' => 'guest' ) ), $today ) );
		$this->assertSame( 'attiva', NewsletterRules::card_state( $this->person( array( 'type' => 'founder', 'active_until' => null ) ), $today ) );
		$this->assertSame( md5( 'anna@example.it' ), NewsletterRules::mailchimp_hash( ' Anna@Example.it ' ) );
		$this->assertSame( 'us21', NewsletterRules::mailchimp_dc( 'abcdef-us21' ) );
		$this->assertSame( '', NewsletterRules::mailchimp_dc( 'abcdef' ) );
	}

	public function test_brevo_newsletter(): void {
		$calls = array();
		$nl    = new BrevoNewsletter( $this->http( array( 'code' => 201, 'body' => '{"id":5}' ), $calls ), array( 'newsletter_brevo_key' => 'k' ) );
		$c     = NewsletterRules::contact( $this->person(), '2026-06-15' );
		$nl->subscribe( $c, '7', false );
		$b = json_decode( $calls[0]['body'], true );
		$this->assertSame( array( 7 ), $b['listIds'] );
		$this->assertSame( 'anna@example.it', $b['email'] );
		$this->assertArrayNotHasKey( 'TIPO_SOCIO', $b['attributes'] );
		$nl->subscribe( $c, '7', true );
		$this->assertSame( 'attiva', json_decode( $calls[1]['body'], true )['attributes']['STATO_TESSERA'] );
		$nl->remove( 'anna@example.it', '7' );
		$this->assertStringContainsString( '/v3/contacts/lists/7/contacts/remove', $calls[2]['url'] );
		$lists = ( new BrevoNewsletter( $this->http( array( 'code' => 200, 'body' => '{"lists":[{"id":7,"name":"Soci"}]}' ) ), array( 'newsletter_brevo_key' => 'k' ) ) )->lists();
		$this->assertSame( array( '7' => 'Soci' ), $lists );
		$this->expectException( \RuntimeException::class );
		( new BrevoNewsletter( $this->http( array( 'code' => 400, 'body' => '{"message":"bad"}' ) ), array( 'newsletter_brevo_key' => 'k' ) ) )->subscribe( $c, '7', false );
	}

	public function test_mailchimp_never_resubscribes_who_unsubscribed(): void {
		$calls = array();
		$nl    = new MailchimpNewsletter( $this->http( array( 'code' => 200, 'body' => '{}' ), $calls ), array( 'newsletter_mailchimp_key' => 'abc-us21' ) );
		$c     = NewsletterRules::contact( $this->person(), '2026-06-15' );
		$nl->subscribe( $c, 'LST', false );
		$this->assertSame( 'PUT', $calls[0]['method'] );
		$this->assertStringStartsWith( 'https://us21.api.mailchimp.com/3.0/lists/LST/members/' . md5( 'anna@example.it' ), $calls[0]['url'] );
		$b = json_decode( $calls[0]['body'], true );
		$this->assertSame( 'subscribed', $b['status_if_new'] );
		$this->assertArrayNotHasKey( 'status', $b, 'senza `status` un contatto già disiscritto resta tale' );
		$nl->subscribe( $c, 'LST', true );
		$this->assertCount( 3, $calls, 'con i dati extra c\'è anche la chiamata delle etichette' );
		$nl->remove( 'anna@example.it', 'LST' );
		$this->assertSame( 'PATCH', $calls[3]['method'] );
		$this->assertSame( 'unsubscribed', json_decode( $calls[3]['body'], true )['status'] );
		$this->assertNotSame( '', ( new MailchimpNewsletter( $this->http( array() ), array( 'newsletter_mailchimp_key' => 'senza-datacenter' ) ) )->missing() );
	}

	public function test_mailpoet_uses_its_php_api(): void {
		$api = new class() {
			public $subscribers = array();
			public $log = array();
			public function getLists() {
				return array( array( 'id' => 3, 'name' => 'Soci' ) );
			}
			public function getSubscriber( $email ) {
				if ( ! isset( $this->subscribers[ $email ] ) ) {
					throw new \Exception( 'non esiste' );
				}
				return $this->subscribers[ $email ];
			}
			public function addSubscriber( $s, $lists, $opts ) {
				$this->subscribers[ $s['email'] ] = array( 'id' => count( $this->subscribers ) + 1, 'status' => 'subscribed' );
				$this->log[] = array( 'add', $lists, $opts );
			}
			public function subscribeToLists( $id, $lists, $opts ) {
				$this->log[] = array( 'sub', $id, $lists );
			}
			public function unsubscribeFromLists( $id, $lists ) {
				$this->log[] = array( 'unsub', $id, $lists );
			}
		};
		$nl = new MailPoetNewsletter( null, array(), $api );
		$c  = NewsletterRules::contact( $this->person(), '2026-06-15' );
		$this->assertSame( array( '3' => 'Soci' ), $nl->lists() );
		$nl->subscribe( $c, '3', false );
		$this->assertSame( 'add', $api->log[0][0] );
		$this->assertFalse( $api->log[0][2]['send_confirmation_email'], 'nessuna email di conferma automatica' );
		$nl->subscribe( $c, '3', false );
		$this->assertSame( 'sub', $api->log[1][0], 'già presente: si aggiunge solo alla lista' );
		$api->subscribers['anna@example.it']['status'] = 'unsubscribed';
		$nl->subscribe( $c, '3', false );
		$this->assertCount( 2, $api->log, 'chi si è disiscritto non viene reiscritto' );
		$nl->remove( 'anna@example.it', '3' );
		$this->assertSame( 'unsub', $api->log[2][0] );
		$nl->remove( 'altro@example.it', '3' );
		$this->assertCount( 3, $api->log, 'un contatto sconosciuto non è un errore' );
		$this->assertTrue( $nl->check()['ok'] );
	}

	// ---------- Satispay ----------

	public function test_satispay_signing_string_and_digest(): void {
		$this->assertSame( 'SHA-256=47DEQpj8HBSa+/TImW+5JCeuQeRkm5NMpJWZG3hSuFU=', SatispayRules::digest( '' ) );
		$this->assertSame( 'Thu, 01 Jan 1970 00:00:00 GMT', SatispayRules::http_date( 0 ) );
		$this->assertSame( 'staging.authservices.satispay.com', SatispayRules::host( SatispayRules::base( 'sandbox' ) ) );
		$this->assertSame( 'authservices.satispay.com', SatispayRules::host( SatispayRules::base( 'live' ) ) );
		$this->assertSame(
			"(request-target): post /g_business/v1/payments\nhost: h\ndate: d\ndigest: g",
			SatispayRules::signing_string( 'POST', '/g_business/v1/payments', 'h', 'd', 'g' )
		);
		$this->assertSame( 'Signature keyId="K", algorithm="rsa-sha256", headers="(request-target) host date digest", signature="S"', SatispayRules::authorization( 'K', 'S' ) );
		$this->assertSame( 'https://x.it/wp-json/a?payment_id={uuid}', SatispayRules::callback_url( 'https://x.it/wp-json/a' ) );
		$this->assertSame( 'https://x.it/?a=1&payment_id={uuid}', SatispayRules::callback_url( 'https://x.it/?a=1' ) );
		$this->assertSame( 'abc-123_Xscriptalert1script', SatispayRules::clean_id( "abc-123_X<script>alert(1)</script>" ), 'solo lettere, cifre, trattino e sottolineato' );
	}

	public function test_satispay_states_and_amount(): void {
		$this->assertTrue( SatispayRules::is_accepted( array( 'status' => 'ACCEPTED' ) ) );
		$this->assertFalse( SatispayRules::is_accepted( array( 'status' => 'PENDING' ) ) );
		$this->assertTrue( SatispayRules::is_failed( array( 'status' => 'CANCELED' ) ) );
		$this->assertFalse( SatispayRules::is_failed( array( 'status' => 'PENDING' ) ) );
		$this->assertSame( 1250, SatispayRules::paid_eur_cents( array( 'amount_unit' => 1250, 'currency' => 'EUR' ) ) );
		$this->assertSame( 0, SatispayRules::paid_eur_cents( array( 'amount_unit' => 1250, 'currency' => 'USD' ) ) );
		$items   = array( array( 'amount_cents' => 1250, 'label' => 'Quota 2026' ) );
		$payload = SatispayRules::payment_payload( $items, 'pub-1', 'https://x.it/cb?payment_id={uuid}', 'https://x.it/ok', 'Pro Loco' );
		$this->assertSame( 1250, $payload['amount_unit'] );
		$this->assertSame( 'EUR', $payload['currency'] );
		$this->assertSame( 'pub-1', $payload['external_code'] );
		$this->assertSame( 'Pro Loco: Quota 2026', $payload['description'] );
	}

	public function test_satispay_requests_are_really_signed(): void {
		if ( ! function_exists( 'openssl_pkey_new' ) ) {
			$this->markTestSkipped( 'OpenSSL non disponibile.' );
		}
		$keys = SatispayApi::generate_keys();
		$cfg  = array( 'satispay_mode' => 'sandbox', 'satispay_key_id' => 'KID', 'satispay_private_key' => $keys['private'] );
		$h    = SatispayApi::signed_headers( 'POST', '/g_business/v1/payments', '{"a":1}', $cfg, 1700000000 );
		$this->assertSame( SatispayRules::digest( '{"a":1}' ), $h['Digest'] );
		$this->assertSame( 'staging.authservices.satispay.com', $h['Host'] );
		$this->assertSame( 1, preg_match( '/signature="([^"]+)"/', $h['Authorization'], $m ) );
		$string = SatispayRules::signing_string( 'POST', '/g_business/v1/payments', $h['Host'], $h['Date'], $h['Digest'] );
		$this->assertSame( 1, openssl_verify( $string, base64_decode( $m[1] ), $keys['public'], OPENSSL_ALGO_SHA256 ), 'la firma si verifica con la chiave pubblica' );

		$calls = array();
		$p     = SatispayApi::create_payment( $this->http( array( 'code' => 200, 'body' => '{"id":"pay-1","status":"PENDING","redirect_url":"https://online.satispay.com/pay/pay-1"}' ), $calls ), $cfg, array( 'amount_unit' => 100 ) );
		$this->assertSame( array( 'id' => 'pay-1', 'url' => 'https://online.satispay.com/pay/pay-1' ), $p );
		$this->assertSame( 'https://staging.authservices.satispay.com/g_business/v1/payments', $calls[0]['url'] );
		$this->assertStringStartsWith( 'Signature keyId="KID"', $calls[0]['headers']['Authorization'] );
		$r = SatispayApi::retrieve_payment( $this->http( array( 'code' => 200, 'body' => '{"id":"pay-1","status":"ACCEPTED","amount_unit":100,"currency":"EUR"}' ) ), $cfg, 'pay-1' );
		$this->assertTrue( SatispayRules::is_accepted( $r ) );
		$this->assertSame( 'KID2', SatispayApi::register_key( $this->http( array( 'code' => 200, 'body' => '{"key_id":"KID2"}' ), $calls ), 'sandbox', 'TOKEN', $keys['public'] ) );
		$this->assertStringContainsString( '/g_business/v1/authentication_keys', $calls[1]['url'] );
		$this->assertSame( 'TOKEN', json_decode( $calls[1]['body'], true )['token'] );
	}

	public function test_satispay_refuses_an_unusable_signing_key(): void {
		$cfg = array( 'satispay_mode' => 'sandbox', 'satispay_key_id' => 'KID', 'satispay_private_key' => 'non una chiave' );
		$this->expectException( \RuntimeException::class );
		SatispayApi::retrieve_payment( $this->http( array( 'code' => 200, 'body' => '{}' ) ), $cfg, 'x' );
	}

	public function test_satispay_is_a_payment_choice(): void {
		$this->assertSame( array( 'satispay' ), P::gateways_of( 'satispay' ) );
		$this->assertSame( array( 'stripe', 'satispay' ), P::gateways_of( 'stripe_satispay' ) );
		$this->assertSame( array( 'stripe', 'paypal', 'sumup', 'satispay' ), P::gateways_of( 'stripe_paypal_sumup_satispay' ) );
		$this->assertSame( array(), P::gateways_of( 'woocommerce' ) );
		$this->assertSame( array(), P::gateways_of( 'inventato' ) );
		$this->assertTrue( P::is_valid( 'paypal_satispay' ) );
		$this->assertFalse( P::is_valid( 'satispay_stripe' ), 'l\'ordine è sempre lo stesso' );
		$this->assertCount( 17, P::providers(), 'nessuno, WooCommerce e le 15 combinazioni' );
		foreach ( array( 'stripe_paypal', 'stripe_sumup', 'paypal_sumup', 'stripe_paypal_sumup', 'sumup', 'stripe', 'paypal' ) as $old ) {
			$this->assertTrue( P::is_valid( $old ), "$old resta valido" );
		}
		$bad = P::validate_gateway( array( 'site_https' => 1 ), P::SATISPAY );
		$this->assertNotEmpty( $bad['errors'], 'senza collegamento c\'è un errore' );
		$ok = P::validate_gateway( array( 'satispay_key_id' => 'k', 'satispay_private_key' => 'pem', 'site_https' => 1 ), P::SATISPAY );
		$this->assertSame( array(), $ok['errors'] );
		$http = P::validate_gateway( array( 'satispay_key_id' => 'k', 'satispay_private_key' => 'pem', 'site_https' => 0 ), P::SATISPAY );
		$this->assertSame( array(), $http['errors'], 'in prova senza https basta un avviso' );
		$this->assertNotEmpty( $http['warnings'] );
		$live = P::validate_gateway( array( 'satispay_mode' => 'live', 'satispay_key_id' => 'k', 'satispay_private_key' => 'pem', 'site_https' => 0 ), P::SATISPAY );
		$this->assertNotEmpty( $live['errors'], 'in modalità reale la conferma automatica richiede https' );
		$this->assertNotSame( '', P::label( P::SATISPAY ) );
		$this->assertNotSame( '', P::note( P::SATISPAY ) );
	}

	// ---------- Klarna ----------

	public function test_klarna_requirements(): void {
		$this->assertFalse( KlarnaRules::check( false, 5000, 'a@b.it' )['ok'], 'spento' );
		$this->assertTrue( KlarnaRules::check( true, 5000, 'a@b.it' )['ok'] );
		$this->assertFalse( KlarnaRules::check( true, 5000, 'a@b.it', 'USD' )['ok'], 'solo euro' );
		$this->assertFalse( KlarnaRules::check( true, KlarnaRules::MIN_CENTS - 1, 'a@b.it' )['ok'], 'sotto il minimo' );
		$this->assertTrue( KlarnaRules::check( true, KlarnaRules::MIN_CENTS, 'a@b.it' )['ok'] );
		$this->assertFalse( KlarnaRules::check( true, KlarnaRules::MAX_CENTS + 1, 'a@b.it' )['ok'], 'sopra il massimo' );
		$this->assertFalse( KlarnaRules::check( true, 5000, '' )['ok'], 'serve l\'email' );
		$this->assertFalse( KlarnaRules::check( true, 5000, 'non-email' )['ok'] );
		$this->assertNotSame( '', KlarnaRules::check( true, 5000, '' )['reason'] );
		$this->assertSame( array( 'card' ), KlarnaRules::payment_method_types( false ) );
		$this->assertSame( array( 'card', 'klarna' ), KlarnaRules::payment_method_types( true ) );
	}

	public function test_klarna_in_the_stripe_checkout_session(): void {
		$items = array( array( 'amount_cents' => 5000, 'label' => 'Quota' ) );
		$off   = StripeApi::checkout_params( $items, 'pub', 'a@b.it', 'https://x/ok', 'https://x/no' );
		$this->assertArrayNotHasKey( 'payment_method_types', $off, 'senza Klarna la sessione è quella di sempre' );
		$on = StripeApi::checkout_params( $items, 'pub', 'a@b.it', 'https://x/ok', 'https://x/no', true );
		$this->assertSame( array( 'card', 'klarna' ), $on['payment_method_types'] );
		$this->assertSame( 'eur', $on['line_items'][0]['price_data']['currency'] );
		$this->assertSame( 'a@b.it', $on['customer_email'] );
		$this->assertStringContainsString( 'payment_method_types%5B1%5D=klarna', http_build_query( $on, '', '&', PHP_QUERY_RFC3986 ) );
	}

	public function test_klarna_deferred_sessions(): void {
		$this->assertTrue( KlarnaRules::is_deferred( array( 'payment_status' => 'unpaid', 'status' => 'complete' ) ) );
		$this->assertFalse( KlarnaRules::is_deferred( array( 'payment_status' => 'paid', 'status' => 'complete' ) ) );
		$this->assertFalse( KlarnaRules::is_deferred( array( 'payment_status' => 'unpaid', 'status' => 'expired' ) ) );
		$this->assertFalse( StripeApi::is_paid( array( 'payment_status' => 'unpaid' ) ), 'finché non è pagata non si registra nulla' );
		$warn = P::validate_gateway( array( 'stripe_mode' => 'test', 'stripe_publishable_key' => 'pk_test_abc', 'stripe_secret_key' => 'sk_test_abc', 'stripe_webhook_secret' => 'whsec_x', 'stripe_klarna' => 1 ), P::STRIPE );
		$this->assertSame( array(), $warn['errors'] );
		$this->assertStringContainsString( 'Klarna', implode( ' ', $warn['warnings'] ) );
	}
}
