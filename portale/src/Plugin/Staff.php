<?php
namespace Portale\Plugin;

use Portale\Db;

/**
 * Staff dell'amministrazione del plugin (`/wordpress/admin`): tabella propria `plugin_staff`, separata dal SaaS
 * (docs/ARCHITETTURA_DOMINI.md §3.2 e §8). Migrazione in due tempi: la tabella vecchia `staff` resta come ripiego in lettura
 * finché tutto non è verificato in produzione; poi si toglie. Le colonne `totp_secret_enc` e `mfa_at` sono per la verifica in due
 * passaggi obbligatoria (passo successivo).
 */
final class Staff {

	const ADMIN   = 'admin';
	const SUPPORT = 'support';

	const LABELS = array( self::ADMIN => 'Amministratore', self::SUPPORT => 'Solo supporto' );

	/** @return string|null admin, support oppure null senza accesso */
	public static function role( ?Db $db, string $email ): ?string {
		$email = strtolower( trim( $email ) );
		if ( '' === $email ) {
			return null;
		}
		if ( \Portale\Staff::isConfigAdmin( $email ) ) {
			return self::ADMIN;
		}
		if ( ! $db ) {
			return null;
		}
		$row = $db->one( 'SELECT role FROM plugin_staff WHERE email = ?', array( $email ) );
		if ( $row ) {
			return isset( self::LABELS[ $row['role'] ] ) ? (string) $row['role'] : null;
		}
		// Primo tempo della migrazione: chi non è ancora nella tabella nuova si legge dalla vecchia.
		$old = $db->one( 'SELECT role FROM staff WHERE email = ?', array( $email ) );
		return ( $old && isset( self::LABELS[ $old['role'] ] ) ) ? (string) $old['role'] : null;
	}

	/** Crea, cambia o toglie (ruolo non valido, p.es. «none») l'accesso al plugin. */
	public static function put( Db $db, string $email, string $role ): void {
		$email = strtolower( trim( $email ) );
		if ( ! isset( self::LABELS[ $role ] ) ) {
			$db->run( 'DELETE FROM plugin_staff WHERE email = ?', array( $email ) );
			return;
		}
		if ( $db->one( 'SELECT 1 AS x FROM plugin_staff WHERE email = ?', array( $email ) ) ) {
			$db->run( 'UPDATE plugin_staff SET role = ? WHERE email = ?', array( $role, $email ) );
		} else {
			$db->run( 'INSERT INTO plugin_staff (email, role, created_at) VALUES (?,?,?)', array( $email, $role, Db::now() ) );
		}
	}

	public static function remove( Db $db, string $email ): void {
		$db->run( 'DELETE FROM plugin_staff WHERE email = ?', array( strtolower( trim( $email ) ) ) );
	}
}
