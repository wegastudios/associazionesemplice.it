<?php
namespace Portale;

/**
 * Aggiornamento diretto dei plugin da WordPress: il plugin chiede al portale se c'è una versione più nuova e, se sì, riceve un indirizzo di
 * scarico firmato e a vita breve (nessun link pubblico fisso). Il free non richiede la chiave; il Pro richiede una licenza di un piano Pro in regola.
 *
 *  POST /api/plugin/update  {product, version (installata), license_key?, domain?}
 *       → {product, version (ultima), update: bool, package: url firmato|null, url: pagina di accesso}
 *  GET  /api/plugin/package?t=<token>  → lo zip (token = base64url(payload).HMAC, valido «update_link_ttl» secondi)
 */
final class PluginUpdates {

	/** @return array [codice HTTP, corpo JSON, intestazioni] */
	public static function check( Db $db, LicenseProxy $proxy, string $raw, string $ip, ?int $now = null ): array {
		$now = $now ?? time();
		$in  = json_decode( $raw, true );
		$p   = is_array( $in ) && isset( $in['product'] ) && is_string( $in['product'] ) ? $in['product'] : '';
		if ( ! isset( Releases::PRODUCTS[ $p ] ) ) {
			return self::error( 422, 'INVALID_PRODUCT', 'Prodotto non valido.' );
		}
		if ( ! $proxy->hit( 'update', $ip, $now ) ) {
			return self::error( 429, 'RATE_LIMITED', 'Troppe richieste: riprova più tardi.', array( 'Retry-After: 3600' ) );
		}
		$cur = isset( $in['version'] ) && is_string( $in['version'] ) && Releases::validVersion( $in['version'] ) ? $in['version'] : '';
		// Si offrono solo versioni firmate: il plugin controlla impronta e firma prima di installare.
		$all = array_values( array_filter( Releases::all( $db, $p ), function ( $r ) {
			return ! empty( $r['signature'] ) && ! empty( $r['kid'] );
		} ) );
		if ( ! $all ) {
			return self::error( 404, 'NO_RELEASE', 'Nessuna versione firmata pubblicata.' );
		}
		if ( 'pro' === $p ) {
			$key = isset( $in['license_key'] ) && is_string( $in['license_key'] ) ? $in['license_key'] : '';
			$dom = isset( $in['domain'] ) && is_string( $in['domain'] ) ? $in['domain'] : '';
			list( $st, $body ) = $proxy->validate( (string) json_encode( array( 'license_key' => $key, 'domain' => $dom ) ), $ip, $now );
			$j = json_decode( $body, true );
			if ( 200 !== $st ) {
				return array( $st, $body, array() );
			}
			if ( empty( $j['valid'] ) || false !== stripos( (string) ( $j['plan_name'] ?? 'free' ), 'free' ) ) {
				return self::error( 403, 'LICENSE_REQUIRED', 'Per aggiornare il Pro serve una licenza di un piano Pro in regola per questo dominio.' );
			}
		}
		$latest = $all[0]['version'];
		$newer  = '' === $cur || version_compare( $latest, $cur, '>' );
		$out    = array(
			'product' => $p, 'version' => $latest, 'update' => $newer,
			'package' => $newer ? self::packageUrl( $p, $latest, $now ) : null,
			'sha256'  => $newer ? $all[0]['sha256'] : null, 'signature' => $newer ? $all[0]['signature'] : null, 'kid' => $newer ? $all[0]['kid'] : null,
			'url'     => rtrim( (string) Config::get( 'base_url', 'https://associazionesemplice.it' ), '/' ) . '/accedi',
		);
		if ( $newer ) {
			$log = Releases::changelog( $p, $latest, $cur );
			if ( '' !== $log ) {
				$out['changelog'] = $log;
			}
		}
		return array( 200, (string) json_encode( $out, JSON_UNESCAPED_SLASHES ), array( 'Cache-Control: no-store' ) );
	}

	public static function packageUrl( string $product, string $version, ?int $now = null ): string {
		$now     = $now ?? time();
		$payload = self::b64( (string) json_encode( array( 'p' => $product, 'v' => $version, 'e' => $now + Settings::limit( 'update_link_ttl' ) ) ) );
		return rtrim( (string) Config::get( 'base_url', 'https://associazionesemplice.it' ), '/' ) . '/api/plugin/package?t=' . $payload . '.' . self::b64( hash_hmac( 'sha256', $payload, self::key(), true ) );
	}

	/**
	 * Verifica il token e registra lo scarico. @return array [codice, percorso del file o messaggio di errore, prodotto, versione]
	 */
	public static function package( Db $db, string $token, ?int $now = null ): array {
		$now   = $now ?? time();
		$parts = explode( '.', $token );
		if ( 2 !== count( $parts ) || ! hash_equals( self::b64( hash_hmac( 'sha256', $parts[0], self::key(), true ) ), $parts[1] ) ) {
			return array( 403, 'Link non valido.', '', '' );
		}
		$c = json_decode( (string) base64_decode( strtr( $parts[0], '-_', '+/' ) ), true );
		if ( ! is_array( $c ) || (int) ( $c['e'] ?? 0 ) < $now ) {
			return array( 410, 'Il link è scaduto: riprova ad aggiornare.', '', '' );
		}
		$p = (string) ( $c['p'] ?? '' );
		$v = (string) ( $c['v'] ?? '' );
		if ( ! isset( Releases::PRODUCTS[ $p ] ) || ! Releases::validVersion( $v ) || ! Releases::get( $db, $p, $v ) || ! is_file( Releases::dir( $p ) . '/' . $v . '.zip' ) ) {
			return array( 404, 'Versione non disponibile.', '', '' );
		}
		$db->run( 'INSERT INTO downloads (id, customer_id, at, product, version) VALUES (?,?,?,?,?)', array( Db::id(), 'aggiornamento', gmdate( 'Y-m-d H:i:s', $now ), $p, $v ) );
		return array( 200, Releases::dir( $p ) . '/' . $v . '.zip', $p, $v );
	}

	private static function key(): string {
		return 'plugin-update|' . (string) Config::get( 'app_key', '' );
	}

	private static function b64( string $raw ): string {
		return rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' );
	}

	private static function error( int $status, string $code, string $message, array $headers = array() ): array {
		return array( $status, (string) json_encode( array( 'error' => array( 'code' => $code, 'message' => $message ) ) ), $headers );
	}
}
