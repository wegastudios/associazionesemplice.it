<?php
namespace Portale\Plugin;

use Portale\Config;
use Portale\Db;
use Portale\Http;
use Portale\PortalAttestation;

/**
 * Accesso staff unico, lato portale (docs/STAFF_SSO.md): l'elenco degli utenti vive nel SaaS, che emette il token `staff_sso`;
 * `/wordpress/admin` è un'area che lo riceve. Tutto spento finché `staff_sso.enabled` in config.php non è `true`.
 *
 * Questo file: verifica del token, sessione dell'area (inattività 2 ore, durata 8 ore), controllo ogni 5 minuti presso il SaaS
 * (`session-check`, due controlli senza risposta di fila chiudono la sessione) e richieste «Utenti» firmate dal portale.
 * Il secondo fattore è facoltativo (decisione della titolare): `mfa` è solo informativo e non si pretende mai.
 *
 * config.php:  'staff_sso' => array( 'enabled' => true, 'issuer' => 'https://associazionesemplice.it/saas',
 *                                    'api_url' => 'https://associazionesemplice.it/saas', 'keys' => array( 'staff-xxxxxxxx' => '<x>' ) )
 * La chiave con cui il portale firma le sue richieste è nuova e separata: data/staff-area.key (`php bin/staff-area-key.php`).
 */
final class StaffSso {

	const AUD       = 'associazionesemplice-portale';
	const AREA      = 'wordpress';
	const LEVELS    = array( 'admin', 'vendita', 'supporto' );
	const ALLOWED   = array( 'iss', 'aud', 'typ', 'iat', 'exp', 'jti', 'sub', 'area', 'level', 'mfa', 'mfa_at', 'sid', 'areas' );
	const IDLE      = 7200;   // 2 ore
	const MAX       = 28800;  // 8 ore
	const RECHECK   = 300;    // 5 minuti
	const MAX_FAILS = 2;
	const SKEW      = 60;

	private static function cfg( string $k, $default = null ) {
		$c = (array) Config::get( 'staff_sso', array() );
		return $c[ $k ] ?? $default;
	}

	public static function enabled(): bool {
		return true === self::cfg( 'enabled', false ) && function_exists( 'sodium_crypto_sign_verify_detached' ) && array() !== self::keys();
	}

	/** @return array<string,string> kid => chiave pubblica (base64url) con cui il SaaS firma lo staff_sso */
	public static function keys(): array {
		$out = array();
		foreach ( (array) self::cfg( 'keys', array() ) as $kid => $x ) {
			if ( is_string( $kid ) && is_string( $x ) && '' !== $kid && '' !== $x ) {
				$out[ $kid ] = $x;
			}
		}
		return $out;
	}

	public static function issuer(): string {
		return rtrim( (string) self::cfg( 'issuer', 'https://associazionesemplice.it/saas' ), '/' );
	}

	public static function apiUrl(): string {
		return rtrim( (string) self::cfg( 'api_url', 'https://associazionesemplice.it/saas' ), '/' );
	}

	private static function unb64( string $s ): string {
		return (string) base64_decode( strtr( $s, '-_', '+/' ), true );
	}

