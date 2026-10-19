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

	/** @param array $server contenuto di $_SERVER (parametro per i test) */
	public static function pick( array $server, bool $trust_proxy ): string {
		if ( $trust_proxy ) {
			if ( ! empty( $server['HTTP_CF_CONNECTING_IP'] ) && filter_var( trim( (string) $server['HTTP_CF_CONNECTING_IP'] ), FILTER_VALIDATE_IP ) ) {
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
		return self::pick( $_SERVER, Limits::flag( 'trust_proxy_headers' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- si usano solo indirizzi IP validati
	}
}
