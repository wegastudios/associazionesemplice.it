<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Prova di connessione ai gateway con le chiavi salvate: parte solo quando l'amministratore preme "Verifica connessione".
 * Non muove denaro: Stripe → lettura del saldo; PayPal → richiesta del token di accesso.
 * La chiamata di rete è iniettata ($http) così la logica si prova senza rete.
 */
final class Gateways {

	/**
	 * @param string   $provider PaymentConfig::STRIPE | PAYPAL
	 * @param array    $c        configurazione con i segreti in chiaro
	 * @param callable $http     function( string $method, string $url, array $headers, ?string $body ): array{code:int, body:string}
	 * @return array ['ok'=>bool, 'message'=>string]
	 */
	public static function test( string $provider, array $c, callable $http ): array {
		try {
			if ( PaymentConfig::STRIPE === $provider ) {
				return self::test_stripe( $c, $http );
			}
			if ( PaymentConfig::PAYPAL === $provider ) {
				return self::test_paypal( $c, $http );
			}
			if ( PaymentConfig::SUMUP === $provider ) {
				return self::test_sumup( $c, $http );
			}
			if ( SatispayRules::ID === $provider ) {
				return self::test_satispay( $c, $http );
			}
		} catch ( \Throwable $e ) {
			return array( 'ok' => false, 'message' => 'Connessione non riuscita: ' . $e->getMessage() );
		}
		return array( 'ok' => false, 'message' => 'Scegli Stripe, PayPal, SumUp o Satispay per fare la prova.' );
	}

	/** Satispay: richiesta firmata al punto di prova della firma (non muove denaro). */
	private static function test_satispay( array $c, callable $http ): array {
		if ( '' === trim( (string) ( $c['satispay_key_id'] ?? '' ) ) || '' === trim( (string) ( $c['satispay_private_key'] ?? '' ) ) ) {
			return array( 'ok' => false, 'message' => 'Collega prima Satispay con il codice di attivazione.' );
		}
		$mode = 'live' === ( $c['satispay_mode'] ?? 'sandbox' ) ? 'reale' : 'sandbox';
		try {
			SatispayApi::test_signature( $http, $c );
		} catch ( \RuntimeException $e ) {
			return array( 'ok' => false, 'message' => $e->getMessage() );
		}
		return array( 'ok' => true, 'message' => 'Satispay: connessione e firma riuscite (modalità ' . $mode . ').' );
	}

	/** SumUp: lettura del profilo dell'account con la chiave API (non muove denaro). */
	private static function test_sumup( array $c, callable $http ): array {
		$key = trim( (string) ( $c['sumup_api_key'] ?? '' ) );
		if ( '' === $key ) {
			return array( 'ok' => false, 'message' => 'Inserisci prima la chiave API di SumUp.' );
		}
		$r    = $http( 'GET', SumUpApi::BASE . '/v0.1/me', array( 'Authorization' => 'Bearer ' . $key, 'Accept' => 'application/json' ), null );
		$data = json_decode( (string) $r['body'], true );
		if ( 200 === (int) $r['code'] && is_array( $data ) ) {
			$code  = trim( (string) ( $c['sumup_merchant_code'] ?? '' ) );
			$mine  = (string) ( $data['merchant_profile']['merchant_code'] ?? '' );
			if ( '' !== $code && '' !== $mine && 0 !== strcasecmp( $code, $mine ) ) {
				return array( 'ok' => false, 'message' => 'SumUp: la chiave funziona ma appartiene al commerciante ' . $mine . ', non a ' . $code . '.' );
			}
			return array( 'ok' => true, 'message' => 'SumUp: connessione riuscita' . ( '' !== $mine ? ' (commerciante ' . $mine . ')' : '' ) . '.' );
		}
		if ( in_array( (int) $r['code'], array( 401, 403 ), true ) ) {
			return array( 'ok' => false, 'message' => 'SumUp: la chiave API non è valida.' );
		}
		return array( 'ok' => false, 'message' => 'SumUp: risposta inattesa (' . (int) $r['code'] . ').' );
	}

