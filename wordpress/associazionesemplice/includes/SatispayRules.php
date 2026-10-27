<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Satispay (GBusiness API): regole pure, senza rete e senza WordPress. Le richieste si firmano con una chiave RSA (scheda «HTTP Signature»):
 * si firma una stringa fatta di metodo e percorso, host, data e impronta SHA-256 del corpo.
 */
final class SatispayRules {

	const BASE_LIVE    = 'https://authservices.satispay.com';
	const BASE_SANDBOX = 'https://staging.authservices.satispay.com';

	public static function base( string $mode ): string {
		return 'live' === $mode ? self::BASE_LIVE : self::BASE_SANDBOX;
	}

	public static function host( string $base ): string {
		return (string) parse_url( $base, PHP_URL_HOST );
	}

	/** Intestazione Digest: impronta SHA-256 del corpo (anche vuoto). */
	public static function digest( string $body ): string {
		return 'SHA-256=' . base64_encode( hash( 'sha256', $body, true ) );
	}

	/** Data della richiesta nel formato delle intestazioni HTTP. */
	public static function http_date( int $timestamp ): string {
		return gmdate( 'D, d M Y H:i:s', $timestamp ) . ' GMT';
	}

	/** Stringa che si firma. */
	public static function signing_string( string $method, string $path, string $host, string $date, string $digest ): string {
		return '(request-target): ' . strtolower( $method ) . ' ' . $path . "\n"
			. 'host: ' . $host . "\n"
			. 'date: ' . $date . "\n"
			. 'digest: ' . $digest;
	}

	/** Intestazione Authorization con la firma già calcolata (base64). */
	public static function authorization( string $key_id, string $signature_b64 ): string {
		return 'Signature keyId="' . $key_id . '", algorithm="rsa-sha256", headers="(request-target) host date digest", signature="' . $signature_b64 . '"';
	}

	/** Indirizzo a cui Satispay avvisa dell'esito: {uuid} è sostituito da Satispay con l'identificativo del pagamento. */
	public static function callback_url( string $base ): string {
		return $base . ( false === strpos( $base, '?' ) ? '?' : '&' ) . 'payment_id={uuid}';
	}

	/** Corpo JSON per creare il pagamento (importo in centesimi di euro). */
	public static function payment_payload( array $items, string $public_id, string $callback_url, string $redirect_url, string $brand ): array {
		return array(
			'flow'          => 'MATCH_CODE',
			'amount_unit'   => PaymentItems::total( $items ),
			'currency'      => 'EUR',
			'external_code' => $public_id,
			'callback_url'  => $callback_url,
			'redirect_url'  => $redirect_url,
			'description'   => PaymentItems::summary( $items, $brand ),
			'metadata'      => array( 'asem_payment' => $public_id ),
		);
	}

	public static function is_accepted( array $payment ): bool {
		return 'ACCEPTED' === strtoupper( (string) ( $payment['status'] ?? '' ) );
	}

	public static function is_failed( array $payment ): bool {
		return in_array( strtoupper( (string) ( $payment['status'] ?? '' ) ), array( 'CANCELED', 'CANCELLED', 'EXPIRED' ), true );
	}

	/** Importo incassato in centesimi di euro; con una valuta diversa dall'euro vale 0 (il pagamento va controllato a mano). */
	public static function paid_eur_cents( array $payment ): int {
		if ( isset( $payment['currency'] ) && 'EUR' !== strtoupper( (string) $payment['currency'] ) ) {
			return 0;
		}
		return max( 0, (int) ( $payment['amount_unit'] ?? 0 ) );
	}

	/** Identificativo scelto da Satispay per un pagamento: solo lettere, cifre e trattini (viene usato in un indirizzo). */
	public static function clean_id( string $id ): string {
		return (string) preg_replace( '/[^A-Za-z0-9_\-]/', '', $id );
	}
}
