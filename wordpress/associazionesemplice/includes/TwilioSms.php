<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/** SMS con Twilio: Account SID, Auth Token e mittente (un numero Twilio o un nome alfanumerico). */
final class TwilioSms implements SmsProvider {

	const BASE = 'https://api.twilio.com';

	/** Errori di Twilio per cui riprovare è inutile (numero non valido, non raggiungibile, disiscritto…). */
	const PERMANENT_CODES = array( 21211, 21214, 21408, 21610, 21612, 21614, 30003, 30004, 30005, 30006 );

	/** @var callable */
	private $http;
	/** @var array */
	private $cfg;

	public function __construct( callable $http, array $cfg ) {
		$this->http = $http;
		$this->cfg  = $cfg;
	}

	public function id(): string {
		return 'twilio';
	}

	public function label(): string {
		return 'Twilio';
	}

	public static function fields(): array {
		return array( 'sms_twilio_sid' => 'Account SID di Twilio', 'sms_twilio_token' => 'Auth Token di Twilio', 'sms_twilio_from' => 'Numero o mittente Twilio (facoltativo se c\'è il mittente generale)' );
	}

	public static function secret_fields(): array {
		return array( 'sms_twilio_token' );
	}

	public function missing(): string {
		if ( '' === trim( (string) ( $this->cfg['sms_twilio_sid'] ?? '' ) ) || '' === trim( (string) ( $this->cfg['sms_twilio_token'] ?? '' ) ) ) {
			return 'Inserisci Account SID e Auth Token di Twilio.';
		}
		return '';
	}

	private function sid(): string {
		return rawurlencode( trim( (string) $this->cfg['sms_twilio_sid'] ) );
	}

	private function headers(): array {
		return array(
			'Authorization' => 'Basic ' . base64_encode( trim( (string) $this->cfg['sms_twilio_sid'] ) . ':' . trim( (string) $this->cfg['sms_twilio_token'] ) ),
			'Accept'        => 'application/json',
			'Content-Type'  => 'application/x-www-form-urlencoded',
		);
	}

	public function send( string $to, string $text, string $sender ): array {
		$from = trim( (string) ( $this->cfg['sms_twilio_from'] ?? '' ) );
		if ( '' === $from ) {
			$from = $sender;
		}
		if ( '' === $from ) {
			return array( 'ok' => false, 'ref' => '', 'error' => 'Twilio: manca il numero o il mittente.', 'permanent' => true, 'parts' => 0 );
		}
		$form = http_build_query( array( 'To' => $to, 'From' => $from, 'Body' => $text ), '', '&', PHP_QUERY_RFC3986 );
		$r    = ( $this->http )( 'POST', self::BASE . '/2010-04-01/Accounts/' . $this->sid() . '/Messages.json', $this->headers(), $form );
		$data = json_decode( (string) $r['body'], true );
		$code = (int) $r['code'];
		if ( $code >= 200 && $code < 300 && is_array( $data ) ) {
			return array( 'ok' => true, 'ref' => (string) ( $data['sid'] ?? '' ), 'error' => '', 'permanent' => false, 'parts' => max( 1, (int) ( $data['num_segments'] ?? 1 ) ) );
		}
		$err_code = is_array( $data ) ? (int) ( $data['code'] ?? 0 ) : 0;
		$msg      = is_array( $data ) && isset( $data['message'] ) ? (string) $data['message'] : 'risposta ' . $code;
		$perm     = in_array( $err_code, self::PERMANENT_CODES, true ) || in_array( $code, array( 401, 403 ), true );
		return array( 'ok' => false, 'ref' => '', 'error' => mb_substr( 'Twilio: ' . $msg, 0, 240 ), 'permanent' => $perm, 'parts' => 0 );
	}

	public function check(): array {
		$r    = ( $this->http )( 'GET', self::BASE . '/2010-04-01/Accounts/' . $this->sid() . '/Balance.json', $this->headers(), null );
		$data = json_decode( (string) $r['body'], true );
		if ( 200 === (int) $r['code'] && is_array( $data ) ) {
			$credits = isset( $data['balance'] ) ? trim( (string) $data['balance'] . ' ' . (string) ( $data['currency'] ?? '' ) ) : null;
			return array( 'ok' => true, 'message' => 'Twilio: connessione riuscita.', 'credits' => $credits );
		}
		if ( in_array( (int) $r['code'], array( 401, 403 ), true ) ) {
			return array( 'ok' => false, 'message' => 'Twilio: Account SID o Auth Token non validi.', 'credits' => null );
		}
		return array( 'ok' => false, 'message' => 'Twilio: risposta inattesa (' . (int) $r['code'] . ').', 'credits' => null );
	}
}
