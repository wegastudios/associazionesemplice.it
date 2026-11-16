<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/** Regole di «Ruoli e accessi» (parte pura, si prova con i test unitari): a chi non si può togliere l'accesso operativo. */
final class RoleAccessRules {

	const REFUSE_MANAGER = 'manager';
	const REFUSE_OWN     = 'own';

	/**
	 * Perché l'accesso operativo di un ruolo NON si può togliere (null = si può).
	 *
	 * @param bool     $role_manages il ruolo ha anche il permesso di amministrare il plugin
	 * @param string[] $user_roles   i ruoli di chi sta facendo la modifica
	 */
	public static function refuse_removal( string $role, bool $role_manages, array $user_roles ): ?string {
		if ( $role_manages ) {
			return self::REFUSE_MANAGER;
		}
		return in_array( $role, $user_roles, true ) ? self::REFUSE_OWN : null;
	}
}
