<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Satispay GBusiness: pagamento su pagina ospitata. Si crea il pagamento firmando la richiesta, si manda la persona all'indirizzo che
 * Satispay restituisce, poi l'esito si conferma con l'avviso (callback) di Satispay e, comunque, rileggendo lo stato del pagamento.
 * Le chiamate di rete sono iniettate ($http) così tutto si prova senza rete.
 *
 * $http: function( string $method, string $url, array $headers, ?string $body ): array{code:int, body:string}
 * $cfg:  satispay_mode (sandbox|live), satispay_key_id, satispay_private_key (PEM, in chiaro)
 */
final class SatispayApi {

	/** Nuova coppia di chiavi RSA. @return array{private:string,public:string} @throws \RuntimeException */
	public static function generate_keys(): array {
		if ( ! function_exists( 'openssl_pkey_new' ) ) {
			throw new \RuntimeException( 'Satispay: sul server manca l\'estensione OpenSSL di PHP.' );
		}
		$res = openssl_pkey_new( array( 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA ) );
		if ( false === $res || ! openssl_pkey_export( $res, $private ) ) {
			throw new \RuntimeException( 'Satispay: non è stato possibile creare la chiave di firma.' );
		}
		$details = openssl_pkey_get_details( $res );
		return array( 'private' => (string) $private, 'public' => (string) ( $details['key'] ?? '' ) );
	}

	/** Intestazioni di una richiesta firmata. @throws \RuntimeException */
	public static function signed_headers( string $method, string $path, string $body, array $cfg, int $now ): array {
		$base   = SatispayRules::base( (string) ( $cfg['satispay_mode'] ?? 'sandbox' ) );
		$host   = SatispayRules::host( $base );
		$date   = SatispayRules::http_date( $now );
		$digest = SatispayRules::digest( $body );
		$string = SatispayRules::signing_string( $method, $path, $host, $date, $digest );
		$key    = function_exists( 'openssl_pkey_get_private' ) ? openssl_pkey_get_private( (string) ( $cfg['satispay_private_key'] ?? '' ) ) : false;
		if ( false === $key || ! openssl_sign( $string, $signature, $key, OPENSSL_ALGO_SHA256 ) ) {
			throw new \RuntimeException( 'Satispay: la chiave di firma non è valida. Ricollega Satispay.' );
		}
		return array(
			'Host'          => $host,
			'Date'          => $date,
			'Digest'        => $digest,
			'Authorization' => SatispayRules::authorization( (string) ( $cfg['satispay_key_id'] ?? '' ), base64_encode( $signature ) ),
			'Accept'        => 'application/json',
		);
	}

	private static function call( callable $http, array $cfg, string $method, string $path, ?array $json = null, ?int $now = null ): array {
		$body    = null === $json ? '' : (string) json_encode( $json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		$headers = self::signed_headers( $method, $path, $body, $cfg, $now ?? time() );
		if ( null !== $json ) {
			$headers['Content-Type'] = 'application/json';
		}
		$r    = $http( $method, SatispayRules::base( (string) ( $cfg['satispay_mode'] ?? 'sandbox' ) ) . $path, $headers, '' === $body ? null : $body );
		return self::parse( $r );
	}

	/** @throws \RuntimeException */
	private static function parse( array $r ): array {
		$data = json_decode( (string) $r['body'], true );
		if ( (int) $r['code'] < 200 || (int) $r['code'] >= 300 || ! is_array( $data ) ) {
			$msg = is_array( $data ) && isset( $data['message'] ) ? (string) $data['message'] : 'risposta ' . (int) $r['code'];
			throw new \RuntimeException( 'Satispay: ' . $msg ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		return $data;
	}

	/**
	 * Collega il sito a Satispay: registra la chiave pubblica con il codice di attivazione (monouso) e restituisce l'identificativo della chiave.
	 *
	 * @throws \RuntimeException
	 */
	public static function register_key( callable $http, string $mode, string $token, string $public_pem ): string {
		$body = (string) json_encode( array( 'public_key' => $public_pem, 'token' => $token ), JSON_UNESCAPED_SLASHES );
		$r    = $http( 'POST', SatispayRules::base( $mode ) . '/g_business/v1/authentication_keys', array( 'Content-Type' => 'application/json', 'Accept' => 'application/json' ), $body );
		$d    = self::parse( $r );
		if ( empty( $d['key_id'] ) ) {
			throw new \RuntimeException( 'Satispay: risposta senza identificativo della chiave.' );
		}
		return (string) $d['key_id'];
	}

	/** @return array ['id' => identificativo del pagamento, 'url' => pagina di pagamento] @throws \RuntimeException */
	public static function create_payment( callable $http, array $cfg, array $payload ): array {
		$d = self::call( $http, $cfg, 'POST', '/g_business/v1/payments', $payload );
		if ( empty( $d['id'] ) || empty( $d['redirect_url'] ) ) {
			throw new \RuntimeException( 'Satispay: risposta senza indirizzo di pagamento.' );
		}
		$url = (string) $d['redirect_url'];
		if ( 0 !== strpos( $url, 'https://' ) ) {
			throw new \RuntimeException( 'Satispay: indirizzo di pagamento non sicuro.' );
		}
		return array( 'id' => SatispayRules::clean_id( (string) $d['id'] ), 'url' => $url );
	}

	/** @throws \RuntimeException */
	public static function retrieve_payment( callable $http, array $cfg, string $payment_id ): array {
		return self::call( $http, $cfg, 'GET', '/g_business/v1/payments/' . rawurlencode( $payment_id ) );
	}

	/** Prova della firma: Satispay ha un punto apposta, che non muove denaro. @throws \RuntimeException */
	public static function test_signature( callable $http, array $cfg ): array {
		return self::call( $http, $cfg, 'POST', '/wally-services/protocol/tests/signature', array( 'ping' => 'associazione-semplice' ) );
	}
}
