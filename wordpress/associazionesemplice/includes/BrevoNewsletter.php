<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/** Newsletter con Brevo: contatti e liste (chiave API v3). */
final class BrevoNewsletter implements NewsletterProvider {

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
		return array( 'newsletter_brevo_key' => 'Chiave API di Brevo (v3)' );
	}

	public static function secret_fields(): array {
		return array( 'newsletter_brevo_key' );
	}

	public function missing(): string {
		return '' === trim( (string) ( $this->cfg['newsletter_brevo_key'] ?? '' ) ) ? 'Inserisci la chiave API di Brevo.' : '';
	}

	/** @return array{code:int,data:mixed} */
	private function call( string $method, string $path, ?array $json = null ): array {
		$headers = array( 'api-key' => trim( (string) $this->cfg['newsletter_brevo_key'] ), 'Accept' => 'application/json' );
		$body    = null;
		if ( null !== $json ) {
			$headers['Content-Type'] = 'application/json';
			$body                    = (string) json_encode( $json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		}
		$r = ( $this->http )( $method, self::BASE . $path, $headers, $body );
		return array( 'code' => (int) $r['code'], 'data' => json_decode( (string) $r['body'], true ) );
	}

	private static function fail( array $r ): \RuntimeException {
		$msg = is_array( $r['data'] ) && isset( $r['data']['message'] ) ? (string) $r['data']['message'] : 'risposta ' . $r['code'];
		return new \RuntimeException( 'Brevo: ' . $msg ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
	}

	public function lists(): array {
		$r = $this->call( 'GET', '/v3/contacts/lists?limit=50&offset=0' );
		if ( 200 !== $r['code'] || ! is_array( $r['data'] ) ) {
			throw self::fail( $r );
		}
		$out = array();
		foreach ( (array) ( $r['data']['lists'] ?? array() ) as $l ) {
			if ( is_array( $l ) && isset( $l['id'] ) ) {
				$out[ (string) $l['id'] ] = (string) ( $l['name'] ?? $l['id'] );
			}
		}
		return $out;
	}

	public function subscribe( array $contact, string $list, bool $extra ): void {
		$attrs = array( 'FIRSTNAME' => $contact['first_name'], 'LASTNAME' => $contact['last_name'] );
		if ( $extra ) { // richiedono due campi personalizzati di testo creati in Brevo: TIPO_SOCIO e STATO_TESSERA
			$attrs['TIPO_SOCIO']    = $contact['type'];
			$attrs['STATO_TESSERA'] = $contact['card'];
		}
		$r = $this->call( 'POST', '/v3/contacts', array( 'email' => $contact['email'], 'attributes' => $attrs, 'listIds' => array( (int) $list ), 'updateEnabled' => true ) );
		if ( $r['code'] < 200 || $r['code'] >= 300 ) {
			throw self::fail( $r );
		}
	}

	public function remove( string $email, string $list ): void {
		$r = $this->call( 'POST', '/v3/contacts/lists/' . (int) $list . '/contacts/remove', array( 'emails' => array( $email ) ) );
		if ( ( $r['code'] < 200 || $r['code'] >= 300 ) && 404 !== $r['code'] ) {
			throw self::fail( $r );
		}
	}

	public function check(): array {
		$r = $this->call( 'GET', '/v3/account' );
		if ( 200 === $r['code'] ) {
			return array( 'ok' => true, 'message' => 'Brevo: connessione riuscita.' );
		}
		return array( 'ok' => false, 'message' => in_array( $r['code'], array( 401, 403 ), true ) ? 'Brevo: la chiave API non è valida.' : 'Brevo: risposta inattesa (' . $r['code'] . ').' );
	}
}
