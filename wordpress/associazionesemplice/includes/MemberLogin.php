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

	/** Manda link e codice se l'email è di un socio. @return bool true se è partita una email (da non mostrare a chi chiede) */
	public static function request( string $email ): bool {
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
		$db->delete( $tbl, array( 'person_id' => $pid, 'kind' => 'login' ) ); // vale solo l'ultimo
		$raw  = SessionToken::generate();
		$code = SessionToken::code();
		$db->insert(
			$tbl,
			array(
				'person_id'  => $pid,
				'kind'       => 'login',
				'token_hash' => SessionToken::hash( $raw ),
				'code_hash'  => SessionToken::hash_code( $code, $pid, MemberSession::secret() ),
				'created_at' => self::now(),
				'expires_at' => gmdate( 'Y-m-d H:i:s', time() + self::TTL_SECONDS ),
			)
		);
		$assoc = (string) Settings::get( 'association_name' );
		$link  = add_query_arg( 'asem_ml', $raw, home_url( '/' ) );
		$text  = 'Ciao ' . $p['first_name'] . ",\n\nper entrare" . ( '' !== $assoc ? ' in ' . $assoc : '' ) . " apri questo link:\n\n" . $link
			. "\n\noppure scrivi questo codice nella pagina di accesso: " . $code
			. "\n\nValgono 15 minuti e si usano una volta sola. Se non hai chiesto tu questo messaggio, ignoralo: nessuno può entrare senza.";
		$ok = (bool) Texts::mail( (string) $p['email'], 'Il tuo accesso' . ( '' !== $assoc ? ' — ' . $assoc : '' ), $text );
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
		return self::open( (int) $p['id'], 'link' );
	}

	/** Apre la sessione di una persona già riconosciuta (ad esempio dopo l'attivazione dal link della segreteria). */
	public static function open( int $person_id, string $how ): int {
		MemberSession::destroy(); // il token cambia a ogni accesso
		MemberSession::start( $person_id );
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
		$db  = Db::db();
		$tbl = Db::t( 'member_challenges' );
		$row = $db->get_row( $db->prepare( "SELECT * FROM $tbl WHERE person_id = %d AND kind = 'login' AND used_at IS NULL AND expires_at > %s ORDER BY id DESC LIMIT 1", (int) $p['id'], self::now() ), ARRAY_A );
		if ( ! $row || (int) $row['attempts'] >= self::MAX_ATTEMPTS ) {
			return null;
		}
		$db->query( $db->prepare( "UPDATE $tbl SET attempts = attempts + 1 WHERE id = %d", (int) $row['id'] ) ); // prima si conta, poi si controlla
		if ( ! hash_equals( (string) $row['code_hash'], SessionToken::hash_code( $code, (int) $p['id'], MemberSession::secret() ) ) ) {
			return null;
		}
		return self::finish( $row );
	}

	/** Entra con email e password. Blocco progressivo per account dopo gli errori di fila. */
	public static function login_with_password( string $email, string $password ): ?int {
		if ( ! MemberSession::enabled() ) {
			return null;
		}
		$db   = Db::db();
		$tbl  = Db::t( 'member_auth' );
		$p    = self::person_by_email( $email );
		$auth = $p ? $db->get_row( $db->prepare( "SELECT * FROM $tbl WHERE person_id = %d AND pass_hash IS NOT NULL", (int) $p['id'] ), ARRAY_A ) : null;
		if ( ! $auth ) {
			MemberPassword::verify( $password, MemberPassword::make( 'x' ) ); // stesso tempo di una password sbagliata
			return null;
		}
		if ( ! empty( $auth['locked_until'] ) && strtotime( $auth['locked_until'] . ' UTC' ) > time() ) {
			return null;
		}
		$hash = (string) $auth['pass_hash'];
		$ok   = MemberPassword::verify( $password, $hash );
		if ( ! $ok ) {
			$failed = (int) $auth['failed_count'] + 1;
			$lock   = MemberPassword::lock_seconds( $failed );
			$db->update( $tbl, array( 'failed_count' => $failed, 'locked_until' => $lock ? gmdate( 'Y-m-d H:i:s', time() + $lock ) : null ), array( 'person_id' => (int) $p['id'] ) );
			return null;
		}
		$upd = array( 'failed_count' => 0, 'locked_until' => null );
		if ( MemberPassword::needs_rehash( $hash ) ) {
			$upd['pass_hash'] = MemberPassword::make( $password );
		}
		$db->update( $tbl, $upd, array( 'person_id' => (int) $p['id'] ) );
		return self::open( (int) $p['id'], 'password' );
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
			$db->update( $tbl, array( 'login_email' => $email, 'pass_hash' => $hash, 'failed_count' => 0, 'locked_until' => null ), array( 'person_id' => $person_id ) );
		} else {
			$db->insert( $tbl, array( 'person_id' => $person_id, 'login_email' => $email, 'pass_hash' => $hash, 'created_at' => self::now() ) );
		}
		Audit::log( 'member.password_set', 'person', $person_id );
		return '';
	}
}
