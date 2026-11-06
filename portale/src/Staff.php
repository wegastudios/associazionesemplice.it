<?php
namespace Portale;

/**
 * Staff del portale, con permessi separati per area:
 * - plugin (gestione del plugin: licenze, prezzi, versioni, impostazioni): admin, support oppure none;
 * - SaaS (gestione della piattaforma SaaS in /saas): admin, support oppure nessun accesso.
 * Le due aree si danno a persone diverse; chi le ha entrambe passa dall'una all'altra dal menu.
 * Entrano come gli altri utenti (link per email o password): i permessi si leggono ogni volta dall'email.
 */
final class Staff {

	const ADMIN   = 'admin';
	const SUPPORT = 'support';
	const NONE    = 'none';

	/** Ruoli nell'area del plugin (none = nessun accesso). */
	const LABELS = array( self::ADMIN => 'Amministratore', self::SUPPORT => 'Solo supporto' );

	/** Livelli nell'area SaaS ('' = nessun accesso). */
	const SAAS_LABELS = array( self::ADMIN => 'Amministratore', self::SUPPORT => 'Solo supporto' );

	/** @return string|null admin, support oppure null se l'indirizzo non ha accesso alla gestione del plugin */
	public static function role( ?Db $db, string $email ): ?string {
		$email = strtolower( trim( $email ) );
		if ( '' === $email ) {
			return null;
		}
		if ( self::isConfigAdmin( $email ) ) {
			return self::ADMIN;
		}
		if ( $db ) {
			$row = $db->one( 'SELECT role FROM staff WHERE email = ?', array( $email ) );
			if ( $row && isset( self::LABELS[ $row['role'] ] ) ) {
				return (string) $row['role'];
			}
		}
		return null;
	}

	/** @return string admin, support oppure '' se l'indirizzo non ha accesso alla gestione del SaaS */
	public static function saas( ?Db $db, string $email ): string {
		$email = strtolower( trim( $email ) );
		if ( '' === $email ) {
			return '';
		}
		if ( self::isConfigAdmin( $email ) ) {
			return self::ADMIN;
		}
		if ( $db ) {
			$row = $db->one( 'SELECT saas FROM staff WHERE email = ?', array( $email ) );
			if ( $row && isset( self::SAAS_LABELS[ (string) $row['saas'] ] ) ) {
				return (string) $row['saas'];
			}
		}
		return '';
	}

	private static function isConfigAdmin( string $email ): bool {
		foreach ( (array) Config::get( 'admins', array() ) as $a ) {
			if ( strtolower( trim( (string) $a ) ) === $email ) {
				return true;
			}
		}
		return false;
	}

	/** Elenco unico: prima gli admin di config.php (non modificabili), poi quelli del database. */
	public static function all( Db $db ): array {
		$out = array();
		foreach ( (array) Config::get( 'admins', array() ) as $a ) {
			$a = strtolower( trim( (string) $a ) );
			if ( '' !== $a ) {
				$out[ $a ] = array( 'email' => $a, 'role' => self::ADMIN, 'saas' => self::ADMIN, 'locked' => true, 'created_at' => '' );
			}
		}
		foreach ( $db->all( 'SELECT email, role, saas, created_at FROM staff ORDER BY email' ) as $r ) {
			if ( ! isset( $out[ $r['email'] ] ) ) {
				$out[ $r['email'] ] = array( 'email' => $r['email'], 'role' => $r['role'], 'saas' => (string) $r['saas'], 'locked' => false, 'created_at' => $r['created_at'] );
			}
		}
		return array_values( $out );
	}

	/**
	 * Aggiunge o cambia i permessi. Crea anche il cliente con quell'email, così la persona può entrare dal link per email.
	 * $saas = null lascia invariato il livello SaaS (per chi è nuovo: nessun accesso).
	 * @throws UserError
	 */
	public static function set( Db $db, Billing $billing, string $email, string $role, ?string $saas = null ): string {
		$email = strtolower( trim( $email ) );
		if ( ! isset( self::LABELS[ $role ] ) && self::NONE !== $role ) {
			throw new UserError( 'Ruolo non valido.' );
		}
		if ( null !== $saas && '' !== $saas && ! isset( self::SAAS_LABELS[ $saas ] ) ) {
			throw new UserError( 'Livello SaaS non valido.' );
		}
		$billing->customer( $email ); // valida l'indirizzo e crea il cliente se manca
		if ( self::isConfigAdmin( $email ) ) {
			throw new UserError( 'Questo indirizzo è amministratore da config.php: si cambia solo lì.' );
		}
		$cur = $db->one( 'SELECT saas FROM staff WHERE email = ?', array( $email ) );
		$new = null === $saas ? ( $cur ? (string) $cur['saas'] : '' ) : $saas;
		if ( self::NONE === $role && '' === $new ) {
			throw new UserError( 'Serve almeno un accesso: per toglierli entrambi usa «Togli».' );
		}
		if ( $cur ) {
			$db->run( 'UPDATE staff SET role = ?, saas = ? WHERE email = ?', array( $role, $new, $email ) );
		} else {
			$db->run( 'INSERT INTO staff (email, role, saas, created_at) VALUES (?,?,?,?)', array( $email, $role, $new, Db::now() ) );
		}
		return $email;
	}

	/** @throws UserError */
	public static function remove( Db $db, string $email ): void {
		$email = strtolower( trim( $email ) );
		if ( $db->one( 'SELECT 1 AS x FROM staff WHERE email = ?', array( $email ) ) ) {
			$db->run( 'DELETE FROM staff WHERE email = ?', array( $email ) );
			return;
		}
		throw new UserError( 'Indirizzo non presente nello staff (o amministratore da config.php).' );
	}
}
