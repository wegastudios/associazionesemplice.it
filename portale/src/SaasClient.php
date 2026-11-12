<?php
namespace Portale;

/**
 * Richieste firmate dal portale al SaaS per l'amministrazione della piattaforma (contratto: saas/docs/CONTRATTO_PORTALE.md §2.3).
 * Il token è un JWT EdDSA con chiave propria del portale verso il SaaS (data/saas-sign.key o `saas_sign_key`), distinta da quella delle licenze.
 * Le letture usano scope «read», le scritture «write»; `act` dice chi ha chiesto (finisce nel registro del SaaS). Vita del token: 60 secondi.
 */
final class SaasClient {

	const AUD = 'associazionesemplice-saas';

	/** Seme della chiave (32 byte) o stringa vuota. */
	private static function seed(): string {
		$raw = (string) Config::get( 'saas_sign_key', '' );
		if ( '' === $raw ) {
			$file = (string) Config::get( 'saas_sign_key_file', dirname( __DIR__ ) . '/data/saas-sign.key' );
			$raw  = is_readable( $file ) ? trim( (string) file_get_contents( $file ) ) : '';
		}
		$seed = '' === $raw ? '' : (string) base64_decode( $raw, true );
		return SODIUM_CRYPTO_SIGN_SEEDBYTES === strlen( $seed ) ? $seed : '';
	}

	public static function enabled(): bool {
		return function_exists( 'sodium_crypto_sign_detached' ) && '' !== self::seed();
	}

	public static function baseUrl(): string {
		return rtrim( (string) Config::get( 'saas_api_url', 'https://app.associazionesemplice.it' ), '/' );
	}

	/** Chiave pubblica da dare al SaaS (`kid=x`), o stringa vuota se manca la chiave. */
	public static function publicKey(): string {
		return PortalAttestation::publicKey( self::seed() );
	}

	public static function kid(): string {
		return PortalAttestation::kid( self::seed() );
	}

	/** Elenco delle associazioni. @param array $filters q, status, page, per_page */
	public static function orgs( string $act, array $filters = array() ): array {
		$extra = array();
		foreach ( array( 'q', 'status' ) as $k ) {
			if ( isset( $filters[ $k ] ) && '' !== trim( (string) $filters[ $k ] ) ) {
				$extra[ $k ] = mb_substr( trim( (string) $filters[ $k ] ), 0, 120 );
			}
		}
		$extra['page']     = max( 1, (int) ( $filters['page'] ?? 1 ) );
		$extra['per_page'] = max( 1, min( 100, (int) ( $filters['per_page'] ?? 50 ) ) );
		return self::call( 'admin/orgs', 'admin_read', 'read', $act, $extra );
	}

	public static function org( string $act, string $orgId ): array {
		return self::call( 'admin/orgs/' . self::id( $orgId ), 'admin_read', 'read', $act );
	}

	public static function operators( string $act, string $orgId ): array {
		return self::call( 'admin/orgs/' . self::id( $orgId ) . '/operators', 'admin_read', 'read', $act );
	}

	/** Disattiva o riattiva un utente (vale in tutte le associazioni in cui è operatore). */
	public static function setUserDisabled( string $act, string $userId, bool $disabled, string $reason = '' ): array {
		return self::call( 'admin/users/' . self::id( $userId ) . '/state', 'admin_write', 'write', $act, array( 'disabled' => $disabled, 'reason' => mb_substr( $reason, 0, 200 ) ) );
	}

	/**
	 * Richiesta firmata del contratto v2 (senza `act` né `scope`). Vita del token 60 secondi, `jti` monouso.
	 * @return array [codice HTTP (0 = rete), corpo decodificato]
	 */
	public static function request( string $method, string $path, string $typ, array $claims, ?int $now = null ): array {
		if ( ! self::enabled() ) {
			throw new UserError( 'Il collegamento con il SaaS non è ancora configurato sul portale (manca la chiave di firma verso il SaaS).' );
		}
		$now   = $now ?? time();
		$token = PortalAttestation::sign( $claims + array(
			'iss' => rtrim( (string) Config::get( 'base_url', 'https://associazionesemplice.it' ), '/' ),
			'aud' => self::AUD,
			'typ' => $typ,
			'iat' => $now,
			'exp' => $now + 60,
			'jti' => bin2hex( random_bytes( 16 ) ),
		), self::seed() );
		$res = Http::send( $method, self::baseUrl() . $path, array( 'Content-Type: application/json', 'Accept: application/json' ), (string) json_encode( array( 'token' => $token ) ) );
		$out = json_decode( $res[1], true );
		return array( $res[0], is_array( $out ) ? $out : array() );
	}

	/** Crea l'associazione (contratto §3.1); ripetibile: lo stesso `ref` risponde 200 con lo stesso `org_id`. */
	public static function provision( string $ref, string $name, string $slug, string $ownerEmail, string $ownerName, ?int $now = null ): array {
		return self::request( 'POST', '/api/portale/v1/orgs', 'provision', array(
			'ref' => $ref, 'org' => array( 'name' => $name, 'slug' => $slug ), 'owner' => array( 'email' => $ownerEmail, 'name' => $ownerName ),
		), $now );
	}

