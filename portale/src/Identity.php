<?php
namespace Portale;

/**
 * Accesso unico: il portale parla col SaaS (unico elenco di persone) con richieste firmate (saas/docs/IDENTITA_API.md).
 * Il `sid` (sessione centrale) resta nella sessione lato server del portale: al browser va solo il cookie di sessione del portale.
 * Nessuna password o codice si conserva qui: passano solo nella richiesta firmata. Spento finché `identity.enabled` non è true.
 */
final class Identity {

	const AUD     = 'associazionesemplice-saas';
	const RECHECK = 300;

	private static function cfg( string $k, $default = null ) {
		return Config::get( 'identity.' . $k, $default );
	}

	public static function enabled(): bool {
		return true === self::cfg( 'enabled', false ) && Plugin\StaffSso::signingEnabled();
	}

	private static function seed(): string {
		$raw = (string) Config::get( 'staff_area_sign_key', '' );
		if ( '' === $raw ) {
			$file = (string) Config::get( 'staff_area_sign_key_file', dirname( __DIR__ ) . '/data/staff-area.key' );
			$raw  = is_readable( $file ) ? trim( (string) file_get_contents( $file ) ) : '';
		}
		return '' === $raw ? '' : (string) base64_decode( $raw, true );
	}

	/** Tipo del token per un'azione: `email-confirm` → `identity_email_confirm`. */
	public static function typ( string $action ): string {
		return 'identity_' . str_replace( '-', '_', $action );
	}

	/** Base dei collegamenti nelle email (reimposta password, conferma email): fissa, mai dalla richiesta. */
	public static function linkBase( string $what ): string {
		return 'https://associazionesemplice.it/accedi/' . $what . '/'; // fisso: il SaaS accetta solo questo dominio
	}

	const ACTIONS = array( 'login', 'code', 'session', 'profile', 'password', 'email', 'email-confirm', '2fa-start', '2fa-confirm', '2fa-disable', 'sessions', 'session-revoke', 'logout', 'reset-request', 'reset-complete', 'token' );

	/** @return array [codice HTTP (0 = nessuna risposta), corpo decodificato] */
	public static function call( string $action, array $data, ?int $now = null ): array {
		if ( ! in_array( $action, self::ACTIONS, true ) ) {
			throw new \InvalidArgumentException( 'azione sconosciuta' );
		}
		$seed = self::seed();
		if ( ! self::enabled() || SODIUM_CRYPTO_SIGN_SEEDBYTES !== strlen( $seed ) ) {
			return array( 0, array() );
		}
		$now   = $now ?? time();
		$token = PortalAttestation::sign( $data + array(
			'iss' => rtrim( (string) Config::get( 'base_url', 'https://associazionesemplice.it' ), '/' ),
			'aud'  => self::AUD,
			'area' => 'wordpress', // una chiave vale per la sua area soltanto (AreaRequestVerifier)
			'typ' => self::typ( $action ),
			'iat' => $now,
			'exp' => $now + 60,
			'jti' => bin2hex( random_bytes( 16 ) ),
		), $seed );
		$res = Http::send( 'POST', Plugin\StaffSso::apiUrl() . '/api/staff/v1/identity/' . $action, array( 'Content-Type: application/json', 'Accept: application/json' ), (string) json_encode( array( 'token' => $token ) ) );
		$out = json_decode( (string) $res[1], true );
		return array( (int) $res[0], is_array( $out ) ? $out : array() );
	}

	/**
	 * Email e password. @return array `status`: ok (sessione aperta), code_required (serve /twofactor), denied, invalid (con `messages`), limit, down
	 */
	public static function login( string $email, string $password, string $ip ): array {
		list( $code, $out ) = self::call( 'login', array( 'email' => strtolower( mb_substr( trim( $email ), 0, 190 ) ), 'password' => $password, 'ip' => mb_substr( $ip, 0, 45 ) ) );
		return self::outcome( $code, $out );
	}

	public static function code( string $ticket, string $code, string $ip ): array {
		list( $status, $out ) = self::call( 'code', array( 'ticket' => $ticket, 'code' => mb_substr( preg_replace( '/\s+/', '', $code ), 0, 12 ), 'ip' => mb_substr( $ip, 0, 45 ) ) );
		return self::outcome( $status, $out );
	}

