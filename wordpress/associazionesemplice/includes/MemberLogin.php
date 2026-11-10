<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Entrare senza utente WordPress: link o codice a 6 cifre via email, oppure la password (facoltativa).
 * Ogni via d'ingresso finisce in MemberSession::start. Le risposte non dicono mai se un'email è di un socio.
 * Funziona solo con l'interruttore «member_sessions» acceso (lo controlla la pagina che chiama).
 */
final class MemberLogin {

	const TTL_SECONDS  = 900; // link e codice valgono 15 minuti
	const MAX_ATTEMPTS = 5;   // tentativi sul codice prima di doverne chiedere uno nuovo
	const MAX_CODE_FAILS_PER_HOUR = 10; // codici sbagliati in un'ora per persona, qualunque sia la richiesta: chiedere nuovi codici non azzera il conto
	const MAX_ACTIVE = 3;     // richieste valide contemporaneamente per persona (chi riceve due email non perde il primo link)
	const MAX_PASSWORD_BYTES = 1000; // oltre non si controlla nemmeno: l'impronta di una password enorme costerebbe al server

	/** @var bool le email di accesso partono dopo la risposta (così il tempo di risposta non dice se l'indirizzo è di un socio) */
	private static $defer = false;
	/** @var callable[] invii in attesa */
	private static $queue = array();

	public static function defer( bool $on ): void {
		self::$defer = $on;
	}

	/** Manda le email messe in coda, dopo che la risposta è stata consegnata. */
	public static function send_queued(): void {
		$jobs        = self::$queue;
		self::$queue = array();
		foreach ( $jobs as $job ) {
			$job();
		}
	}

	/** Chi può entrare così: socio con accesso e senza un utente WordPress (chi ha un utente, cioè chi ha poteri, entra da WordPress). */
	public static function person_by_email( string $email ): ?array {
		$email = strtolower( trim( $email ) );
		if ( '' === $email || ! is_email( $email ) ) {
			return null;
		}
		$p = Plugin::people()->find_by_email( $email );
		if ( ! MemberSession::person_allowed( $p ) ) {
			return null;
		}
		if ( ! empty( $p['wp_user_id'] ) ) {
			return null;
		}
		return $p;
	}

	private static function now(): string {
		return gmdate( 'Y-m-d H:i:s' );
	}

	/** Stato della password di un socio: «ok» (scelta), «none» (non l'ha ancora scelta), «reset» (ha chiesto il recupero e deve sceglierne una nuova). */
	public static function password_state( int $person_id ): string {
		$r = Db::db()->get_row( Db::db()->prepare( 'SELECT pass_hash, must_reset FROM ' . Db::t( 'member_auth' ) . ' WHERE person_id = %d', $person_id ), ARRAY_A );
		if ( ! $r || '' === (string) $r['pass_hash'] ) {
			return 'none';
		}
		return (int) $r['must_reset'] ? 'reset' : 'ok';
	}

	/** Dopo il recupero della password: prima di navigare il socio deve sceglierne una nuova. */
	private static function require_reset( int $person_id ): void {
		$db  = Db::db();
		$tbl = Db::t( 'member_auth' );
		if ( $db->get_var( $db->prepare( "SELECT COUNT(*) FROM $tbl WHERE person_id = %d", $person_id ) ) ) {
			$db->update( $tbl, array( 'must_reset' => 1 ), array( 'person_id' => $person_id ) );
			return;
		}
		$p = Plugin::people()->get( $person_id );
		$db->insert( $tbl, array( 'person_id' => $person_id, 'login_email' => strtolower( (string) ( $p['email'] ?? '' ) ), 'must_reset' => 1, 'created_at' => self::now() ) );
	}