	/** Abbonamento (contratto §3.2): solo il fatto commerciale; i limiti li ricava il SaaS dalla `plan_version`. */
	public static function subscription( string $orgId, array $claims, ?int $now = null ): array {
		return self::request( 'PUT', '/api/portale/v2/orgs/' . self::id( $orgId ) . '/subscription', 'subscription', array( 'sub' => $orgId ) + $claims, $now );
	}

	/** «Vale?» (contratto §3.3): non consuma niente. */
	public static function promoCheck( string $code, string $plan, int $planVersion, string $period, ?string $ref = null, ?int $now = null ): array {
		$c = array( 'code' => mb_substr( $code, 0, 40 ), 'plan' => $plan, 'plan_version' => $planVersion, 'period' => $period );
		if ( null !== $ref && '' !== $ref ) {
			$c['ref'] = $ref;
		}
		return self::request( 'POST', '/api/portale/v2/promo/check', 'promo_check', $c, $now );
	}

	/** Consuma la promo al pagamento riuscito: una volta sola per ordine (idempotente). */
	public static function promoRedeem( string $code, string $orderId, string $ref, ?int $now = null ): array {
		return self::request( 'POST', '/api/portale/v2/promo/redeem', 'promo_redeem', array( 'code' => mb_substr( $code, 0, 40 ), 'order' => $orderId, 'ref' => $ref ), $now );
	}

	/** «Ci sto dentro?» prima di passare a un altro piano (contratto §3.4): solo numeri, nessun dato dei soci. Non cambia niente. */
	public static function planChangeCheck( string $orgId, string $ref, string $toPlan, int $toVersion, ?int $now = null ): array {
		return self::request( 'POST', '/api/portale/v2/orgs/' . self::id( $orgId ) . '/plan-change/check', 'plan_change_check', array( 'ref' => $ref, 'to_plan' => $toPlan, 'to_plan_version' => $toVersion ), $now );
	}

	/** Il dominio è valido e libero? (contratto §3.5) */
	public static function domainCheck( string $orgId, string $ref, string $domain, ?int $now = null ): array {
		return self::request( 'POST', '/api/portale/v2/orgs/' . self::id( $orgId ) . '/domain/check', 'domain_check', array( 'ref' => $ref, 'domain' => mb_substr( $domain, 0, 253 ) ), $now );
	}

	/** Salva il dominio personalizzato (`null` = toglie). Risponde con lo stato e i record DNS da creare. */
	public static function domainSet( string $orgId, string $ref, ?string $domain, ?int $now = null ): array {
		return self::request( 'POST', '/api/portale/v2/orgs/' . self::id( $orgId ) . '/domain/set', 'domain_set', array( 'ref' => $ref, 'domain' => null === $domain ? null : mb_substr( $domain, 0, 253 ) ), $now );
	}

	/** Stato per l'area clienti (contratto §3.6): solo numeri e stato. */
	public static function orgStatus( string $orgId, string $ref, ?int $now = null ): array {
		return self::request( 'POST', '/api/portale/v2/orgs/' . self::id( $orgId ) . '/status', 'org_status', array( 'ref' => $ref ), $now );
	}

	/** Gli identificativi sono UUID: niente altro finisce nell'indirizzo. */
	private static function id( string $id ): string {
		if ( ! preg_match( '/^[A-Za-z0-9-]{8,64}$/', $id ) ) {
			throw new UserError( 'Identificativo non valido.' );
		}
		return $id;
	}

	/** @return array risposta decodificata @throws UserError */
	private static function call( string $path, string $typ, string $scope, string $act, array $extra = array(), ?int $now = null ): array {
		if ( ! self::enabled() ) {
			throw new UserError( 'Il collegamento con il SaaS non è ancora configurato sul portale (manca la chiave di firma verso il SaaS).' );
		}
		$now   = $now ?? time();
		$token = PortalAttestation::sign( $extra + array(
			'iss'   => rtrim( (string) Config::get( 'base_url', 'https://associazionesemplice.it' ), '/' ),
			'aud'   => self::AUD,
			'typ'   => $typ,
			'iat'   => $now,
			'exp'   => $now + 60,
			'jti'   => bin2hex( random_bytes( 16 ) ),
			'act'   => mb_substr( $act, 0, 190 ),
			'scope' => $scope,
		), self::seed() );
		$res = Http::send( 'POST', self::baseUrl() . '/api/portale/v1/' . $path, array( 'Content-Type: application/json', 'Accept: application/json' ), (string) json_encode( array( 'token' => $token ) ) );
		$out = json_decode( $res[1], true );
		if ( 200 === $res[0] && is_array( $out ) ) {
			return $out;
		}
		$code = is_array( $out ) ? (string) ( $out['error'] ?? '' ) : '';
		$msg  = array(
			'invalid_token'  => 'Il SaaS non riconosce la firma del portale: controlla le chiavi.',
			'scope'          => 'Questa operazione richiede il permesso di scrittura.',
			'unknown_org'    => 'Associazione non trovata.',
			'unknown_user'   => 'Utente non trovato.',
			'invalid_claims' => 'Richiesta non valida.',
			'replayed'       => 'Richiesta già eseguita: riprova.',
		);
		if ( 0 === $res[0] ) {
			throw new UserError( 'Il SaaS non risponde: riprova tra poco.' );
		}
		throw new UserError( $msg[ $code ] ?? ( 'Il SaaS ha risposto con un errore (' . $res[0] . ').' ) );
	}
}
