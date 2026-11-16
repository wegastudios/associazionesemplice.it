<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Regole (parte pura, si prova con i test unitari) per la pulizia dei ruoli di WordPress altrui.
 *
 * Il plugin dà e toglie permessi solo al ruolo «Amministratore» e ai suoi tre ruoli (Socio, Segreteria, Dipendente).
 * Qualunque altro ruolo (Editore, Autore, Collaboratore, ruoli di altri plugin) non li ha: chi entra nel pannello lo fa con un ruolo interno,
 * o come presidente o vicepresidente.
 */
final class WpRoleCleanup {

	/** Ruoli su cui il plugin lavora: l'amministratore e i tre ruoli creati dal plugin. */
	const OWN_ROLES = array( 'administrator', Plugin::ROLE_MEMBER, Plugin::ROLE_SECRETARY, Plugin::ROLE_EMPLOYEE );

	/** Ruoli che danno l'ingresso nel pannello. */
	const ENTRY_ROLES = array( 'administrator', Plugin::ROLE_SECRETARY, Plugin::ROLE_EMPLOYEE );

	/** @return string[] i permessi che un ruolo altrui non deve avere */
	public static function caps(): array {
		return array( Plugin::CAP_OPS, Plugin::CAP );
	}

	/**
	 * Quali ruoli vanno ripuliti: quelli che non sono del plugin e hanno uno dei suoi permessi.
	 *
	 * @param array<string,array<string,mixed>> $roles ruolo => permessi del ruolo
	 * @return string[] chiavi dei ruoli da ripulire
	 */
	public static function roles_to_clean( array $roles ): array {
		$out = array();
		foreach ( $roles as $key => $caps ) {
			if ( in_array( (string) $key, self::OWN_ROLES, true ) || ! is_array( $caps ) ) {
				continue;
			}
			foreach ( self::caps() as $cap ) {
				if ( array_key_exists( $cap, $caps ) ) {
					$out[] = (string) $key;
					break;
				}
			}
		}
		return $out;
	}

	/**
	 * L'utente entrava nel pannello solo per uno dei ruoli ripuliti? (non è amministratore, non ha un ruolo interno, non è presidente o vicepresidente)
	 *
	 * @param string[] $user_roles ruoli dell'utente
	 * @param string[] $cleaned    ruoli ripuliti
	 * @param bool     $board      è presidente o vicepresidente in regola
	 */
	public static function entered_only_by_cleaned_role( array $user_roles, array $cleaned, bool $board ): bool {
		if ( $board || array_intersect( $user_roles, self::ENTRY_ROLES ) ) {
			return false;
		}
		return (bool) array_intersect( $user_roles, $cleaned );
	}
}