	private static function outcome( int $code, array $out ): array {
		if ( 200 === $code && 'ok' === ( $out['status'] ?? '' ) && is_string( $out['sid'] ?? null ) && 1 === preg_match( '/^[0-9a-f]{32}$/', $out['sid'] ) ) {
			return array( 'status' => 'ok', 'sid' => $out['sid'], 'person' => (array) ( $out['person'] ?? array() ), 'areas' => self::cleanAreas( $out['areas'] ?? array() ) );
		}
		if ( 200 === $code && 'code_required' === ( $out['status'] ?? '' ) && is_string( $out['ticket'] ?? null ) && '' !== $out['ticket'] && strlen( $out['ticket'] ) <= 200 ) {
			return array( 'status' => 'code_required', 'ticket' => $out['ticket'] );
		}
		if ( 429 === $code ) {
			return array( 'status' => 'limit' );
		}
		if ( 422 === $code ) {
			return array( 'status' => 'invalid', 'messages' => array_slice( array_map( 'strval', array_filter( (array) ( $out['messages'] ?? array() ), 'is_scalar' ) ), 0, 5 ) );
		}
		return array( 'status' => 0 === $code || $code >= 500 ? 'down' : 'denied' );
	}

	/** @return array<string,string> area → livello, solo valori noti */
	public static function cleanAreas( $areas ): array {
		$out = array();
		foreach ( (array) $areas as $area => $level ) {
			if ( in_array( $area, array( 'wordpress', 'saas', 'supporto' ), true ) && is_string( $level ) && 1 === preg_match( '/^[a-z_]{1,20}$/', $level ) ) {
				$out[ $area ] = $level;
			}
		}
		return $out;
	}

	/** Apre la sessione unica: salva il `sid` solo lato server. */
	public static function open( array $ok, string $email, ?int $now = null ): void {
		$now = $now ?? time();
		$_SESSION['identity'] = array( 'sid' => $ok['sid'], 'email' => strtolower( $email ), 'person' => $ok['person'], 'areas' => $ok['areas'], 'opened' => $now, 'checked' => $now, 'fails' => 0 );
	}

	/** La sessione unica corrente, o null. */
	public static function current(): ?array {
		$s = $_SESSION['identity'] ?? null;
		return is_array( $s ) && isset( $s['sid'], $s['email'] ) ? $s : null;
	}

	public static function close(): void {
		unset( $_SESSION['identity'], $_SESSION['identity_ticket'] );
	}

	/**
	 * Riconferma al SaaS (ogni 5 minuti, o subito con $force prima di azioni che cambiano qualcosa). Sessione revocata o scaduta = chiusa.
	 * Nessuna risposta: si tiene per ora, al secondo controllo mancato di fila si chiude.
	 * @return array|null la sessione dopo il controllo, o null se è chiusa
	 */
	public static function check( bool $force = false, ?int $now = null ): ?array {
		$s = self::current();
		if ( ! $s ) {
			return null;
		}
		$now = $now ?? time();
		if ( ! $force && $now - (int) $s['checked'] < self::RECHECK ) {
			return $s;
		}
		list( $code, $out ) = self::call( 'session', array( 'sid' => $s['sid'] ), $now );
		if ( 200 === $code && array_key_exists( 'valid', $out ) ) {
			if ( true !== $out['valid'] ) {
				self::close();
				return null;
			}
			$_SESSION['identity']['areas']   = self::cleanAreas( $out['areas'] ?? array() );
			$_SESSION['identity']['person']  = (array) ( $out['person'] ?? $s['person'] );
			$_SESSION['identity']['checked'] = $now;
			$_SESSION['identity']['fails']   = 0;
			return $_SESSION['identity'];
		}
		$fails = (int) $s['fails'] + 1;
		if ( $fails >= 2 ) {
			self::close();
			return null;
		}
		$_SESSION['identity']['fails']   = $fails;
		$_SESSION['identity']['checked'] = $now;
		return $_SESSION['identity'];
	}

