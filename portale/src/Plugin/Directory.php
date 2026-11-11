<?php
namespace Portale\Plugin;

use Portale\Config;
use Portale\Db;

/**
 * Elenco centrale dello staff (docs/UTENTI_E_ACCESSI.md): una persona, un account, un livello per area.
 * Aree: WordPress, SaaS, Supporto. Livelli: admin · vendita · supporto (WordPress e SaaS); admin · agente (Supporto).
 *
 * Questo è il primo passo (solo aggiunta): le tabelle, la copia degli staff di oggi e le funzioni di lettura. Chi comanda gli accessi
 * resta `Plugin\Staff` e la tabella `staff` finché l'accesso unico non è in funzione; nessun permesso cambia.
 *
 * Super amministratore: l'indirizzo sta solo in `super_admins` di config.php (mai nel repository, nei test o nei log) ed è
 * admin di tutte le aree, sempre, senza righe nel database: non si può togliere né cambiare da interfaccia.
 */
final class Directory {

	const AREA_WORDPRESS = 'wordpress';
	const AREA_SAAS      = 'saas';
	const AREA_SUPPORT   = 'supporto';

	const ADMIN  = 'admin';
	const SALES  = 'vendita';
	const SUPPORT = 'supporto';
	const AGENT  = 'agente';

	/** Livelli ammessi per area, dal più alto al più basso. */
	const LEVELS = array(
		self::AREA_WORDPRESS => array( self::ADMIN, self::SALES, self::SUPPORT ),
		self::AREA_SAAS      => array( self::ADMIN, self::SALES, self::SUPPORT ),
		self::AREA_SUPPORT   => array( self::ADMIN, self::AGENT ),
	);

	const LABELS = array(
		self::ADMIN   => 'Amministratore',
		self::SALES   => 'Vendita',
		self::SUPPORT => 'Supporto',
		self::AGENT   => 'Agente',
	);

	public static function areas(): array {
		return array_keys( self::LEVELS );
	}

	/** Grado del livello nell'area (più alto = più potere); 0 se non esiste. */
	public static function rank( string $area, string $level ): int {
		$i = array_search( $level, self::LEVELS[ $area ] ?? array(), true );
		return false === $i ? 0 : count( self::LEVELS[ $area ] ) - (int) $i;
	}

	public static function isSuper( string $email ): bool {
		$email = strtolower( trim( $email ) );
		if ( '' === $email ) {
			return false;
		}
		foreach ( (array) Config::get( 'super_admins', array() ) as $a ) {
			if ( strtolower( trim( (string) $a ) ) === $email ) {
				return true;
			}
		}
		return false;
	}

	/** Identificativo stabile della persona, ricavato dall'indirizzo (la stessa email dà sempre lo stesso id). */
	public static function personId( string $email ): string {
		return substr( hash( 'sha256', 'staff|' . strtolower( trim( $email ) ) ), 0, 32 );
	}

	public static function person( Db $db, string $email ): ?array {
		$row = $db->one( 'SELECT * FROM staff_people WHERE email = ?', array( strtolower( trim( $email ) ) ) );
		return $row ?: null;
	}

	/** @return string|null livello della persona nell'area (null = nessun accesso; persona disattivata = nessun accesso) */
	public static function level( ?Db $db, string $email, string $area ): ?string {
		if ( ! isset( self::LEVELS[ $area ] ) ) {
			return null;
		}
		if ( self::isSuper( $email ) ) {
			return self::ADMIN;
		}
		$email = strtolower( trim( $email ) );
		if ( ! $db || '' === $email ) {
			return null;
		}
		$row = $db->one(
			'SELECT r.level FROM staff_roles r JOIN staff_people p ON p.id = r.person_id WHERE p.email = ? AND p.active = 1 AND r.area = ?',
			array( $email, $area )
		);
		return ( $row && in_array( $row['level'], self::LEVELS[ $area ], true ) ) ? (string) $row['level'] : null;
	}

	/** @return array<string,string> area → livello, solo le aree in cui la persona ha un ruolo */
	public static function levels( ?Db $db, string $email ): array {
		$out = array();
		foreach ( self::areas() as $area ) {
			$l = self::level( $db, $email, $area );
			if ( null !== $l ) {
				$out[ $area ] = $l;
			}
		}
		return $out;
	}

	/** Persone con un ruolo nell'area (la titolare, se configurata, compare sempre per prima e bloccata). */
	public static function people( Db $db, string $area ): array {
		$out = array();
		foreach ( (array) Config::get( 'super_admins', array() ) as $a ) {
			$a = strtolower( trim( (string) $a ) );
			if ( '' !== $a ) {
				$out[ $a ] = array( 'email' => $a, 'name' => '', 'level' => self::ADMIN, 'locked' => true, 'active' => true );
			}
		}
		foreach ( $db->all( 'SELECT p.email, p.name, p.active, r.level FROM staff_roles r JOIN staff_people p ON p.id = r.person_id WHERE r.area = ? ORDER BY p.email', array( $area ) ) as $r ) {
			if ( ! isset( $out[ $r['email'] ] ) ) {
				$out[ $r['email'] ] = array( 'email' => $r['email'], 'name' => (string) $r['name'], 'level' => (string) $r['level'], 'locked' => false, 'active' => (bool) $r['active'] );
			}
		}
		return array_values( $out );
	}

