<?php
namespace Portale\Plugin;

use Portale\Config;
use Portale\Db;
use Portale\Releases;
use Portale\Settings;
use Portale\UserError;

/**
 * Collegamento di scarico firmato, legato a una licenza e valido 5 minuti (`download.link`).
 * A licenza in regola si scarica qualunque versione conservata; a licenza scaduta solo l'ultima versione uscita PRIMA della scadenza.
 */
final class DownloadLink {

	const PATH = '/wordpress/scarica';
	const TTL  = 300;

	/** Prodotti che una licenza dà diritto di scaricare (il Pro solo con un piano diverso dal gratuito). */
	public static function products( array $sub ): array {
		return 'free' === $sub['plan_code'] ? array( 'free' ) : array( 'free', 'pro' );
	}

	/** Riga della versione se è scaricabile con questa licenza adesso, altrimenti null. */
	public static function allowed( Db $db, array $sub, string $product, string $version, ?int $now = null ): ?array {
		$now = $now ?? time();
		if ( ! isset( Releases::PRODUCTS[ $product ] ) || ! in_array( $product, self::products( $sub ), true ) ) {
			return null;
		}
		$rel = Releases::get( $db, $product, $version );
		if ( ! $rel || in_array( $sub['status'], array( 'pending', 'canceled', 'merged' ), true ) ) {
			return null;
		}
		$exp = ! empty( $sub['expires_at'] ) ? strtotime( $sub['expires_at'] . ' UTC' ) : 0;
		if ( 'past_due' !== $sub['status'] && 0 !== $exp && $exp >= $now ) {
			return $rel; // in regola
		}
		// Scaduta (o pagamento non riuscito): solo l'ultima versione precedente alla scadenza.
		$limit = 0 === $exp ? $now : min( $exp, $now );
		foreach ( Releases::all( $db, $product ) as $r ) { // dalla più nuova
			if ( strtotime( $r['created_at'] . ' UTC' ) <= $limit ) {
				return $r['version'] === $rel['version'] ? $rel : null;
			}
		}
		return null;
	}

	/** @throws UserError */
	public static function create( Db $db, array $sub, string $product, string $version, ?int $now = null ): string {
		if ( null === self::allowed( $db, $sub, $product, $version, $now ) ) {
			throw new UserError( 'Questa versione non è disponibile con la tua licenza.' );
		}
		$payload = self::b64( (string) json_encode( array( 's' => $sub['id'], 'p' => $product, 'v' => $version, 'e' => ( $now ?? time() ) + self::TTL ) ) );
		return rtrim( (string) Config::get( 'base_url', '' ), '/' ) . self::PATH . '?t=' . $payload . '.' . self::b64( hash_hmac( 'sha256', $payload, self::key(), true ) );
	}

	/**
	 * Verifica il token, ricontrolla il diritto sulla licenza e registra lo scarico.
	 * @return array [codice HTTP, file o messaggio, prodotto, versione]
	 */
	public static function resolve( Db $db, string $token, ?int $now = null ): array {
		$now   = $now ?? time();
		$parts = explode( '.', $token );
		if ( 2 !== count( $parts ) || '' === (string) Config::get( 'app_key', '' ) || ! hash_equals( self::b64( hash_hmac( 'sha256', $parts[0], self::key(), true ) ), $parts[1] ) ) {
			return array( 403, 'Link non valido.', '', '' );
		}
		$c = json_decode( (string) base64_decode( strtr( $parts[0], '-_', '+/' ) ), true );
		if ( ! is_array( $c ) || (int) ( $c['e'] ?? 0 ) < $now ) {
			return array( 410, 'Il link è scaduto: generane uno nuovo dalla tua area clienti.', '', '' );
		}
		$sub = $db->one( 'SELECT * FROM subscriptions WHERE id = ?', array( (string) ( $c['s'] ?? '' ) ) );
		$p   = (string) ( $c['p'] ?? '' );
		$v   = (string) ( $c['v'] ?? '' );
		if ( ! $sub || ! Releases::validVersion( $v ) || null === self::allowed( $db, $sub, $p, $v, $now ) ) {
			return array( 404, 'Versione non disponibile.', '', '' );
		}
		$file = Releases::dir( $p ) . '/' . $v . '.zip';
		if ( ! is_file( $file ) ) {
			return array( 404, 'Il file di questa versione non è più sul server.', '', '' );
		}
		if ( Releases::recentDownloads( $db, (string) $sub['customer_id'], $now ) >= Settings::limit( 'download_day' ) ) {
			return array( 429, 'Hai raggiunto il limite di scarichi di oggi: riprova domani.', '', '' );
		}
		$db->run( 'INSERT INTO downloads (id, customer_id, at, product, version) VALUES (?,?,?,?,?)', array( Db::id(), $sub['customer_id'], gmdate( 'Y-m-d H:i:s', $now ), $p, $v ) );
		return array( 200, $file, $p, $v );
	}

	private static function key(): string {
		return 'plugin-download|' . (string) Config::get( 'app_key', '' );
	}

	private static function b64( string $raw ): string {
		return rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' );
	}
}
