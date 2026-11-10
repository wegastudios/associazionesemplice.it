<?php
namespace Portale;

/**
 * Accesso unico al sito di supporto: il portale conferma chi è la persona (ha già fatto l'accesso qui) e la manda al sito
 * di supporto con un token firmato e a vita brevissima. Il sito di supporto verifica la firma con lo stesso segreto.
 *
 * Token: base64url(payload JSON) . "." . base64url(HMAC-SHA256(payload_base64url, segreto))
 * Payload: { v:1 (v:2 se porta `plans`), iss: base_url del portale, aud: url del sito di supporto, sub: email, role: admin|support|customer,
 *            iat, exp (iat + 60 s), jti: id casuale da usare una sola volta, plans?: [{product, plan, support_level, status}] }
 * Indirizzo: {support.url}/sso?t=<token>
 */
final class SupportSso {

	const TTL = 60;

	public static function supportUrl(): string {
		return rtrim( (string) Config::get( 'support.url', 'https://supporto.associazionesemplice.it' ), '/' );
	}

	public static function enabled(): bool {
		return strlen( (string) Config::get( 'support.sso_secret', '' ) ) >= 32;
	}

	/** Ruolo da dichiarare al sito di supporto: lo staff col suo ruolo, tutti gli altri come clienti. */
	public static function roleFor( ?Db $db, string $email ): string {
		return Staff::role( $db, $email ) ?? 'customer';
	}

	public static function token( string $email, string $role, ?int $now = null, array $extra = array() ): string {
		$now     = $now ?? time();
		$payload = self::b64( (string) json_encode( $extra + array(
			'v'    => 1,
			'iss'  => rtrim( (string) Config::get( 'base_url' ), '/' ),
			'aud'  => self::supportUrl(),
			'sub'  => strtolower( trim( $email ) ),
			'role' => $role,
			'iat'  => $now,
			'exp'  => $now + self::TTL,
			'jti'  => bin2hex( random_bytes( 16 ) ),
		) ) );
		return $payload . '.' . self::b64( hash_hmac( 'sha256', $payload, (string) Config::get( 'support.sso_secret', '' ), true ) );
	}

	/**
	 * Claim facoltativi per precompilare il modulo del supporto: `plan` = «Pro» se ha una licenza a pagamento (o di prova) in regola, «Free» se ha
	 * solo licenze gratuite in regola, assente altrimenti; `name` = nome del cliente se lo conosciamo (max 150 caratteri).
	 */
	public static function claimsFor( ?Db $db, string $email ): array {
		if ( null === $db ) {
			return array();
		}
		$out  = array();
		$cust = $db->one( 'SELECT name FROM customers WHERE email = ?', array( strtolower( trim( $email ) ) ) );
		if ( $cust && '' !== trim( (string) $cust['name'] ) ) {
			$out['name'] = mb_substr( trim( (string) $cust['name'] ), 0, 150 );
		}
		$rows = $db->all( 'SELECT s.* FROM subscriptions s JOIN customers c ON c.id = s.customer_id WHERE c.email = ?', array( strtolower( trim( $email ) ) ) );
		$plan = null;
		foreach ( $rows as $r ) {
			if ( 'Attiva' !== sub_status( $r )[0] ) {
				continue;
			}
			if ( 'free' !== $r['plan_code'] ) {
				return $out + array( 'plan' => 'Pro' );
			}
			$plan = 'Free';
		}
		return null === $plan ? $out : $out + array( 'plan' => $plan );
	}

	/**
	 * Elenco dei piani del cliente per il supporto (accesso unico v2): [{product, plan, support_level, status}], al massimo 20 voci, senza prezzi né dati di soci.
	 * `product` è plugin|saas; `plan` lo slug fisso del piano; `support_level` free|paid|top (dal catalogo del prodotto);
	 * `status` active|trial|past_due|cancelled. Le richieste mai concluse e i rinnovi già registrati non compaiono.
	 */
	public static function plansFor( ?Db $db, string $email ): array {
		if ( null === $db ) {
			return array();
		}
		$out  = array();
		$seen = array();
		$rows = $db->all( 'SELECT s.* FROM subscriptions s JOIN customers c ON c.id = s.customer_id WHERE c.email = ? ORDER BY s.created_at', array( strtolower( trim( $email ) ) ) );
		foreach ( $rows as $r ) {
			$label  = sub_status( $r )[0];
			$status = array( 'Attiva' => 'active', 'Pagamento non riuscito' => 'past_due', 'Scaduta' => 'cancelled', 'Annullata' => 'cancelled' )[ $label ] ?? '';
			if ( '' === $status ) {
				continue;
			}
			if ( 'active' === $status && 'trial' === $r['provider'] ) {
				$status = 'trial';
			}
			$slug  = Site::slug( (string) $r['plan_code'] );
			$entry = array( 'product' => 'plugin', 'plan' => $slug, 'support_level' => self::levelOf( $db, $slug ), 'status' => $status );
			$key   = implode( '|', $entry );
			if ( ! isset( $seen[ $key ] ) && count( $out ) < 20 ) {
				$seen[ $key ] = true;
				$out[]        = $entry;
			}
		}
		return $out;
	}

	/** Indirizzo a cui mandare la persona: con l'accesso unico configurato porta il token, altrimenti la home del supporto. */
	public static function url( ?Db $db, string $email ): string {
		if ( ! self::enabled() ) {
			return self::supportUrl();
		}
		return self::supportUrl() . '/sso?t=' . rawurlencode( self::token( $email, self::roleFor( $db, $email ), null, self::claimsFor( $db, $email ) + self::plansClaims( $db, $email ) ) );
	}

	/** Livello di assistenza del piano dal catalogo del plugin (free|paid|top); piano nascosto o sconosciuto: free per il gratuito, altrimenti paid. */
	private static function levelOf( Db $db, string $slug ): string {
		$plan = ( new Plugin\DbCatalog( $db ) )->plan( $slug );
		$lvl  = is_array( $plan ) ? (string) ( $plan['support_level'] ?? '' ) : '';
		return in_array( $lvl, Plugin\ConfigCatalog::SUPPORT_LEVELS, true ) ? $lvl : ( 'free' === $slug ? 'free' : 'paid' );
	}

	/** Claim dell'accesso unico v2: i piani, con v:2; senza piani il token resta v:1. */
	private static function plansClaims( ?Db $db, string $email ): array {
		$plans = self::plansFor( $db, $email );
		return $plans ? array( 'v' => 2, 'plans' => $plans ) : array();
	}

	private static function b64( string $raw ): string {
		return rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' );
	}
}
