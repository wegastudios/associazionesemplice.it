<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Regole di «Ruoli e accessi» (parte pura, si prova con i test unitari): a chi non si può togliere l'accesso operativo.
 *
 * - Un amministratore di WordPress (ruolo «administrator») non si toglie e non si limita mai dal plugin.
 * - Un amministratore dell'associazione (ruolo del plugin con il permesso di amministrare, che NON è «administrator») può essere tolto
 *   o limitato da un altro amministratore, anche se è nuovo.
 * - Nessuno può togliere l'accesso al ruolo con cui sta operando: se lo toglierebbe da solo.
 */
final class RoleAccessRules {

	const ROLE_WP_ADMIN = 'administrator';

	const REFUSE_WP_ADMIN = 'wp_admin';
	const REFUSE_OWN      = 'own';

	/**
	 * Perché l'accesso operativo di un ruolo NON si può togliere (null = si può).
	 *
	 * @param string   $role       il ruolo a cui si vuole togliere l'accesso
	 * @param string[] $user_roles i ruoli di chi sta facendo la modifica
	 */
	public static function refuse_removal( string $role, array $user_roles ): ?string {
		if ( self::ROLE_WP_ADMIN === $role ) {
			return self::REFUSE_WP_ADMIN;
		}
		return in_array( $role, $user_roles, true ) ? self::REFUSE_OWN : null;
	}
}