	private static function test_stripe( array $c, callable $http ): array {
		$sk = trim( (string) ( $c['stripe_secret_key'] ?? '' ) );
		if ( '' === $sk ) {
			return array( 'ok' => false, 'message' => 'Inserisci prima la chiave segreta di Stripe.' );
		}
		$r    = $http( 'GET', 'https://api.stripe.com/v1/balance', array( 'Authorization' => 'Bearer ' . $sk ), null );
		$data = json_decode( (string) $r['body'], true );
		if ( 200 === (int) $r['code'] ) {
			$live = is_array( $data ) && ! empty( $data['livemode'] );
			$mode = 'live' === ( $c['stripe_mode'] ?? 'test' ) ? 'live' : 'test';
			if ( $live !== ( 'live' === $mode ) ) {
				return array( 'ok' => false, 'message' => 'La chiave funziona ma è ' . ( $live ? 'REALE (live)' : 'di PROVA (test)' ) . ', mentre la modalità scelta è ' . ( 'live' === $mode ? 'reale' : 'di prova' ) . '.' );
			}
			return array( 'ok' => true, 'message' => 'Stripe: connessione riuscita (' . ( $live ? 'modalità reale' : 'modalità di prova' ) . ').' );
		}
		if ( 401 === (int) $r['code'] ) {
			return array( 'ok' => false, 'message' => 'Stripe: la chiave segreta non è valida.' );
		}
		$err = is_array( $data ) && isset( $data['error']['message'] ) ? (string) $data['error']['message'] : 'risposta ' . (int) $r['code'];
		return array( 'ok' => false, 'message' => 'Stripe: ' . $err );
	}

	private static function test_paypal( array $c, callable $http ): array {
		$id  = trim( (string) ( $c['paypal_client_id'] ?? '' ) );
		$sec = trim( (string) ( $c['paypal_client_secret'] ?? '' ) );
		if ( '' === $id || '' === $sec ) {
			return array( 'ok' => false, 'message' => 'Inserisci Client ID e Client Secret di PayPal.' );
		}
		$live = 'live' === ( $c['paypal_mode'] ?? 'sandbox' );
		$url  = ( $live ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com' ) . '/v1/oauth2/token';
		$r    = $http(
			'POST',
			$url,
			array( 'Authorization' => 'Basic ' . base64_encode( $id . ':' . $sec ), 'Content-Type' => 'application/x-www-form-urlencoded', 'Accept' => 'application/json' ),
			'grant_type=client_credentials'
		);
		$data = json_decode( (string) $r['body'], true );
		if ( 200 === (int) $r['code'] && is_array( $data ) && ! empty( $data['access_token'] ) ) {
			return array( 'ok' => true, 'message' => 'PayPal: connessione riuscita (' . ( $live ? 'modalità reale' : 'sandbox' ) . ').' );
		}
		if ( in_array( (int) $r['code'], array( 400, 401 ), true ) ) {
			return array( 'ok' => false, 'message' => 'PayPal: Client ID o Client Secret non validi per la modalità ' . ( $live ? 'reale' : 'sandbox' ) . '.' );
		}
		return array( 'ok' => false, 'message' => 'PayPal: risposta inattesa (' . (int) $r['code'] . ').' );
	}

	/** Chiamata di rete reale, tramite l'API HTTP di WordPress. */
	public static function wp_http( string $method, string $url, array $headers, ?string $body ): array {
		$res = wp_remote_request( $url, array( 'method' => $method, 'headers' => $headers, 'body' => $body, 'timeout' => 15 ) );
		if ( is_wp_error( $res ) ) {
			throw new \RuntimeException( $res->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		return array( 'code' => (int) wp_remote_retrieve_response_code( $res ), 'body' => (string) wp_remote_retrieve_body( $res ) );
	}
}
