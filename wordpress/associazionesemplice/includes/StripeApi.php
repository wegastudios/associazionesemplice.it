<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Stripe Checkout: l'utente paga su una pagina ospitata da Stripe, noi non vediamo mai i dati della carta.
 * Le chiamate di rete sono iniettate ($http) così tutto si prova senza rete.
 *
 * $http: function( string $method, string $url, array $headers, ?string $body ): array{code:int, body:string}
 */
final class StripeApi {

	const BASE = 'https://api.stripe.com';

	/** Parametri per creare la sessione di pagamento (da inviare con http_build_query). */
	public static function checkout_params( array $items, string $public_id, string $payer_email, string $success_url, string $cancel_url, bool $klarna = false ): array {
		$lines = array();
		foreach ( array_values( $items ) as $idx => $i ) {
			$lines[ $idx ] = array(
				'quantity'   => 1,
				'price_data' => array(
					'currency'     => 'eur',
					'unit_amount'  => (int) $i['amount_cents'],
					'product_data' => array( 'name' => PaymentItems::line_name( $i ) ),
				),
			);
		}
		$params = array(
			'mode'                => 'payment',
			'locale'              => 'it',
			'success_url'         => $success_url,
			'cancel_url'          => $cancel_url,
			'client_reference_id' => $public_id,
			'line_items'          => $lines,
			'metadata'            => array( 'asem_payment' => $public_id ),
			'payment_intent_data' => array( 'metadata' => array( 'asem_payment' => $public_id ) ),
		);
		if ( '' !== $payer_email ) {
			$params['customer_email'] = $payer_email;
		}
		if ( $klarna ) { // Klarna va elencato a mano (insieme alla carta); chi lo chiama ha già controllato euro, importo ed email con KlarnaRules
			$params['payment_method_types'] = KlarnaRules::payment_method_types( true );
		}
		return $params;
	}

