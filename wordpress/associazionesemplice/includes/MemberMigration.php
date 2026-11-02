<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Riepilogo per il passaggio all'accesso proprio dei soci: quanti utenti WordPress si possono spostare, quanti restano utenti WordPress
 * perché lavorano nell'amministrazione, quanti hanno già l'accesso proprio. Sola lettura: non cambia niente.
 */
final class MemberMigration {

	const MIGRATE          = 'migrate';          // solo socio, con email: si può spostare
	const MIGRATE_NO_EMAIL = 'migrate_no_email'; // solo socio, senza email: spostabile ma non può ricevere il link
	const OPERATOR         = 'operator';         // segreteria, consiglio, tesoriere, staff o altri ruoli: resta utente WordPress
	const NOT_ALLOWED      = 'not_allowed';      // la scheda non può accedere (ospite, domanda in attesa…): non si sposta

	/**
	 * Classifica un utente WordPress collegato a una scheda. Funzione pura.
	 *
	 * @param array $f roles (ruoli dell'utente), power (ha poteri: segreteria, consiglio, tesoriere o staff dell'ente), email, allowed (la scheda può accedere)
	 */
	public static function classify( array $f ): string {
		if ( empty( $f['allowed'] ) ) {
			return self::NOT_ALLOWED;
		}
		$extra = array_diff( (array) ( $f['roles'] ?? array() ), array( Plugin::ROLE_MEMBER, 'subscriber' ) );
		if ( $extra || ! empty( $f['power'] ) ) {
			return self::OPERATOR;
		}
		return '' === trim( (string) ( $f['email'] ?? '' ) ) ? self::MIGRATE_NO_EMAIL : self::MIGRATE;
	}

	/** @return array{linked:int,migrate:int,migrate_no_email:int,operator:int,not_allowed:int,own:int,no_user:int,examples:array} */
	public static function recap(): array {
		$db  = Db::db();
		$out = array( 'linked' => 0, self::MIGRATE => 0, self::MIGRATE_NO_EMAIL => 0, self::OPERATOR => 0, self::NOT_ALLOWED => 0, 'own' => 0, 'no_user' => 0, 'examples' => array() );
		$t   = Db::t( 'people' );
		$last = 0;
		do { // a gruppi, per non caricare tutto in memoria
			$rows = (array) $db->get_results( $db->prepare( "SELECT * FROM $t WHERE deleted_at IS NULL AND wp_user_id IS NOT NULL AND id > %d ORDER BY id LIMIT 500", $last ), ARRAY_A );
			foreach ( $rows as $p ) {
				$last = (int) $p['id'];
				$u    = get_userdata( (int) $p['wp_user_id'] );
				if ( ! $u ) {
					continue; // collegamento a un utente che non c'è più
				}
				++$out['linked'];
				$uid   = (int) $u->ID;
				$class = self::classify(
					array(
						'roles'   => (array) $u->roles,
						'power'   => user_can( $u, Plugin::CAP_OPS ) || Access::is_board_operator( $uid ) || Access::is_treasurer( $uid ) || Access::is_entity_staff( $uid ),
						'email'   => (string) $p['email'],
						'allowed' => MemberSession::person_allowed( $p ),
					)
				);
				++$out[ $class ];
				if ( self::MIGRATE_NO_EMAIL === $class && count( $out['examples'] ) < 10 ) {
					$out['examples'][] = trim( $p['first_name'] . ' ' . $p['last_name'] );
				}
			}
		} while ( count( $rows ) === 500 );
		$auth = Db::t( 'member_auth' );
		$out['own']     = (int) $db->get_var( "SELECT COUNT(*) FROM $auth a JOIN $t p ON p.id = a.person_id WHERE p.deleted_at IS NULL AND p.wp_user_id IS NULL" );
		foreach ( (array) $db->get_results( "SELECT type, COUNT(*) AS n FROM $t WHERE deleted_at IS NULL AND wp_user_id IS NULL AND (application_status IS NULL OR application_status = '') GROUP BY type", ARRAY_A ) as $r ) {
			if ( MemberType::can_login( (string) $r['type'] ) ) {
				$out['no_user'] += (int) $r['n'];
			}
		}
		return $out;
	}
}
