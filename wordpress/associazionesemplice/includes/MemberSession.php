<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Accesso proprio dei soci, senza utente WordPress: sessione in una tabella nostra, cookie dedicato, protezione CSRF per sessione.
 * Resta spento finché non lo si accende dai Limiti («member_sessions»): da spento il cookie non si legge e l'unica via è il
 * collegamento con l'utente WordPress, come prima. Gli orari delle tabelle di accesso sono in UTC.
 *
 * I permessi non cambiano: si ricavano dalla persona con Access::decide, qui si dice solo chi è la persona che sta navigando.
 */
final class MemberSession {

	const COOKIE        = 'asem_s';
	const IDLE_DAYS     = 30; // la sessione scade dopo tanti giorni senza usarla
	const MAX_DAYS      = 90; // e in ogni caso dopo tanti giorni dall'accesso
	const TOUCH_SECONDS = 300; // l'ultimo uso si aggiorna al massimo ogni 5 minuti
	const CSRF_SECONDS  = 12 * HOUR_IN_SECONDS;

	/** @var array|false|null sessione trovata in questa richiesta (false = nessuna) */
	private static $current = null;

	public static function enabled(): bool {
		return Limits::flag( 'member_sessions' );
	}

	/** Segreto per firmare codici e token di questo accesso (derivato dal sale del sito). */
	public static function secret(): string {
		return wp_salt( 'auth' ) . '|member-session';
	}

	private static function utc( int $ts ): string {
		return gmdate( 'Y-m-d H:i:s', $ts );
	}

	/** Può entrare con l'accesso proprio? Come per l'attivazione: tipo di socio che può accedere e domanda di adesione già approvata. */
	public static function person_allowed( ?array $p ): bool {
		return $p && empty( $p['deleted_at'] ) && empty( $p['anonymized_at'] ) && empty( $p['application_status'] ) && MemberType::can_login( (string) $p['type'] );
	}

	/** Apre una sessione per la persona e mette il cookie. @return string il token (anche se il cookie non si può scrivere) */
	public static function start( int $person_id ): string {
		$raw = SessionToken::generate();
		$now = time();
		Db::db()->insert(
			Db::t( 'member_sessions' ),
			array(
				'token_hash' => SessionToken::hash( $raw ),
				'person_id'  => $person_id,
				'created_at' => self::utc( $now ),
				'last_seen'  => self::utc( $now ),
				'expires_at' => self::utc( $now + self::IDLE_DAYS * DAY_IN_SECONDS ),
				'ip_hash'    => substr( hash_hmac( 'sha256', ClientIp::get(), self::secret() ), 0, 32 ),
				'ua_hash'    => substr( hash_hmac( 'sha256', isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '', self::secret() ), 0, 32 ),
			)
		);
		self::set_cookie( $raw, $now + self::IDLE_DAYS * DAY_IN_SECONDS );
		$_COOKIE[ self::COOKIE ] = $raw;
		self::$current           = null;
		return $raw;
	}