	/**
	 * Verifica il token `staff_sso`: firma e kid fissato, emittente, destinatario, tipo, area, livello, date (vita al massimo 60 s,
	 * tolleranza 60 s), campi sconosciuti. Non consuma il `jti` (lo fa {@see receive}).
	 * @return array|null i claim, o null se qualcosa non torna
	 */
	public static function verify( string $jwt, ?int $now = null ): ?array {
		$now = $now ?? time();
		$p   = explode( '.', $jwt );
		if ( 3 !== count( $p ) || strlen( $jwt ) > 4096 || ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
			return null;
		}
		$h = json_decode( self::unb64( $p[0] ), true );
		$c = json_decode( self::unb64( $p[1] ), true );
		if ( ! is_array( $h ) || ! is_array( $c ) || 'EdDSA' !== ( $h['alg'] ?? '' ) || ! is_string( $h['kid'] ?? null ) ) {
			return null;
		}
		$keys = self::keys();
		if ( ! isset( $keys[ $h['kid'] ] ) ) {
			return null;
		}
		$pub = self::unb64( $keys[ $h['kid'] ] );
		$sig = self::unb64( $p[2] );
		if ( SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES !== strlen( $pub ) || SODIUM_CRYPTO_SIGN_BYTES !== strlen( $sig ) || ! sodium_crypto_sign_verify_detached( $sig, $p[0] . '.' . $p[1], $pub ) ) {
			return null;
		}
		if ( array() !== array_diff( array_keys( $c ), self::ALLOWED ) ) {
			return null;
		}
		if ( ( $c['iss'] ?? '' ) !== self::issuer() || ( $c['aud'] ?? '' ) !== self::AUD || 'staff_sso' !== ( $c['typ'] ?? '' ) || self::AREA !== ( $c['area'] ?? '' ) ) {
			return null;
		}
		$iat = $c['iat'] ?? null;
		$exp = $c['exp'] ?? null;
		if ( ! is_int( $iat ) || ! is_int( $exp ) || $exp - $iat > 60 || $exp + self::SKEW < $now || $iat - self::SKEW > $now ) {
			return null;
		}
		$email = $c['sub'] ?? null;
		if ( ! is_string( $email ) || strlen( $email ) > 190 || ! filter_var( $email, FILTER_VALIDATE_EMAIL ) || strtolower( $email ) !== $email ) {
			return null;
		}
		if ( ! in_array( $c['level'] ?? null, self::LEVELS, true ) || ! is_string( $c['jti'] ?? null ) || '' === $c['jti'] || strlen( $c['jti'] ) > 64 ) {
			return null;
		}
		if ( ! is_string( $c['sid'] ?? null ) || 1 !== preg_match( '/^[0-9a-f]{32}$/', $c['sid'] ) || ( isset( $c['mfa'] ) && ! is_bool( $c['mfa'] ) ) ) {
			return null;
		}
		return $c;
	}

	/** Livello del SaaS → ruolo dell'amministrazione del plugin. */
	public static function roleFor( string $level ): ?string {
		$map = array( 'admin' => \Portale\Staff::ADMIN, 'supporto' => \Portale\Staff::SUPPORT );
		return $map[ $level ] ?? null;
	}

	/**
	 * Riceve la POST del selettore (campo `token`): verifica, consuma il `jti` (una volta sola) e apre la sessione dell'area.
	 * @return bool true se la sessione è aperta
	 */
	public static function receive( Db $db, string $jwt, ?int $now = null ): bool {
		if ( ! self::enabled() ) {
			return false;
		}
		$now = $now ?? time();
		$c   = self::verify( $jwt, $now );
		if ( ! $c || ! $db->claimEvent( 'staff_sso', $c['jti'] ) ) {
			return false;
		}
		$_SESSION['staff_sso'] = array(
			'email'   => $c['sub'],
			'level'   => $c['level'],
			'sid'     => $c['sid'],
			'areas'   => self::cleanAreas( $c['areas'] ?? array() ),
			'opened'  => $now,
			'last'    => $now,
			'checked' => $now,
			'fails'   => 0,
			'mfa'     => ! empty( $c['mfa'] ),
		);
		return true;
	}

	/** @return array<string,string> area → livello, solo valori noti (il selettore «Vai a» si disegna da qui) */
	private static function cleanAreas( $areas ): array {
		$out = array();
		foreach ( (array) $areas as $area => $level ) {
			if ( in_array( $area, array( 'wordpress', 'saas', 'supporto' ), true ) && in_array( $level, array( 'admin', 'vendita', 'supporto', 'agente' ), true ) ) {
				$out[ $area ] = $level;
			}
		}
		return $out;
	}

	/** La sessione dell'area, o null se manca, è inattiva da più di 2 ore o dura da più di 8 (e in tal caso la chiude). */
	public static function current( ?int $now = null ): ?array {
		$now = $now ?? time();
		$s   = $_SESSION['staff_sso'] ?? null;
		if ( ! is_array( $s ) || ! isset( $s['email'], $s['sid'], $s['opened'], $s['last'] ) ) {
			return null;
		}
		if ( $now - (int) $s['last'] > self::IDLE || $now - (int) $s['opened'] > self::MAX ) {
			unset( $_SESSION['staff_sso'] );
			return null;
		}
		$_SESSION['staff_sso']['last'] = $now;
		return $_SESSION['staff_sso'];
	}

	public static function close(): void {
		unset( $_SESSION['staff_sso'] );
	}

	/** Da riconfermare presso il SaaS (l'ultima conferma ha più di 5 minuti)? */
	public static function due( array $s, ?int $now = null ): bool {
		return ( $now ?? time() ) - (int) ( $s['checked'] ?? 0 ) >= self::RECHECK;
	}

