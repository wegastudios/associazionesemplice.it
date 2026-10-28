<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Indirizzo del visitatore, per i limiti «per connessione» (primo accesso, attivazione). Di norma è quello della connessione (`REMOTE_ADDR`).
 * Dietro un proxy o una rete di distribuzione (Cloudflare, bilanciatore) quell'indirizzo è sempre lo stesso per tutti e un solo visitatore
 * esaurirebbe il limite di tutti: con l'opzione `trust_proxy_headers` (Limiti e soglie) si legge quello inoltrato dal proxy. Va accesa solo
 * se il sito è davvero dietro un proxy: altrimenti chiunque potrebbe scrivere un indirizzo finto per aggirare i limiti.
 */
final class ClientIp {

	/** Indirizzi pubblicati da Cloudflare (cloudflare.com/ips): solo da qui l'intestazione `CF-Connecting-IP` è affidabile. */
	const CLOUDFLARE = array(
		'173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
		'197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13', '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
		'2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
	);

	/** L'indirizzo è dentro la rete data (CIDR, IPv4 o IPv6)? */
	public static function in_range( string $ip, string $cidr ): bool {
		list( $net, $bits ) = explode( '/', $cidr ) + array( '', '0' );
		$a = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$b = @inet_pton( $net ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( false === $a || false === $b || strlen( $a ) !== strlen( $b ) ) {
			return false;
		}
		$bits  = (int) $bits;
		$bytes = intdiv( $bits, 8 );
		if ( substr( $a, 0, $bytes ) !== substr( $b, 0, $bytes ) ) {
			return false;
		}
		$rest = $bits % 8;
		if ( 0 === $rest ) {
			return true;
		}
		$mask = ( 0xFF << ( 8 - $rest ) ) & 0xFF;
		return ( ord( $a[ $bytes ] ) & $mask ) === ( ord( $b[ $bytes ] ) & $mask );
	}

	/** La connessione arriva da Cloudflare (o da un tunnel sullo stesso server)? Allora l'intestazione di Cloudflare non l'ha scritta il visitatore. */
	private static function from_cloudflare( string $remote ): bool {
		if ( ! filter_var( $remote, FILTER_VALIDATE_IP ) ) {
			return false;
		}
		if ( '127.0.0.1' === $remote || '::1' === $remote ) {
			return true;
		}
		foreach ( self::CLOUDFLARE as $cidr ) {
			if ( self::in_range( $remote, $cidr ) ) {
				return true;
			}
		}
		return false;
	}

	/** @param array $server contenuto di $_SERVER (parametro per i test) */
	public static function pick( array $server, bool $trust_proxy ): string {
		if ( $trust_proxy ) {
			if ( ! empty( $server['HTTP_CF_CONNECTING_IP'] ) && self::from_cloudflare( trim( (string) ( $server['REMOTE_ADDR'] ?? '' ) ) ) && filter_var( trim( (string) $server['HTTP_CF_CONNECTING_IP'] ), FILTER_VALIDATE_IP ) ) {
				return trim( (string) $server['HTTP_CF_CONNECTING_IP'] );
			}
			if ( ! empty( $server['HTTP_X_FORWARDED_FOR'] ) ) {
				// L'ultimo indirizzo è quello aggiunto dal proxy del sito; i precedenti li può scrivere chiunque.
				$parts = array_reverse( array_map( 'trim', explode( ',', (string) $server['HTTP_X_FORWARDED_FOR'] ) ) );
				foreach ( $parts as $p ) {
					if ( filter_var( $p, FILTER_VALIDATE_IP ) ) {
						return $p;
					}
				}
			}
		}
		$remote = trim( (string) ( $server['REMOTE_ADDR'] ?? '' ) );
		return filter_var( $remote, FILTER_VALIDATE_IP ) ? $remote : '';
	}

	public static function get(): string {
		$server = array();
		foreach ( array( 'REMOTE_ADDR', 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR' ) as $k ) { // solo le intestazioni che servono, ripulite; pick() poi accetta solo indirizzi IP validi
			if ( isset( $_SERVER[ $k ] ) ) {
				$server[ $k ] = sanitize_text_field( wp_unslash( $_SERVER[ $k ] ) );
			}
		}
		return self::pick( $server, Limits::flag( 'trust_proxy_headers' ) );
	}
}