	/** Token per entrare in un'area (solo se la persona ha il ruolo); il browser lo manda in POST all'indirizzo fisso dell'area. */
	public static function tokenFor( array $s, string $area, ?int $now = null ): ?string {
		if ( ! isset( Plugin\StaffSso::DEST[ $area ] ) && 'wordpress' !== $area ) {
			return null;
		}
		list( $code, $out ) = self::call( 'token', array( 'sid' => $s['sid'], 'to' => $area ), $now );
		return 200 === $code && is_string( $out['token'] ?? null ) && '' !== $out['token'] ? $out['token'] : null;
	}

	public static function logout(): void {
		$s = self::current();
		if ( $s ) {
			self::call( 'logout', array( 'sid' => $s['sid'] ) );
		}
		self::close();
	}

	/** Profilo della persona: dati, aree, secondo livello sì/no, sessioni aperte. Null se la sessione non vale più. */
	public static function profile( array $s ): ?array {
		list( $code, $out ) = self::call( 'profile', array( 'sid' => $s['sid'] ) );
		if ( 200 !== $code || ! is_array( $out['sessions'] ?? null ) ) {
			return null;
		}
		$sessions = array();
		foreach ( $out['sessions'] as $x ) {
			if ( is_array( $x ) && is_string( $x['id'] ?? null ) ) {
				$sessions[] = array( 'id' => $x['id'], 'ip' => (string) ( $x['ip'] ?? '' ), 'last' => (string) ( $x['last_used_at'] ?? '' ), 'created' => (string) ( $x['created_at'] ?? '' ), 'current' => ! empty( $x['current'] ) );
			}
		}
		return array( 'person' => (array) ( $out['person'] ?? array() ), 'areas' => self::cleanAreas( $out['areas'] ?? array() ), 'two_factor' => ! empty( $out['two_factor'] ), 'sessions' => $sessions );
	}

	/**
	 * Azione che cambia qualcosa (password, email, secondo livello, chiusura di una sessione). La sessione si riconferma subito prima.
	 * @return array `status` ok | invalid (con `messages`) | denied | limit | down | closed (sessione non più valida); `body` = risposta del SaaS
	 */
	public static function act( string $action, array $data ): array {
		if ( ! in_array( $action, array( 'password', 'email', '2fa-start', '2fa-confirm', '2fa-disable', 'session-revoke' ), true ) ) {
			throw new \InvalidArgumentException( 'azione sconosciuta' );
		}
		$s = self::check( true );
		if ( ! $s ) {
			return array( 'status' => 'closed', 'messages' => array(), 'body' => array() );
		}
		list( $code, $out ) = self::call( $action, array( 'sid' => $s['sid'] ) + $data );
		$status = 200 === $code ? 'ok' : ( 422 === $code ? 'invalid' : ( 429 === $code ? 'limit' : ( 0 === $code || $code >= 500 ? 'down' : 'denied' ) ) );
		return array( 'status' => $status, 'messages' => array_slice( array_map( 'strval', array_filter( (array) ( $out['messages'] ?? array() ), 'is_scalar' ) ), 0, 5 ), 'body' => $out );
	}

	/** Conferma del nuovo indirizzo dal link nella mail (non serve la sessione). */
	public static function emailConfirm( string $code ): bool {
		list( $status ) = self::call( 'email-confirm', array( 'code' => mb_substr( $code, 0, 128 ) ) );
		return 200 === $status;
	}

	/** Chiede il link per reimpostare la password: la risposta è sempre la stessa. */
	public static function resetRequest( string $email, string $ip ): void {
		self::call( 'reset-request', array( 'email' => strtolower( mb_substr( trim( $email ), 0, 190 ) ), 'link_base' => self::linkBase( 'reimposta' ), 'ip' => mb_substr( $ip, 0, 45 ) ) );
	}

	/** @return string ok, invalid, limit, down o denied */
	public static function resetComplete( string $code, string $password ): string {
		list( $status, $out ) = self::call( 'reset-complete', array( 'code' => mb_substr( $code, 0, 128 ), 'password' => $password ) );
		if ( 200 === $status ) {
			return 'ok';
		}
		return 422 === $status ? 'invalid' : ( 429 === $status ? 'limit' : ( 0 === $status || $status >= 500 ? 'down' : 'denied' ) );
	}
}