	/**
	 * Riconferma la sessione al SaaS e applica la risposta: sessione non valida = chiusa; livello cambiato = adottato;
	 * nessuna risposta = «non so»: al secondo controllo fallito di fila si chiude. Il portale irraggiungibile non apre sessioni nuove.
	 * @return array|null la sessione dopo il controllo, o null se è stata chiusa
	 */
	public static function recheck( array $s, ?int $now = null ): ?array {
		$now = $now ?? time();
		list( $code, $out ) = self::request( 'POST', '/api/staff/v1/session-check', 'staff_session_check', array( 'sid' => $s['sid'], 'area' => self::AREA ), $now );
		if ( 200 === $code && array_key_exists( 'valid', $out ) ) {
			if ( true !== $out['valid'] || ! in_array( $out['level'] ?? null, self::LEVELS, true ) ) {
				self::close();
				return null;
			}
			$_SESSION['staff_sso']['level']   = $out['level'];
			$_SESSION['staff_sso']['checked'] = $now;
			$_SESSION['staff_sso']['fails']   = 0;
			return $_SESSION['staff_sso'];
		}
		$fails = (int) ( $_SESSION['staff_sso']['fails'] ?? 0 ) + 1;
		if ( $fails >= self::MAX_FAILS ) {
			self::close();
			return null;
		}
		$_SESSION['staff_sso']['fails']   = $fails;
		$_SESSION['staff_sso']['checked'] = $now;
		return $_SESSION['staff_sso'];
	}

	/**
	 * Richiesta «Utenti» al SaaS (list, invite, grant, revoke, deactivate). Il SaaS decide chi può cosa; qui si passa solo `act`.
	 * @return array [codice HTTP (0 = rete), corpo decodificato]
	 */
	public static function people( string $op, string $act, array $data = array(), ?int $now = null ): array {
		if ( ! in_array( $op, array( 'list', 'invite', 'grant', 'revoke', 'deactivate' ), true ) ) {
			throw new \InvalidArgumentException( 'operazione sconosciuta' );
		}
		return self::request( 'POST', '/api/staff/v1/people/' . $op, 'staff_people', array( 'area' => self::AREA, 'act' => strtolower( mb_substr( $act, 0, 190 ) ) ) + $data, $now );
	}

	/** Indirizzo dell'accesso staff (verso le tre aree fisse, mai un indirizzo preso dalla richiesta). */
	public static function loginUrl(): string {
		return self::apiUrl() . '/staff/accedi?area=' . self::AREA;
	}

	// --- Chiave del portale e richieste firmate -----------------------------------------------------------------------------

	private static function seed(): string {
		$raw = (string) Config::get( 'staff_area_sign_key', '' );
		if ( '' === $raw ) {
			$file = (string) Config::get( 'staff_area_sign_key_file', dirname( __DIR__, 2 ) . '/data/staff-area.key' );
			$raw  = is_readable( $file ) ? trim( (string) file_get_contents( $file ) ) : '';
		}
		$seed = '' === $raw ? '' : (string) base64_decode( $raw, true );
		return SODIUM_CRYPTO_SIGN_SEEDBYTES === strlen( $seed ) ? $seed : '';
	}

	public static function signingEnabled(): bool {
		return function_exists( 'sodium_crypto_sign_detached' ) && '' !== self::seed();
	}

	public static function kid(): string {
		return PortalAttestation::kid( self::seed() );
	}

	public static function publicKey(): string {
		return PortalAttestation::publicKey( self::seed() );
	}

	private static function request( string $method, string $path, string $typ, array $claims, ?int $now = null ): array {
		if ( ! self::signingEnabled() ) {
			return array( 0, array() );
		}
		$now   = $now ?? time();
		$token = PortalAttestation::sign( $claims + array(
			'iss' => rtrim( (string) Config::get( 'base_url', 'https://associazionesemplice.it' ), '/' ),
			'aud' => 'associazionesemplice-saas',
			'typ' => $typ,
			'iat' => $now,
			'exp' => $now + 60,
			'jti' => bin2hex( random_bytes( 16 ) ),
		), self::seed() );
		$res = Http::send( $method, self::apiUrl() . $path, array( 'Content-Type: application/json', 'Accept: application/json' ), (string) json_encode( array( 'token' => $token ) ) );
		$out = json_decode( (string) $res[1], true );
		return array( (int) $res[0], is_array( $out ) ? $out : array() );
	}
}