	/** Dà (o cambia) il livello a una persona in un'area, creandola se manca. Nessun controllo di chi agisce: li fa il chiamante. */
	public static function put( Db $db, string $email, string $area, string $level, string $by ): void {
		$email = strtolower( trim( $email ) );
		if ( '' === $email || ! in_array( $level, self::LEVELS[ $area ] ?? array(), true ) ) {
			return;
		}
		$id = self::personId( $email );
		if ( ! $db->one( 'SELECT 1 AS x FROM staff_people WHERE id = ?', array( $id ) ) ) {
			$db->run( 'INSERT INTO staff_people (id, email, name, active, created_at) VALUES (?,?,?,?,?)', array( $id, $email, '', 1, Db::now() ) );
		}
		if ( $db->one( 'SELECT 1 AS x FROM staff_roles WHERE person_id = ? AND area = ?', array( $id, $area ) ) ) {
			$db->run( 'UPDATE staff_roles SET level = ?, granted_by = ?, granted_at = ? WHERE person_id = ? AND area = ?', array( $level, $by, Db::now(), $id, $area ) );
		} else {
			$db->run( 'INSERT INTO staff_roles (person_id, area, level, granted_by, granted_at) VALUES (?,?,?,?,?)', array( $id, $area, $level, $by, Db::now() ) );
		}
	}

	/** Registro delle azioni: si aggiunge soltanto, non c'è modo di cambiare o togliere righe. */
	public static function audit( Db $db, string $actor, string $target, string $action, string $detail = '', string $ip = '' ): void {
		$db->run(
			'INSERT INTO staff_audit (id, at, actor, target, action, detail, ip) VALUES (?,?,?,?,?,?,?)',
			array( Db::id(), Db::now(), strtolower( trim( $actor ) ), strtolower( trim( $target ) ), $action, mb_substr( $detail, 0, 500 ), mb_substr( $ip, 0, 45 ) )
		);
	}

	/**
	 * Primo tempo della migrazione: copia gli staff di oggi (`plugin_staff`, colonna `saas` di `staff`, `admins` di config.php) nelle
	 * tabelle nuove. Si può ripetere: aggiunge solo ciò che manca e non sovrascrive mai ciò che è già stato cambiato qui.
	 * Le tabelle vecchie non si toccano.
	 */
	public static function importLegacy( Db $db ): void {
		if ( $db->one( "SELECT 1 AS x FROM staff_audit WHERE action = 'cutover'" ) ) {
			return; // da quando l'accesso unico comanda, la copia dalle tabelle vecchie non si ripete più
		}
		$map = array( 'admin' => self::ADMIN, 'support' => self::SUPPORT );
		foreach ( $db->all( 'SELECT email, role, created_at, totp_secret_enc, mfa_at FROM plugin_staff' ) as $r ) {
			if ( ! isset( $map[ $r['role'] ] ) ) {
				continue;
			}
			self::ensure( $db, (string) $r['email'], (string) $r['created_at'], $r['totp_secret_enc'], $r['mfa_at'] );
			self::seed( $db, (string) $r['email'], self::AREA_WORDPRESS, $map[ $r['role'] ] );
		}
		foreach ( $db->all( "SELECT email, saas, created_at FROM staff WHERE saas <> ''" ) as $r ) {
			if ( ! isset( $map[ $r['saas'] ] ) ) {
				continue;
			}
			self::ensure( $db, (string) $r['email'], (string) $r['created_at'], null, null );
			self::seed( $db, (string) $r['email'], self::AREA_SAAS, $map[ $r['saas'] ] );
		}
		foreach ( (array) Config::get( 'admins', array() ) as $a ) {
			$a = strtolower( trim( (string) $a ) );
			if ( '' === $a ) {
				continue;
			}
			self::ensure( $db, $a, Db::now(), null, null );
			self::seed( $db, $a, self::AREA_WORDPRESS, self::ADMIN );
			self::seed( $db, $a, self::AREA_SAAS, self::ADMIN );
		}
	}

	private static function ensure( Db $db, string $email, string $createdAt, $totp, $mfaAt ): void {
		$email = strtolower( trim( $email ) );
		$id    = self::personId( $email );
		if ( ! $db->one( 'SELECT 1 AS x FROM staff_people WHERE id = ?', array( $id ) ) ) {
			$db->run( 'INSERT INTO staff_people (id, email, name, active, totp_secret_enc, mfa_at, created_at) VALUES (?,?,?,?,?,?,?)', array( $id, $email, '', 1, $totp, $mfaAt, '' !== $createdAt ? $createdAt : Db::now() ) );
		}
	}

	private static function seed( Db $db, string $email, string $area, string $level ): void {
		$id = self::personId( $email );
		if ( ! $db->one( 'SELECT 1 AS x FROM staff_roles WHERE person_id = ? AND area = ?', array( $id, $area ) ) ) {
			$db->run( 'INSERT INTO staff_roles (person_id, area, level, granted_by, granted_at) VALUES (?,?,?,?,?)', array( $id, $area, $level, 'migrazione', Db::now() ) );
		}
	}
}
