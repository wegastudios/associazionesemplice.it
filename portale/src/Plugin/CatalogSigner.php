<?php
namespace Portale\Plugin;

use Portale\Config;

/**
 * Firma EdDSA (Ed25519) del catalogo del plugin (ARCHITETTURA_DOMINI §4.1): chi legge il catalogo sa che non è stato alterato.
 * Chiave NUOVA e SEPARATA da quella delle licenze (`portal-sign.key`), del contratto col SaaS e di `admin_hop`; la privata (32 byte, base64)
 * sta fuori dal repository in `data/catalog-sign.key` (permessi 600) o in `catalog_sign_key` di config.php; si crea con `php bin/catalog-key.php`,
 * che stampa solo kid e chiave pubblica.
 *
 * La firma copre i byte ESATTI del corpo JSON e viaggia nell'intestazione
 *   X-Catalog-Signature: alg="EdDSA", kid="catalogo-plugin-xxxxxxxx", sig="<base64url>"
 * Senza chiave configurata il catalogo si serve non firmato (l'ETag resta).
 */
final class CatalogSigner {

	private static function seed(): string {
		$raw = (string) Config::get( 'catalog_sign_key', '' );
		if ( '' === $raw ) {
			$file = (string) Config::get( 'catalog_sign_key_file', dirname( __DIR__, 2 ) . '/data/catalog-sign.key' );
			$raw  = is_readable( $file ) ? trim( (string) file_get_contents( $file ) ) : '';
		}
		$seed = '' === $raw ? '' : (string) base64_decode( $raw, true );
		return SODIUM_CRYPTO_SIGN_SEEDBYTES === strlen( $seed ) ? $seed : '';
	}

	public static function enabled(): bool {
		return function_exists( 'sodium_crypto_sign_detached' ) && '' !== self::seed();
	}

	/** Chiave pubblica (32 byte) in base64url, da fissare in chi legge il catalogo. */
	public static function publicKey( ?string $seed = null ): string {
		$seed = $seed ?? self::seed();
		return '' === $seed ? '' : self::b64( sodium_crypto_sign_publickey( sodium_crypto_sign_seed_keypair( $seed ) ) );
	}

	public static function kid( ?string $seed = null ): string {
		$pub = self::publicKey( $seed );
		return '' === $pub ? '' : 'catalogo-plugin-' . substr( hash( 'sha256', $pub ), 0, 8 );
	}

	/** Valore dell'intestazione per questo corpo, o null se la chiave non è configurata. */
	public static function header( string $body, ?string $seed = null ): ?string {
		$seed = $seed ?? self::seed();
		if ( '' === $seed || ! function_exists( 'sodium_crypto_sign_detached' ) ) {
			return null;
		}
		$sig = sodium_crypto_sign_detached( $body, sodium_crypto_sign_secretkey( sodium_crypto_sign_seed_keypair( $seed ) ) );
		return 'alg="EdDSA", kid="' . self::kid( $seed ) . '", sig="' . self::b64( $sig ) . '"';
	}

	/** Controlla corpo e intestazione con la chiave pubblica fissata (base64url). */
	public static function verify( string $body, string $header, string $publicKeyB64 ): bool {
		if ( 1 !== preg_match( '/sig="([A-Za-z0-9_-]+)"/', $header, $m ) ) {
			return false;
		}
		$sig = base64_decode( strtr( $m[1], '-_', '+/' ), true );
		$pub = base64_decode( strtr( $publicKeyB64, '-_', '+/' ), true );
		if ( false === $sig || false === $pub || SODIUM_CRYPTO_SIGN_BYTES !== strlen( $sig ) || SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES !== strlen( $pub ) ) {
			return false;
		}
		return sodium_crypto_sign_verify_detached( $sig, $body, $pub );
	}

	private static function b64( string $raw ): string {
		return rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' );
	}
}
