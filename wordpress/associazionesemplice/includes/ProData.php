<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Dati delle funzioni aggiuntive nei punti di estensione generici di Associazione Semplice: esportazione e anonimizzazione della privacy,
 * eliminazione di eventi e iscrizioni, pagamenti online in corso.
 */
final class ProData {

	public static function register(): void {
		add_filter( 'asem_privacy_export', array( __CLASS__, 'export' ), 10, 2 );
		add_action( 'asem_person_anonymized', array( __CLASS__, 'anonymize' ), 10, 3 );
		add_filter( 'asem_online_payment_pending', array( __CLASS__, 'online_pending' ), 10, 3 );
		add_filter( 'asem_activity_delete_blockers', array( __CLASS__, 'delete_blockers' ), 10, 2 );
		add_action( 'asem_activity_deleted', array( __CLASS__, 'activity_deleted' ) );
		add_action( 'asem_registration_deleted', array( __CLASS__, 'registration_deleted' ), 10, 2 );
	}

	/** Assicurazioni e presenze della persona nell'esportazione dei suoi dati. */
	public static function export( array $data, int $person_id ): array {
		$db = Db::db();
		$q  = function ( string $sql, array $args ) use ( $db ) {
			return $db->get_results( $db->prepare( $sql, $args ), ARRAY_A ) ?: array();
		};
		$data['assicurazioni'] = $q( 'SELECT company AS compagnia, policy_no AS numero, valid_from AS dal, valid_to AS al FROM ' . Db::t( 'insurance' ) . ' WHERE person_id = %d ORDER BY valid_from', array( $person_id ) );
		$data['presenze']      = $q( 'SELECT a.name AS attivita, t.lesson_date AS data, t.present AS presente FROM ' . Db::t( 'attendance' ) . ' t JOIN ' . Db::t( 'activities' ) . ' a ON a.id = t.activity_id WHERE t.person_id = %d ORDER BY t.lesson_date', array( $person_id ) );
		return $data;
	}

	/** Anonimizzazione: polizze, destinatari delle comunicazioni, dispositivi delle notifiche e nome nelle voci dei pagamenti online. */
	public static function anonymize( int $person_id, int $uid, string $name = '' ): void {
		$db = Db::db();
		$db->delete( Db::t( 'insurance' ), array( 'person_id' => $person_id ) ); // numeri di polizza e note
		$db->update( Db::t( 'broadcast_rcpt' ), array( 'email' => '', 'name' => 'Persona anonimizzata' ), array( 'person_id' => $person_id ) ); // destinatari delle comunicazioni
		if ( $uid > 0 ) {
			$db->delete( Db::t( 'push_subs' ), array( 'user_id' => $uid ) ); // dispositivi con le notifiche
		}
		// pagamenti online: il nome scritto in ogni voce
		$pay = Db::t( 'payments' );
		foreach ( $db->get_results( $db->prepare( "SELECT id, items FROM $pay WHERE payer_person_id = %d OR items LIKE %s", $person_id, '%"person_id":' . $person_id . '%' ), ARRAY_A ) ?: array() as $r ) {
			$items = json_decode( (string) $r['items'], true );
			if ( ! is_array( $items ) ) {
				continue;
			}
			$changed = false;
			foreach ( $items as $k => $i ) {
				if ( is_array( $i ) && (int) ( $i['person_id'] ?? 0 ) === $person_id && isset( $i['person_name'] ) ) {
					$items[ $k ]['person_name'] = 'Persona anonimizzata';
					$changed                    = true;
				}
			}
			if ( $changed ) {
				$db->update( $pay, array( 'items' => wp_json_encode( $items ) ), array( 'id' => (int) $r['id'] ) );
			}
		}
	}

	/** Ha un pagamento online avviato da poco per questa prenotazione? */
	public static function online_pending( $pending, int $person_id, int $session_id ) {
		if ( $pending ) {
			return $pending;
		}
		$db    = Db::db();
		$since = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		foreach ( $db->get_results( $db->prepare( 'SELECT items FROM ' . Db::t( 'payments' ) . " WHERE payer_person_id = %d AND status IN ('created','pending') AND updated_at >= %s", $person_id, $since ), ARRAY_A ) ?: array() as $p ) {
			foreach ( (array) json_decode( (string) $p['items'], true ) as $it ) {
				if ( (int) ( $it['session_id'] ?? 0 ) === $session_id ) {
					return true;
				}
			}
		}
		return false;
	}

	/** Un pagamento online in corso per l'evento ne impedisce l'eliminazione. */
	public static function delete_blockers( array $out, int $activity_id ): array {
		$db    = Db::db();
		$since = gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS );
		foreach ( $db->get_results( $db->prepare( 'SELECT id, items FROM ' . Db::t( 'payments' ) . " WHERE status IN ('created','pending') AND updated_at >= %s", $since ), ARRAY_A ) ?: array() as $pay ) {
			foreach ( (array) json_decode( (string) $pay['items'], true ) as $it ) {
				if ( (int) ( $it['activity_id'] ?? 0 ) === $activity_id ) {
					$out[] = 'C\'è un pagamento online in corso per questo evento: attendi che si concluda (o scada) prima di eliminarlo.';
					break 2;
				}
			}
		}
		return $out;
	}

	/** Eliminato un evento: presenze e collegamenti con WooCommerce. */
	public static function activity_deleted( int $activity_id ): void {
		$db = Db::db();
		$db->delete( Db::t( 'attendance' ), array( 'activity_id' => $activity_id ) );
		$db->delete( Db::t( 'woo_links' ), array( 'kind' => 'activity', 'ref_id' => $activity_id ) );
	}

	/** Cancellata un'iscrizione a un corso: le sue presenze. */
	public static function registration_deleted( int $activity_id, int $person_id ): void {
		Db::db()->delete( Db::t( 'attendance' ), array( 'activity_id' => $activity_id, 'person_id' => $person_id ) );
	}
}