	private static function call( callable $http, string $method, string $path, string $secret, ?array $form = null, array $extra = array() ): array {
		$headers = array_merge( array( 'Authorization' => 'Bearer ' . $secret ), $extra );
		$body    = null;
		if ( null !== $form ) {
			$headers['Content-Type'] = 'application/x-www-form-urlencoded';
			$body                    = http_build_query( $form, '', '&', PHP_QUERY_RFC3986 );
		}
		$r    = $http( $method, self::BASE . $path, $headers, $body );
		$data = json_decode( (string) $r['body'], true );
		if ( (int) $r['code'] < 200 || (int) $r['code'] >= 300 || ! is_array( $data ) ) {
			$msg = is_array( $data ) && isset( $data['error']['message'] ) ? (string) $data['error']['message'] : 'risposta ' . (int) $r['code'];
			throw new \RuntimeException( 'Stripe: ' . $msg ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		return $data;
	}

	/** @return array ['id' => cs_…, 'url' => pagina di pagamento] */
	public static function create_session( callable $http, string $secret, array $params, string $idempotency_key ): array {
		$d = self::call( $http, 'POST', '/v1/checkout/sessions', $secret, $params, array( 'Idempotency-Key' => $idempotency_key ) );
		if ( empty( $d['id'] ) || empty( $d['url'] ) ) {
			throw new \RuntimeException( 'Stripe: risposta senza indirizzo di pagamento.' );
		}
		return array( 'id' => (string) $d['id'], 'url' => (string) $d['url'] );
	}

	public static function retrieve_session( callable $http, string $secret, string $session_id ): array {
		return self::call( $http, 'GET', '/v1/checkout/sessions/' . rawurlencode( $session_id ), $secret );
	}

	/**
	 * Rimborso (anche parziale) di un pagamento: i soldi tornano sul metodo usato dal socio. L'Idempotency-Key fa sì che lo stesso invio ripetuto
	 * (doppio clic, richiesta rifatta dopo un timeout) non rimborsi due volte. Non lancia eccezioni per le risposte di Stripe: le classifica.
	 *
	 * @return array ['ok' => bool, 'id' => re_…, 'reason' => permission|key|already|not_found|balance|amount|other (se non ok), 'certain' => true se il rifiuto è certo (4xx riconosciuto o rimborso fallito/annullato: nulla è stato restituito), false per 5xx, 429, risposta illeggibile o errore non riconosciuto (il rimborso può essere partito), 'message' => testo del gestore]
	 */
	public static function refund( callable $http, string $secret, string $payment_intent, int $cents, string $idempotency_key, string $public_id ): array {
		$form = array(
			'payment_intent' => $payment_intent,
			'amount'         => $cents,
			'reason'         => 'requested_by_customer',
			'metadata'       => array( 'asem_payment' => $public_id ),
		);
		$r    = $http(
			'POST',
			self::BASE . '/v1/refunds',
			array( 'Authorization' => 'Bearer ' . $secret, 'Content-Type' => 'application/x-www-form-urlencoded', 'Idempotency-Key' => $idempotency_key ),
			http_build_query( $form, '', '&', PHP_QUERY_RFC3986 )
		);
		$code = (int) $r['code'];
		$data = json_decode( (string) $r['body'], true );
		if ( $code >= 200 && $code < 300 && is_array( $data ) && ! empty( $data['id'] ) ) {
			if ( in_array( (string) ( $data['status'] ?? '' ), array( 'failed', 'canceled' ), true ) ) {
				return array( 'ok' => false, 'id' => (string) $data['id'], 'reason' => 'other', 'certain' => true, 'message' => 'rimborso ' . (string) $data['status'] );
			}
			return array( 'ok' => true, 'id' => (string) $data['id'], 'reason' => '', 'message' => '' );
		}
		return array( 'ok' => false, 'id' => '', 'reason' => self::refund_reason( $code, $data ), 'certain' => $code >= 400 && $code < 500 && 429 !== $code && 'other' !== self::refund_reason( $code, $data ), 'message' => is_array( $data ) && isset( $data['error']['message'] ) ? (string) $data['error']['message'] : 'risposta ' . $code );
	}

	/** Perché Stripe ha rifiutato il rimborso. @param mixed $data risposta già decodificata */
	public static function refund_reason( int $code, $data ): string {
		$err = is_array( $data ) && isset( $data['error'] ) && is_array( $data['error'] ) ? $data['error'] : array();
		$ec  = (string) ( $err['code'] ?? '' );
		$msg = strtolower( (string) ( $err['message'] ?? '' ) );
		if ( 'charge_already_refunded' === $ec || false !== strpos( $msg, 'has already been refunded' ) ) {
			return 'already';
		}
		if ( 'balance_insufficient' === $ec || false !== strpos( $msg, 'insufficient funds' ) ) {
			return 'balance';
		}
		if ( 'amount_too_large' === $ec || false !== strpos( $msg, 'greater than unrefunded amount' ) || false !== strpos( $msg, 'greater than the' ) ) {
			return 'amount';
		}
		if ( 'resource_missing' === $ec || 404 === $code ) {
			return 'not_found';
		}
		if ( 403 === $code || 'permission_error' === (string) ( $err['type'] ?? '' ) || false !== strpos( $msg, 'required permissions' ) || false !== strpos( $msg, 'does not have the required' ) ) {
			return 'permission';
		}
		if ( 401 === $code || 'invalid_api_key' === $ec ) {
			return 'key';
		}
		return 'other';
	}

	public static function is_paid( array $session ): bool {
		return 'paid' === ( $session['payment_status'] ?? '' );
	}

	/** Importo incassato in centesimi di euro. Con una valuta diversa dall'euro vale 0: il pagamento va controllato a mano. */
	public static function paid_eur_cents( array $session ): int {
		if ( isset( $session['currency'] ) && 'eur' !== strtolower( (string) $session['currency'] ) ) {
			return 0;
		}
		return max( 0, (int) ( $session['amount_total'] ?? 0 ) );
	}
}