	/**
	 * Manda link e codice se l'email è di un socio. Con $kind «reset» è il recupero della password: il link fa entrare ma il socio deve
	 * scegliere una nuova password prima di navigare. @return bool true se è partita una email (da non mostrare a chi chiede)
	 */
	public static function request( string $email, string $kind = 'login' ): bool {
		$kind = 'reset' === $kind ? 'reset' : 'login';
		if ( ! MemberSession::enabled() || ! Edition::allows( 'member_area' ) ) {
			return false;
		}
		$p = self::person_by_email( $email );
		if ( ! $p ) {
			return false;
		}
		$mail_key = 'asem_ml_m_' . md5( strtolower( (string) $p['email'] ) ); // come per il primo accesso: nessuno può inondare la casella di un socio
		$sent     = (int) get_transient( $mail_key );
		if ( $sent >= Limits::get( 'first_access_per_email' ) ) {
			return false;
		}
		$db  = Db::db();
		$tbl = Db::t( 'member_challenges' );
		$pid = (int) $p['id'];
		$db->query( $db->prepare( "DELETE FROM $tbl WHERE person_id = %d AND (used_at IS NOT NULL OR expires_at <= %s)", $pid, self::now() ) );
		$active = array_map( 'intval', (array) $db->get_col( $db->prepare( "SELECT id FROM $tbl WHERE person_id = %d ORDER BY id DESC", $pid ) ) );
		if ( count( $active ) >= self::MAX_ACTIVE ) { // restano le più recenti: chi chiede mille volte non fa scadere il link che il socio ha appena ricevuto
			$old = array_slice( $active, self::MAX_ACTIVE - 1 );
			$db->query( "DELETE FROM $tbl WHERE id IN (" . implode( ',', $old ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL -- id interi
		}
		$raw  = SessionToken::generate();
		$code = SessionToken::code();
		$db->insert(
			$tbl,
			array(
				'person_id'  => $pid,
				'kind'       => $kind,
				'token_hash' => SessionToken::hash( $raw ),
				'code_hash'  => SessionToken::hash_code( $code, $pid, MemberSession::secret() ),
				'created_at' => self::now(),
				'expires_at' => gmdate( 'Y-m-d H:i:s', time() + self::TTL_SECONDS ),
			)
		);
		$assoc = (string) Settings::get( 'association_name' );
		$link  = add_query_arg( 'asem_ml', $raw, home_url( '/' ) );
		if ( 'reset' === $kind ) {
			$text    = 'Ciao ' . $p['first_name'] . ",\n\nhai chiesto di reimpostare la password" . ( '' !== $assoc ? ' di ' . $assoc : '' ) . ". Apri questo link e scegli una nuova password:\n\n" . $link
				. "\n\noppure scrivi questo codice nella pagina di accesso: " . $code
				. "\n\nValgono 15 minuti e si usano una volta sola. Se non l'hai chiesto tu, ignora questo messaggio: la tua password non cambia.";
			$subject = 'Reimposta la password';
		} else {
			$text    = 'Ciao ' . $p['first_name'] . ",\n\nper entrare" . ( '' !== $assoc ? ' in ' . $assoc : '' ) . " apri questo link:\n\n" . $link
				. "\n\noppure scrivi questo codice nella pagina di accesso: " . $code
				. "\n\nValgono 15 minuti e si usano una volta sola. Se non hai chiesto tu questo messaggio, ignoralo: nessuno può entrare senza.";
			$subject = 'Il tuo accesso';
		}
		$to      = (string) $p['email'];
		$subject = $subject . ( '' !== $assoc ? ' — ' . $assoc : '' );
		if ( self::$defer ) { // dalla pagina pubblica: la richiesta si conta subito e l'email parte a risposta consegnata
			set_transient( $mail_key, $sent + 1, HOUR_IN_SECONDS );
			self::$queue[] = static function () use ( $to, $subject, $text ) {
				Texts::mail( $to, $subject, $text );
			};
			return true;
		}
		$ok = (bool) Texts::mail( $to, $subject, $text );
		if ( $ok ) {
			set_transient( $mail_key, $sent + 1, HOUR_IN_SECONDS );
		}
		return $ok;
	}

	/** Consuma la richiesta (una volta sola) e apre la sessione. @return int|null la persona entrata */
	private static function finish( array $row ): ?int {
		$db  = Db::db();
		$won = $db->query( $db->prepare( 'UPDATE ' . Db::t( 'member_challenges' ) . ' SET used_at = %s WHERE id = %d AND used_at IS NULL', self::now(), (int) $row['id'] ) );
		if ( 1 !== (int) $won ) {
			return null; // l'ha già usata qualcun altro nello stesso istante
		}
		$p = Plugin::people()->get( (int) $row['person_id'] );
		if ( ! MemberSession::person_allowed( $p ) ) {
			return null;
		}
		if ( 'reset' === (string) $row['kind'] ) {
			self::require_reset( (int) $p['id'] );
		}
		return self::open( (int) $p['id'], 'reset' === (string) $row['kind'] ? 'reset' : 'link' );
	}

	/** Apre la sessione di una persona già riconosciuta (ad esempio dopo l'attivazione dal link della segreteria). */
	public static function open( int $person_id, string $how, bool $remember = false ): int {
		MemberSession::destroy(); // il token cambia a ogni accesso
		MemberSession::start( $person_id, $remember );
		Db::db()->update( Db::t( 'member_auth' ), array( 'last_login_at' => self::now() ), array( 'person_id' => $person_id ) );
		Audit::log( 'member.login', 'person', $person_id, array( 'how' => $how ) );
		return $person_id;
	}

	/** Entra con il link ricevuto per email. */
	public static function login_with_token( string $raw ): ?int {
		if ( ! MemberSession::enabled() || ! SessionToken::is_well_formed( $raw ) ) {
			return null;
		}
		$db  = Db::db();
		$row = $db->get_row( $db->prepare( 'SELECT * FROM ' . Db::t( 'member_challenges' ) . ' WHERE token_hash = %s AND used_at IS NULL AND expires_at > %s', SessionToken::hash( $raw ), self::now() ), ARRAY_A );
		return $row ? self::finish( $row ) : null;
	}

	/** Entra con l'email e il codice a 6 cifre. Dopo 5 errori il codice non vale più. */
	public static function login_with_code( string $email, string $code ): ?int {
		if ( ! MemberSession::enabled() || 1 !== preg_match( '/^\d{6}$/', trim( $code ) ) ) {
			return null;
		}
		$p = self::person_by_email( $email );
		if ( ! $p ) {
			return null;
		}
		$fails_key = 'asem_ml_cf_' . (int) $p['id'];
		if ( (int) get_transient( $fails_key ) >= self::MAX_CODE_FAILS_PER_HOUR ) {
			return null; // troppi codici sbagliati per questa persona: anche chiedendone di nuovi non si ricomincia
		}
		$db  = Db::db();
		$tbl = Db::t( 'member_challenges' );
		$row = $db->get_row( $db->prepare( "SELECT * FROM $tbl WHERE person_id = %d AND used_at IS NULL AND expires_at > %s ORDER BY id DESC LIMIT 1", (int) $p['id'], self::now() ), ARRAY_A );
		if ( ! $row ) {
			return null;
		}
		// Il tentativo si conta e si controlla nello stesso colpo: richieste parallele non possono superare il limite.
		$counted = $db->query( $db->prepare( "UPDATE $tbl SET attempts = attempts + 1 WHERE id = %d AND attempts < %d", (int) $row['id'], self::MAX_ATTEMPTS ) );
		if ( 1 !== (int) $counted ) {
			return null;
		}
		if ( ! hash_equals( (string) $row['code_hash'], SessionToken::hash_code( $code, (int) $p['id'], MemberSession::secret() ) ) ) {
			set_transient( $fails_key, (int) get_transient( $fails_key ) + 1, HOUR_IN_SECONDS );
			return null;
		}
		delete_transient( $fails_key ); // codice giusto: il conto dei errori riparte
		return self::finish( $row );
	}

	/** Entra con email e password. Blocco progressivo per account dopo gli errori di fila. */
	public static function login_with_password( string $email, string $password, bool $remember = false ): ?int {
		if ( ! MemberSession::enabled() || strlen( $password ) > self::MAX_PASSWORD_BYTES ) {
			return null;
		}
		$db   = Db::db();
		$tbl  = Db::t( 'member_auth' );
		$p    = self::person_by_email( $email );
		$auth = $p ? $db->get_row( $db->prepare( "SELECT * FROM $tbl WHERE person_id = %d AND pass_hash IS NOT NULL", (int) $p['id'] ), ARRAY_A ) : null;
		if ( ! $auth ) {
			MemberPassword::verify( $password, self::dummy_hash() ); // un controllo vero su un'impronta finta: stesso tempo di una password sbagliata
			return null;
		}
		if ( ! empty( $auth['locked_until'] ) && strtotime( $auth['locked_until'] . ' UTC' ) > time() ) {
			return null;
		}
		$hash = (string) $auth['pass_hash'];
		$ok   = MemberPassword::verify( $password, $hash );
		if ( ! $ok ) {
			// Il conto sale con una sola istruzione: tentativi in parallelo non lo aggirano.
			$db->query( $db->prepare( "UPDATE $tbl SET failed_count = LEAST(failed_count + 1, 1000) WHERE person_id = %d", (int) $p['id'] ) );
			$failed = (int) $db->get_var( $db->prepare( "SELECT failed_count FROM $tbl WHERE person_id = %d", (int) $p['id'] ) );
			$lock   = MemberPassword::lock_seconds( $failed );
			if ( $lock > 0 ) {
				$db->update( $tbl, array( 'locked_until' => gmdate( 'Y-m-d H:i:s', time() + $lock ) ), array( 'person_id' => (int) $p['id'] ) );
			}
			return null;
		}
		$upd = array( 'failed_count' => 0, 'locked_until' => null, 'must_reset' => 0 ); // ha dimostrato di conoscere la password: un recupero lasciato a metà non la blocca
		if ( MemberPassword::needs_rehash( $hash ) ) {
			$upd['pass_hash'] = MemberPassword::make( $password );
		}
		$db->update( $tbl, $upd, array( 'person_id' => (int) $p['id'] ) );
		return self::open( (int) $p['id'], 'password', $remember );
	}

	/** Impronta finta (creata una volta sola) per spendere lo stesso tempo anche quando l'email non è di un socio. */
	private static function dummy_hash(): string {
		$h = (string) get_option( 'asem_ml_dummy', '' );
		if ( '' === $h ) {
			$h = MemberPassword::make( bin2hex( random_bytes( 16 ) ) );
			add_option( 'asem_ml_dummy', $h, '', false );
		}
		return $h;
	}

	/** La password data è quella attuale del socio? (serve per cambiarla: chi ha rubato una sessione non può cambiarla da solo). */
	public static function check_password( int $person_id, string $password ): bool {
		if ( strlen( $password ) > self::MAX_PASSWORD_BYTES ) {
			return false;
		}
		$hash = (string) Db::db()->get_var( Db::db()->prepare( 'SELECT pass_hash FROM ' . Db::t( 'member_auth' ) . ' WHERE person_id = %d', $person_id ) );
		return '' !== $hash && MemberPassword::verify( $password, $hash );
	}

	public static function has_password( int $person_id ): bool {
		return (bool) Db::db()->get_var( Db::db()->prepare( 'SELECT COUNT(*) FROM ' . Db::t( 'member_auth' ) . ' WHERE person_id = %d AND pass_hash IS NOT NULL', $person_id ) );
	}

	/** Sceglie o cambia la password del socio. @return string vuoto se fatto, altrimenti cosa non va */
	public static function set_password( int $person_id, string $password ): string {
		$problem = MemberPassword::problem( $password );
		if ( '' !== $problem ) {
			return $problem;
		}
		$p = Plugin::people()->get( $person_id );
		if ( ! MemberSession::person_allowed( $p ) || '' === (string) $p['email'] ) {
			return 'Non è possibile impostare la password per questo accesso.';
		}
		$db    = Db::db();
		$tbl   = Db::t( 'member_auth' );
		$email = strtolower( (string) $p['email'] );
		$taken = $db->get_var( $db->prepare( "SELECT person_id FROM $tbl WHERE login_email = %s AND person_id <> %d", $email, $person_id ) );
		if ( $taken ) {
			return 'Non è possibile impostare la password per questo accesso. Scrivi alla segreteria.';
		}
		$hash = MemberPassword::make( $password );
		if ( $db->get_var( $db->prepare( "SELECT COUNT(*) FROM $tbl WHERE person_id = %d", $person_id ) ) ) {
			$db->update( $tbl, array( 'login_email' => $email, 'pass_hash' => $hash, 'must_reset' => 0, 'failed_count' => 0, 'locked_until' => null ), array( 'person_id' => $person_id ) );
		} else {
			$db->insert( $tbl, array( 'person_id' => $person_id, 'login_email' => $email, 'pass_hash' => $hash, 'created_at' => self::now() ) );
		}
		MemberSession::destroy_others( $person_id ); // cambiata la password, gli altri dispositivi devono rientrare
		Audit::log( 'member.password_set', 'person', $person_id );
		return '';
	}
}
