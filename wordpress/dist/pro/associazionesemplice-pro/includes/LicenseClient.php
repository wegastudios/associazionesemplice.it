<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Client delle licenze. Il plugin non parla direttamente col server Wega: controlla la chiave col portale di Associazione Semplice
 * (`POST https://associazionesemplice.it/api/license/validate`), che la verifica con Wega e restituisce la risposta di Wega invariata, con
 * l'attestazione firmata. Nessuna credenziale nel plugin. La licenza vale per il dominio registrato e per tutti i suoi sottodomini e
 * sottocartelle (siti di prova compresi). Sui siti locali la verifica non c'è e resta lo stato «standby» (tutto consentito).
 *
 * Le parti pure (domini, livelli, interpretazione della risposta, firma dell'attestazione) non usano WordPress e si provano con PHPUnit.
 */
final class LicenseClient {

	const API    = 'https://associazionesemplice.it/api/license';
	/** Emittente dell'attestazione: resta quello di Wega, che firma; il portale non firma niente. */
	const ISSUER = 'https://license.wegastudios.com';

	/**
	 * Chiavi pubbliche con cui Wega firma le attestazioni (kid => chiave Ed25519, base64url). Sono scritte nel plugin e non si leggono dal portale:
	 * chi controlla il portale (o la connessione) non può farsi riconoscere un piano. Per cambiare chiave serve una nuova versione del plugin, che
	 * può già portare quella successiva accanto alla corrente.
	 */
	const PINNED_KEYS = array(
		'k20261015-4304ed' => '1J6fs2yrHCTTa5w1Mt1JnuDwO7zkjyRiiERm3gESdsQ',
	);

	/** Solo per i test (con ASEM_TESTING): forza «sito locale» sì/no. @var bool|null */
	private static $local_override = null;

	public static function api(): string {
		return rtrim( self::API, '/' );
	}

	/** Sposta il sito tra locale e non locale nei test. Senza la costante ASEM_TESTING non fa niente. */
	public static function set_local_override( ?bool $v ): void {
		if ( defined( 'ASEM_TESTING' ) && ASEM_TESTING ) {
			self::$local_override = $v;
		}
	}

	/** Sito di sviluppo (localhost, *.local, *.test, indirizzi IP): non richiede licenza e non occupa un dominio. */
	public static function is_local(): bool {
		return null !== self::$local_override ? self::$local_override : LicenseRules::is_local( home_url() );
	}

	/** La verifica con il portale è attiva: il sito non è locale. */
	public static function enabled(): bool {
		return ! self::is_local();
	}

	/**
	 * Dominio nel formato richiesto dal server: minuscolo, punycode, senza schema, porta, percorso e «www.». '' se non utilizzabile.
	 */
	public static function normalize_domain( string $url_or_host ): string {
		$h = LicenseRules::host( $url_or_host );
		if ( 0 === strpos( $h, 'www.' ) ) {
			$h = substr( $h, 4 );
		}
		if ( '' === $h || false === strpos( $h, '.' ) || filter_var( $h, FILTER_VALIDATE_IP ) || false !== strpos( $h, '*' ) ) {
			return '';
		}
		if ( preg_match( '/[^\x00-\x7f]/', $h ) ) {
			if ( ! function_exists( 'idn_to_ascii' ) ) {
				return '';
			}
			$h = (string) idn_to_ascii( $h, 0, defined( 'INTL_IDNA_VARIANT_UTS46' ) ? INTL_IDNA_VARIANT_UTS46 : 0 );
		}
		return (bool) preg_match( '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $h ) ? $h : '';
	}

	/**
	 * Livello del piano dal nome che il server restituisce (`plan_name`). Il nome si sceglie nel pannello del portale: deve contenere
	 * «Free», «Lite» o «Full» (o i vecchi «Fiscale» e «contabile»). Un piano a pagamento non riconosciuto vale come Lite.
	 */
	public static function plan_from_name( string $name ): string {
		$n = strtolower( trim( $name ) );
		if ( '' === $n || false !== strpos( $n, 'free' ) || false !== strpos( $n, 'gratuit' ) ) {
			return License::PLAN_FREE;
		}
		if ( false !== strpos( $n, 'full' ) || false !== strpos( $n, 'fiscal' ) ) {
			return License::PLAN_FISCAL;
		}
		if ( false !== strpos( $n, 'contab' ) || false !== strpos( $n, 'accounting' ) ) {
			return License::PLAN_ACCOUNTING;
		}
		return License::PLAN_LITE;
	}

	/**
	 * Risposta di `validate` → stato del plugin.
	 *
	 * @return array ['status', 'since' (Y-m-d|null), 'plan', 'reason', 'expires_at', 'in_grace', 'next_check_at']
	 */
	public static function interpret( array $r, ?int $now = null ): array {
		$now    = $now ?? time();
		$reason = strtoupper( (string) ( $r['reason_code'] ?? '' ) );
		$exp    = isset( $r['expires_at'] ) ? strtotime( (string) $r['expires_at'] ) : false;
		$out    = array(
			'plan'          => self::plan_from_name( (string) ( $r['plan_name'] ?? '' ) ),
			'reason'        => $reason,
			'expires_at'    => false === $exp ? null : gmdate( 'Y-m-d', $exp ),
			'in_grace'      => 'IN_GRACE' === $reason,
			'next_check_at' => isset( $r['next_check_at'] ) && false !== strtotime( (string) $r['next_check_at'] ) ? gmdate( 'Y-m-d H:i:s', strtotime( (string) $r['next_check_at'] ) ) : null,
			'since'         => null,
		);
		if ( ! empty( $r['valid'] ) ) {
			return array( 'status' => LicensePolicy::STATUS_ACTIVE ) + $out;
		}
		// Scaduta o sospesa: manca il pagamento; il resto (revocata, dominio diverso, chiave sbagliata, non ancora iniziata): licenza non valida per questo sito.
		if ( in_array( $reason, array( 'EXPIRED', 'SUSPENDED' ), true ) ) {
			$out['since'] = gmdate( 'Y-m-d', false !== $exp && $exp < $now ? $exp : $now );
			return array( 'status' => LicensePolicy::STATUS_UNPAID ) + $out;
		}
		$out['since'] = gmdate( 'Y-m-d', $now );
		return array( 'status' => LicensePolicy::STATUS_UNLICENSED ) + $out;
	}

	/** Spiegazione in italiano del motivo per cui la licenza non è valida. */
	public static function reason_label( string $reason ): string {
		$map = array(
			'ACTIVE'            => 'Licenza valida.',
			'IN_GRACE'          => 'Licenza scaduta da pochi giorni: c\'è ancora una breve tolleranza, rinnovala subito.',
			'EXPIRED'           => 'Licenza scaduta: rinnovala per riattivare le funzioni.',
			'SUSPENDED'         => 'Licenza sospesa dal fornitore.',
			'REVOKED'           => 'Licenza revocata.',
			'PENDING'           => 'La licenza non è ancora iniziata.',
			'DOMAIN_MISMATCH'   => 'La licenza è legata a un altro dominio: cambialo dall\'area riservata del portale.',
			'LICENSE_NOT_FOUND' => 'Chiave di licenza non riconosciuta: controlla di averla copiata per intero.',
		);
		return $map[ $reason ] ?? '';
	}

	/**
	 * Chiede al server di verificare la chiave per questo dominio.
	 *
	 * @return array|\WP_Error corpo della risposta (valid, reason_code, plan_name, expires_at, attestation…)
	 */
	public static function validate( string $key, string $domain ) {
		$res = wp_remote_post(
			self::api() . '/validate',
			array(
				'timeout' => 15,
				'headers' => array( 'Content-Type' => 'application/json', 'Accept' => 'application/json' ),
				'body'    => wp_json_encode( array( 'license_key' => $key, 'domain' => $domain ) ),
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$body = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( 429 === $code || $code >= 500 || 0 === $code ) {
			return new \WP_Error( 'asem_license_unavailable', 'Il server delle licenze non è disponibile adesso (codice ' . $code . ').' );
		}
		if ( 200 !== $code || ! is_array( $body ) ) {
			return new \WP_Error( 'asem_license_bad', 'Risposta inattesa dal server delle licenze (codice ' . $code . ').' );
		}
		if ( isset( $body['data'] ) && is_array( $body['data'] ) && ! isset( $body['valid'] ) ) {
			$body = $body['data'];
		}
		return $body;
	}

	/** Chiavi pubbliche di Wega (formato JWKS): quelle scritte nel plugin. Nei test si possono sostituire con la costante ASEM_LICENSE_JWKS (con ASEM_TESTING). */
	public static function jwks(): array {
		if ( defined( 'ASEM_TESTING' ) && ASEM_TESTING && defined( 'ASEM_LICENSE_JWKS' ) ) {
			$doc = json_decode( (string) ASEM_LICENSE_JWKS, true );
			if ( is_array( $doc ) && isset( $doc['keys'] ) ) {
				return $doc;
			}
		}
		$keys = array();
		foreach ( self::PINNED_KEYS as $kid => $x ) {
			$keys[] = array( 'kty' => 'OKP', 'crv' => 'Ed25519', 'kid' => $kid, 'x' => $x, 'use' => 'sig', 'alg' => 'EdDSA' );
		}
		return array( 'keys' => $keys );
	}

	private static function b64d( string $s ): string {
		$r = base64_decode( strtr( $s, '-_', '+/' ) . str_repeat( '=', ( 4 - strlen( $s ) % 4 ) % 4 ), true );
		return false === $r ? '' : $r;
	}

	/**
	 * Verifica l'attestazione (JWT firmato EdDSA/Ed25519) senza contattare il server: firma, emittente, destinatario, scadenza e dominio.
	 * Accetta solo EdDSA (mai «none» o HMAC).
	 *
	 * @param array|null $jwks     documento JWKS del server
	 * @param string     $audience valore di `product_code` della risposta
	 * @param string|null $domain  dominio atteso (`bid`), null per le licenze senza dominio
	 * @return array|null le dichiarazioni (claim) se valida, altrimenti null
	 */
	public static function verify_attestation( string $jwt, ?array $jwks, string $issuer, string $audience, ?string $domain, ?int $now = null, int $leeway = 0 ): ?array {
		$parts = explode( '.', $jwt );
		if ( 3 !== count( $parts ) || ! $jwks || ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
			return null;
		}
		$head   = json_decode( self::b64d( $parts[0] ), true );
		$claims = json_decode( self::b64d( $parts[1] ), true );
		$sig    = self::b64d( $parts[2] );
		if ( ! is_array( $head ) || ! is_array( $claims ) || 'EdDSA' !== ( $head['alg'] ?? '' ) || 64 !== strlen( $sig ) ) {
			return null;
		}
		$pub = '';
		foreach ( (array) ( $jwks['keys'] ?? array() ) as $k ) {
			if ( is_array( $k ) && ( $k['kid'] ?? null ) === ( $head['kid'] ?? '' ) && 'OKP' === ( $k['kty'] ?? '' ) && 'Ed25519' === ( $k['crv'] ?? '' ) ) {
				$pub = self::b64d( (string) ( $k['x'] ?? '' ) );
			}
		}
		if ( 32 !== strlen( $pub ) || ! sodium_crypto_sign_verify_detached( $sig, $parts[0] . '.' . $parts[1], $pub ) ) {
			return null;
		}
		$now = $now ?? time();
		$aud = $claims['aud'] ?? '';
		if ( ( $claims['iss'] ?? '' ) !== $issuer || ! in_array( $audience, (array) $aud, true ) ) {
			return null;
		}
		if ( ! isset( $claims['exp'] ) || (int) $claims['exp'] + $leeway <= $now || ( isset( $claims['nbf'] ) && (int) $claims['nbf'] > $now + 60 ) ) {
			return null;
		}
		// `bid` è il dominio registrato della licenza: vale anche per i suoi sottodomini (siti di prova).
		if ( null !== $domain && ! LicenseRules::same_domain( (string) ( $claims['bid'] ?? '' ), $domain ) ) {
			return null;
		}
		return $claims;
	}
}
