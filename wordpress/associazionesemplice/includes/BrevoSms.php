<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/** SMS transazionali con Brevo (ex Sendinblue): chiave API v3 e mittente alfanumerico. */
final class BrevoSms implements SmsProvider {

	const BASE = 'https://api.brevo.com';

	/** @var callable */
	private $http;
	/** @var array */
	private $cfg;

	public function __construct( callable $http, array $cfg ) {
		$this->http = $http;
		$this->cfg  = $cfg;
	}

	public function id(): string {
		return 'brevo';
	}

	public function label(): string {
		return 'Brevo';
	}

	public static function fields(): array {
		return array( 'sms_brevo_key' => 'Chiave API di Brevo (v3)' );
	}

	public static function secret_fields(): array {
		return array( 'sms_brevo_key' );
	}

	public function missing(): string {
		return '' === trim( (string) ( $this->cfg['sms_brevo_key'] ?? '' ) ) ? 'Inserisci la chiave API di Brevo.' : '';
	}

	private function headers(): array {
		return array( 'api-key' => trim( (string) $this->cfg['sms_brevo_key'] ), 'Accept' => 'application/json', 'Content-Type' => 'application/json' );
	}

	public function send( string $to, string $text, string $sender ): array {
		$body = (string) json_encode(
			array( 'sender' => '' !== $sender ? $sender : 'Info', 'recipient' => ltrim( $to, '+' ), 'content' => $text, 'type' => 'transactional' ),
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);
		$r    = ( $this->http )( 'POST', self::BASE . '/v3/transactionalSMS/sms', $this->headers(), $body );
		$data = json_decode( (string) $r['body'], true );
		$code = (int) $r['code'];
		if ( $code >= 200 && $code < 300 && is_array( $data ) ) {
			return array( 'ok' => true, 'ref' => (string) ( $data['messageId'] ?? $data['reference'] ?? '' ), 'error' => '', 'permanent' => false, 'parts' => max( 1, (int) ( $data['smsCount'] ?? 1 ) ) );
		}
		$msg = is_array( $data ) && isset( $data['message'] ) ? (string) $data['message'] : 'risposta ' . $code;
		return array( 'ok' => false, 'ref' => '', 'error' => mb_substr( 'Brevo: ' . $msg, 0, 240 ), 'permanent' => in_array( $code, array( 400, 401, 402, 403 ), true ), 'parts' => 0 );
	}

	public function check(): array {
		$r    = ( $this->http )( 'GET', self::BASE . '/v3/account', $this->headers(), null );
		$data = json_decode( (string) $r['body'], true );
		if ( 200 === (int) $r['code'] && is_array( $data ) ) {
			$credits = null;
			foreach ( (array) ( $data['plan'] ?? array() ) as $plan ) {
				if ( is_array( $plan ) && 'sms' === strtolower( (string) ( $plan['type'] ?? '' ) ) && isset( $plan['credits'] ) ) {
					$credits = (string) ( (float) ( $credits ?? 0 ) + (float) $plan['credits'] );
				}
			}
			return array( 'ok' => true, 'message' => 'Brevo: connessione riuscita.', 'credits' => $credits );
		}
		if ( in_array( (int) $r['code'], array( 401, 403 ), true ) ) {
			return array( 'ok' => false, 'message' => 'Brevo: la chiave API non è valida.', 'credits' => null );
		}
		return array( 'ok' => false, 'message' => 'Brevo: risposta inattesa (' . (int) $r['code'] . ').', 'credits' => null );
	}
}
