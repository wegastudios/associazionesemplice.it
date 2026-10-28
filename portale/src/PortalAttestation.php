<?php
namespace Portale;

/**
 * Prova firmata del livello della licenza, decisa dal portale: Wega resta la fonte di nome, date e chiave; il livello (gratuito, contabile,
 * Pro Full…) viene dalla tabella dei piani e degli acquisti del portale e lo firma il portale con una sua chiave Ed25519.
 * Il plugin ha la chiave pubblica fissata (kid e `x`): chi controlla la connessione non può inventare un livello.
 *
 * Formato: JWT EdDSA, intestazione {typ, alg: EdDSA, kid}, claim: iss (indirizzo del portale), aud (product_code di Wega), bid (dominio registrato),
 * sub (id licenza su Wega), plan (free | lite | accounting | fiscal), iat, exp (14 giorni). Compare come `portal_attestation` nella risposta di
 * /api/license/validate, solo se la licenza è valida e il portale la conosce.
 *
 * La chiave privata (32 byte, base64) sta in `data/portal-sign.key` (permessi 600, fuori dalla cartella pubblica e dal repository) oppure
 * in `portal_sign_key` di config.php; si crea con `php bin/portal-key.php`, che stampa solo kid e chiave pubblica.
 */
final class PortalAttestation {

	const TTL = 1209600; // 14 giorni

	/** Livello firmato per ogni piano del portale (codice del piano → livello del plugin); si cambia con `plan_levels` in config.php. */
	const LEVELS = array( 'free' => 'free', 'lite' => 'accounting', 'full' => 'fiscal' );

	/** Seme della chiave (32 byte) o stringa vuota se non c'è. */
	private static function seed(): string {
		$raw = (string) Config::get( 'portal_sign_key', '' );
		if ( '' === $raw ) {
			$file = (string) Config::get( 'portal_sign_key_file', dirname( __DIR__ ) . '/data/portal-sign.key' );
			$raw  = is_readable( $file ) ? trim( (string) file_get_contents( $file ) ) : '';
		}
		$seed = '' === $raw ? '' : (string) base64_decode( $raw, true );
		return SODIUM_CRYPTO_SIGN_SEEDBYTES === strlen( $seed ) ? $seed : '';
	}

	public static function enabled(): bool {
		return function_exists( 'sodium_crypto_sign_detached' ) && '' !== self::seed();
	}

	/** Chiave pubblica (32 byte) in base64url: il campo `x` da fissare nel plugin. */
	public static function publicKey( ?string $seed = null ): string {
		$seed = $seed ?? self::seed();
		return '' === $seed ? '' : self::b64( sodium_crypto_sign_publickey( sodium_crypto_sign_seed_keypair( $seed ) ) );
	}

	public static function kid( ?string $seed = null ): string {
		$pub = self::publicKey( $seed );
		return '' === $pub ? '' : 'portale-' . substr( hash( 'sha256', $pub ), 0, 8 );
	}

	/** Livello del plugin per un piano del portale, o null se il piano non è noto (allora non si firma niente). */
	public static function level( string $planCode ): ?string {
		$map = (array) Config::get( 'plan_levels', array() ) + self::LEVELS;
		return isset( $map[ $planCode ] ) && in_array( $map[ $planCode ], array( 'free', 'lite', 'accounting', 'fiscal' ), true ) ? (string) $map[ $planCode ] : null;
	}

	/** JWT firmato con i claim dati. */
	public static function sign( array $claims, ?string $seed = null ): string {
		$seed = $seed ?? self::seed();
		if ( '' === $seed ) {
			throw new \RuntimeException( 'Chiave di firma del portale mancante.' );
		}
		$head = self::b64( (string) json_encode( array( 'typ' => 'JWT', 'alg' => 'EdDSA', 'kid' => self::kid( $seed ) ) ) );
		$body = self::b64( (string) json_encode( $claims, JSON_UNESCAPED_SLASHES ) );
		$sig  = sodium_crypto_sign_detached( $head . '.' . $body, sodium_crypto_sign_secretkey( sodium_crypto_sign_seed_keypair( $seed ) ) );
		return $head . '.' . $body . '.' . self::b64( $sig );
	}

	/**
	 * La prova da accodare alla risposta di Wega, o null: chiave non configurata, licenza non valida per Wega, licenza che il portale non
	 * conosce o annullata, piano senza livello, dominio mancante.
	 *
	 * @param array $wega risposta di Wega a /licenses/validate
	 */
	public static function forResponse( Db $db, array $wega, ?int $now = null ): ?string {
		if ( ! self::enabled() || empty( $wega['valid'] ) || empty( $wega['license_id'] ) || empty( $wega['product_code'] ) ) {
			return null;
		}
		$bid = (string) ( $wega['identity'] ?? $wega['domain'] ?? '' );
		if ( '' === $bid ) {
			return null;
		}
		$sub = $db->one( 'SELECT plan_code, status FROM subscriptions WHERE license_id = ?', array( (string) $wega['license_id'] ) );
		if ( ! $sub || 'canceled' === $sub['status'] ) {
			return null;
		}
		$level = self::level( (string) $sub['plan_code'] );
		if ( null === $level ) {
			return null;
		}
		$now = $now ?? time();
		return self::sign( array(
			'iss'  => rtrim( (string) Config::get( 'base_url', 'https://associazionesemplice.it' ), '/' ),
			'aud'  => (string) $wega['product_code'],
			'bid'  => $bid,
			'sub'  => (string) $wega['license_id'],
			'plan' => $level,
			'iat'  => $now,
			'exp'  => $now + self::TTL,
		) );
	}

	private static function b64( string $raw ): string {
		return rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' );
	}
}
