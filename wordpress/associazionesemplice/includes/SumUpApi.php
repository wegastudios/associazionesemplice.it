<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * SumUp: pagina di pagamento ospitata da SumUp («hosted checkout»), così i dati della carta non passano dal sito.
 * Si crea un pagamento (checkout) con la chiave API e il codice commerciante, si manda la persona sull'indirizzo che SumUp restituisce,
 * poi si controlla lo stato: PAID = incassato. Le chiamate di rete sono iniettate ($http) così tutto si prova senza rete.
 *
 * $http: function( string $method, string $url, array $headers, ?string $body ): array{code:int, body:string}
 */
final class SumUpApi {

	const BASE = 'https://api.sumup.com';

	/** Corpo JSON per creare il pagamento. */
	public static function checkout_payload( array $items, string $public_id, string $merchant_code, string $redirect_url, string $brand ): array {
		$total = PaymentItems::total( $items );
		return array(
			'checkout_reference' => $public_id,
			'amount'             => (float) PaymentItems::decimal( $total ),
			'currency'           => 'EUR',
			'merchant_code'      => $merchant_code,
			'description'        => PaymentItems::summary( $items, $brand ),
			'redirect_url'       => $redirect_url,
			'hosted_checkout'    => array( 'enabled' => true ),
		);
	}

	private static function call( callable $http, string $method, string $path, string $key, ?array $json = null ): array {
		$headers = array( 'Authorization' => 'Bearer ' . $key, 'Accept' => 'application/json' );
		$body    = null;
		if ( null !== $json ) {
			$headers['Content-Type'] = 'application/json';
			$body                    = (string) json_encode( $json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); // senza dipendere da WordPress (così la classe si prova anche senza)
		}
		$r    = $http( $method, self::BASE . $path, $headers, $body );
		$data = json_decode( (string) $r['body'], true );
		if ( (int) $r['code'] < 200 || (int) $r['code'] >= 300 || ! is_array( $data ) ) {
			$msg = is_array( $data ) && isset( $data['message'] ) ? (string) $data['message'] : ( is_array( $data ) && isset( $data['error_message'] ) ? (string) $data['error_message'] : 'risposta ' . (int) $r['code'] );
			throw new \RuntimeException( 'SumUp: ' . $msg ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		return $data;
	}

	/** @return array ['id' => identificativo del pagamento, 'url' => pagina di pagamento] */
	public static function create_checkout( callable $http, string $key, array $payload ): array {
		$d = self::call( $http, 'POST', '/v0.1/checkouts', $key, $payload );
		if ( empty( $d['id'] ) || empty( $d['hosted_checkout_url'] ) ) {
			throw new \RuntimeException( 'SumUp: risposta senza indirizzo di pagamento.' );
		}
		$url = (string) $d['hosted_checkout_url'];
		if ( 0 !== strpos( $url, 'https://' ) ) {
			throw new \RuntimeException( 'SumUp: indirizzo di pagamento non sicuro.' );
		}
		return array( 'id' => (string) $d['id'], 'url' => $url );
	}

	public static function retrieve_checkout( callable $http, string $key, string $checkout_id ): array {
		return self::call( $http, 'GET', '/v0.1/checkouts/' . rawurlencode( $checkout_id ), $key );
	}

	public static function is_paid( array $checkout ): bool {
		return 'PAID' === strtoupper( (string) ( $checkout['status'] ?? '' ) );
	}

	public static function is_failed( array $checkout ): bool {
		return in_array( strtoupper( (string) ( $checkout['status'] ?? '' ) ), array( 'FAILED', 'EXPIRED' ), true );
	}

	/** Importo incassato in centesimi di euro; con una valuta diversa dall'euro vale 0 (il pagamento va controllato a mano). */
	public static function paid_eur_cents( array $checkout ): int {
		if ( isset( $checkout['currency'] ) && 'EUR' !== strtoupper( (string) $checkout['currency'] ) ) {
			return 0;
		}
		return max( 0, (int) round( (float) ( $checkout['amount'] ?? 0 ) * 100 ) );
	}

	/** Identificativo dell'operazione (transazione) per il registro: il primo della lista, se c'è. */
	public static function transaction_id( array $checkout ): string {
		$t = $checkout['transactions'][0] ?? array();
		return (string) ( $t['transaction_code'] ?? $t['id'] ?? ( $checkout['id'] ?? '' ) );
	}
}
