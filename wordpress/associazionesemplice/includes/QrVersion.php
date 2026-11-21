<?php
/**
 * Versione del codice QR di ciascun socio (tessera, biglietti degli eventi, ingresso alle risorse).
 * Parte da 0: i QR già stampati valgono finché il socio non rigenera il codice; nessuna scadenza.
 *
 * @package AssociazioneSemplice
 */

namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

final class QrVersion {

	/** @var array<int,int> versioni lette in questa richiesta */
	private static $cache = array();

	public static function of( int $person_id ): int {
		if ( $person_id <= 0 ) {
			return 0;
		}
		if ( ! isset( self::$cache[ $person_id ] ) ) {
			$db                         = Db::db();
			self::$cache[ $person_id ] = (int) $db->get_var( $db->prepare( 'SELECT qr_version FROM ' . Db::t( 'people' ) . ' WHERE id = %d', $person_id ) );
		}
		return self::$cache[ $person_id ];
	}

	/** Dimentica la versione letta in questa richiesta (dopo una scrittura fatta altrove). */
	public static function forget( int $person_id ): void {
		unset( self::$cache[ $person_id ] );
	}

	/**
	 * Rigenera il codice di un socio: quello attuale smette subito di valere (tessera, biglietti, ingresso risorse).
	 *
	 * @param int $person_id scheda del socio
	 * @param int $actor_id  chi lo chiede (il socio stesso o la segreteria), per il registro
	 * @throws \InvalidArgumentException
	 */
	public static function regenerate( int $person_id, int $actor_id ): void {
		if ( ! Plugin::people()->get( $person_id ) ) {
			throw new \InvalidArgumentException( 'Persona non trovata.' );
		}
		MemberThrottle::hit( 'qr', $person_id, 'member_qr_per_10min' );
		$db = Db::db();
		$db->query( $db->prepare( 'UPDATE ' . Db::t( 'people' ) . ' SET qr_version = LEAST(qr_version + 1, 65000) WHERE id = %d', $person_id ) );
		unset( self::$cache[ $person_id ] );
		Audit::log( 'person.qr_regenerated', 'person', $person_id, array( 'by' => $actor_id ) );
	}
}