	private static function set_cookie( string $value, int $expires ): void {
		if ( headers_sent() ) {
			return;
		}
		setcookie(
			self::COOKIE,
			$value,
			array(
				'expires'  => $expires,
				'path'     => defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => defined( 'COOKIE_DOMAIN' ) && COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
	}

	private static function cookie_token(): string {
		$raw = isset( $_COOKIE[ self::COOKIE ] ) ? (string) wp_unslash( $_COOKIE[ self::COOKIE ] ) : '';
		return SessionToken::is_well_formed( $raw ) ? $raw : '';
	}

	/** La sessione valida di questa richiesta (riga della tabella) oppure null. Da spento non guarda nemmeno il cookie. */
	public static function current(): ?array {
		if ( null !== self::$current ) {
			return self::$current ?: null;
		}
		$raw = self::cookie_token();
		if ( '' === $raw || ! self::enabled() ) {
			return null; // niente in memoria: l'interruttore o il cookie possono cambiare nella stessa richiesta
		}
		self::$current = false;
		$db            = Db::db();
		$tbl = Db::t( 'member_sessions' );
		$now = time();
		$row = $db->get_row( $db->prepare( "SELECT * FROM $tbl WHERE token_hash = %s AND expires_at > %s AND created_at > %s", SessionToken::hash( $raw ), self::utc( $now ), self::utc( $now - self::MAX_DAYS * DAY_IN_SECONDS ) ), ARRAY_A );
		if ( ! $row ) {
			return null;
		}
		if ( strtotime( $row['last_seen'] . ' UTC' ) < $now - self::TOUCH_SECONDS ) { // la sessione si rinnova usandola
			$exp = self::utc( min( $now + self::IDLE_DAYS * DAY_IN_SECONDS, strtotime( $row['created_at'] . ' UTC' ) + self::MAX_DAYS * DAY_IN_SECONDS ) );
			$db->update( $tbl, array( 'last_seen' => self::utc( $now ), 'expires_at' => $exp ), array( 'id' => (int) $row['id'] ) );
		}
		self::$current = $row;
		return $row;
	}

	/** La persona dell'accesso proprio di questa richiesta (null se non c'è sessione o la persona non può più entrare). */
	public static function session_person(): ?array {
		$s = self::current();
		if ( ! $s ) {
			return null;
		}
		$p = Plugin::people()->get( (int) $s['person_id'] );
		if ( self::person_allowed( $p ) ) {
			return $p;
		}
		self::destroy(); // la persona non può più entrare (tolta, anonimizzata, tipo cambiato)
		return null;
	}

	/**
	 * Chi sta navigando. Se è collegato a WordPress vale WordPress (segreteria, tesoriere e consiglio restano utenti WordPress e i loro
	 * poteri sono coerenti); altrimenti vale l'accesso proprio.
	 */
	public static function current_person(): ?array {
		return Access::current_person();
	}

	/** Base dei numeri che identificano chi paga o agisce senza utente WordPress: molto sopra qualsiasi id utente, così non si confonde con nessuno. */
	const ACTOR_BASE = 1000000000000;

	/**
	 * Chi agisce, come numero: l'utente WordPress se collegato; con l'accesso proprio un numero legato alla persona (ACTOR_BASE + id);
	 * 0 se nessuno. Serve dove un servizio vuole «l'utente» (pagamenti, limiti orari, avvisi) e 0 per tutti i soci li confonderebbe.
	 */
	public static function actor_key(): int {
		if ( is_user_logged_in() ) {
			return get_current_user_id();
		}
		$p = self::session_person();
		return $p ? self::ACTOR_BASE + (int) $p['id'] : 0;
	}

	/** Qualcuno è entrato, in uno dei due modi. */
	public static function logged_in(): bool {
		return is_user_logged_in() || null !== self::session_person();
	}

	/** Entrato con l'accesso proprio e non con WordPress (i moduli usano il token CSRF della sessione). */
	public static function is_own_session(): bool {
		return ! is_user_logged_in() && null !== self::session_person();
	}

	/** Chiude la sessione di questa richiesta e toglie il cookie. */
	public static function destroy(): void {
		$raw = self::cookie_token();
		if ( '' !== $raw ) {
			Db::db()->delete( Db::t( 'member_sessions' ), array( 'token_hash' => SessionToken::hash( $raw ) ) );
		}
		self::set_cookie( '', time() - YEAR_IN_SECONDS );
		unset( $_COOKIE[ self::COOKIE ] );
		self::$current = false;
	}

	/** «Esci da tutti i dispositivi» e revoca dalla segreteria. @return int sessioni chiuse */
	public static function destroy_all( int $person_id ): int {
		self::$current = null;
		return (int) Db::db()->delete( Db::t( 'member_sessions' ), array( 'person_id' => $person_id ) );
	}

	/** Cancella tutti i dati di accesso della persona (eliminazione e anonimizzazione). */
	public static function forget_person( int $person_id ): void {
		$db = Db::db();
		foreach ( array( 'member_sessions', 'member_auth', 'member_challenges' ) as $t ) {
			$db->delete( Db::t( $t ), array( 'person_id' => $person_id ) );
		}
		do_action( 'asem_member_access_forgotten', $person_id, self::ACTOR_BASE + $person_id ); // le funzioni avanzate tolgono qui ciò che hanno legato alla persona (ad esempio le notifiche del telefono)
		self::$current = null;
	}

	/** Lavoro giornaliero: toglie sessioni e codici scaduti. @return int righe tolte */
	public static function purge_expired(): int {
		$db  = Db::db();
		$now = time();
		$n   = (int) $db->query( $db->prepare( 'DELETE FROM ' . Db::t( 'member_sessions' ) . ' WHERE expires_at <= %s OR created_at <= %s', self::utc( $now ), self::utc( $now - self::MAX_DAYS * DAY_IN_SECONDS ) ) );
		$n    += (int) $db->query( $db->prepare( 'DELETE FROM ' . Db::t( 'member_challenges' ) . ' WHERE expires_at <= %s', self::utc( $now - DAY_IN_SECONDS ) ) );
		return $n;
	}

	/** Token CSRF per la sessione propria (stringa vuota se non c'è: i moduli degli utenti WordPress continuano con i loro nonce). */
	public static function csrf( string $action ): string {
		$s = self::current();
		return $s ? SessionToken::csrf( (string) $s['token_hash'], $action, time() + self::CSRF_SECONDS, self::secret() ) : '';
	}

	public static function check_csrf( string $action, string $token ): bool {
		$s = self::current();
		return $s && SessionToken::check_csrf( $token, (string) $s['token_hash'], $action, time(), self::secret() );
	}
}
