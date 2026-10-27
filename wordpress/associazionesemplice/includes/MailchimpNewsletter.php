<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Newsletter con Mailchimp: audience e contatti (chiave API del tipo «abc…-us21»).
 * Chi si è già disiscritto da Mailchimp resta disiscritto: il sito non lo reiscrive mai.
 */
final class MailchimpNewsletter implements NewsletterProvider {

	/** @var callable */
	private $http;
	/** @var array */
	private $cfg;

	public function __construct( callable $http, array $cfg ) {
		$this->http = $http;
		$this->cfg  = $cfg;
	}

	public function id(): string {
		return 'mailchimp';
	}

	public function label(): string {
		return 'Mailchimp';
	}

	public static function fields(): array {
		return array( 'newsletter_mailchimp_key' => 'Chiave API di Mailchimp (finisce con -us21 o simile)' );
	}

	public static function secret_fields(): array {
		return array( 'newsletter_mailchimp_key' );
	}

	public function missing(): string {
		$key = trim( (string) ( $this->cfg['newsletter_mailchimp_key'] ?? '' ) );
		if ( '' === $key ) {
			return 'Inserisci la chiave API di Mailchimp.';
		}
		return '' === NewsletterRules::mailchimp_dc( $key ) ? 'La chiave di Mailchimp deve finire con il datacenter, ad esempio «-us21».' : '';
	}

	/** @return array{code:int,data:mixed} */
	private function call( string $method, string $path, ?array $json = null ): array {
		$key     = trim( (string) $this->cfg['newsletter_mailchimp_key'] );
		$headers = array( 'Authorization' => 'Basic ' . base64_encode( 'associazionesemplice:' . $key ), 'Accept' => 'application/json' );
		$body    = null;
		if ( null !== $json ) {
			$headers['Content-Type'] = 'application/json';
			$body                    = (string) json_encode( $json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		}
		$r = ( $this->http )( $method, 'https://' . NewsletterRules::mailchimp_dc( $key ) . '.api.mailchimp.com/3.0' . $path, $headers, $body );
		return array( 'code' => (int) $r['code'], 'data' => json_decode( (string) $r['body'], true ) );
	}

	private static function fail( array $r ): \RuntimeException {
		$msg = is_array( $r['data'] ) && isset( $r['data']['detail'] ) ? (string) $r['data']['detail'] : 'risposta ' . $r['code'];
		return new \RuntimeException( 'Mailchimp: ' . $msg ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
	}

	public function lists(): array {
		$r = $this->call( 'GET', '/lists?count=100&fields=lists.id,lists.name' );
		if ( 200 !== $r['code'] || ! is_array( $r['data'] ) ) {
			throw self::fail( $r );
		}
		$out = array();
		foreach ( (array) ( $r['data']['lists'] ?? array() ) as $l ) {
			if ( is_array( $l ) && ! empty( $l['id'] ) ) {
				$out[ (string) $l['id'] ] = (string) ( $l['name'] ?? $l['id'] );
			}
		}
		return $out;
	}

	public function subscribe( array $contact, string $list, bool $extra ): void {
		$hash = NewsletterRules::mailchimp_hash( $contact['email'] );
		// Solo `status_if_new`: un contatto già presente (anche disiscritto) mantiene il suo stato, si aggiornano solo i dati.
		$r = $this->call(
			'PUT',
			'/lists/' . rawurlencode( $list ) . '/members/' . $hash,
			array( 'email_address' => $contact['email'], 'status_if_new' => 'subscribed', 'merge_fields' => array( 'FNAME' => $contact['first_name'], 'LNAME' => $contact['last_name'] ) )
		);
		if ( $r['code'] < 200 || $r['code'] >= 300 ) {
			throw self::fail( $r );
		}
		if ( $extra ) { // tipo di socio e stato della tessera come etichette (si creano da sole)
			$tags = array();
			foreach ( array( 'attiva', 'scaduta', 'sospesa', 'nessuna' ) as $state ) {
				$tags[] = array( 'name' => 'Tessera ' . $state, 'status' => $state === $contact['card'] ? 'active' : 'inactive' );
			}
			if ( '' !== $contact['type'] ) {
				$tags[] = array( 'name' => 'Tipo: ' . $contact['type'], 'status' => 'active' );
			}
			$t = $this->call( 'POST', '/lists/' . rawurlencode( $list ) . '/members/' . $hash . '/tags', array( 'tags' => $tags ) );
			if ( $t['code'] < 200 || $t['code'] >= 300 ) {
				throw self::fail( $t );
			}
		}
	}

	public function remove( string $email, string $list ): void {
		$r = $this->call( 'PATCH', '/lists/' . rawurlencode( $list ) . '/members/' . NewsletterRules::mailchimp_hash( $email ), array( 'status' => 'unsubscribed' ) );
		if ( ( $r['code'] < 200 || $r['code'] >= 300 ) && 404 !== $r['code'] ) {
			throw self::fail( $r );
		}
	}

	public function check(): array {
		$r = $this->call( 'GET', '/ping' );
		if ( 200 === $r['code'] ) {
			return array( 'ok' => true, 'message' => 'Mailchimp: connessione riuscita.' );
		}
		return array( 'ok' => false, 'message' => in_array( $r['code'], array( 401, 403 ), true ) ? 'Mailchimp: la chiave API non è valida.' : 'Mailchimp: risposta inattesa (' . $r['code'] . ').' );
	}
}
