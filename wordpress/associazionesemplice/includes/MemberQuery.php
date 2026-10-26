<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Elenchi di soci per i certificati medici e le affiliazioni. Il tutore (`guardian`) e l'ospite non sono soci: restano fuori.
 */
final class MemberQuery {

	/** Condizione sulle persone `p`: socio vero (non tutore né ospite), non eliminato, non anonimizzato, non uscito, non sospeso, adesione già approvata. */
	private static function where(): string {
		$types = "'" . implode( "','", array_map( 'esc_sql', MemberType::member_types() ) ) . "'";
		return "p.deleted_at IS NULL AND p.anonymized_at IS NULL AND p.left_on IS NULL AND p.suspended_at IS NULL AND p.application_status IS NULL AND p.type IN ($types)";
	}

	/** Tutti i soci attivi, in ordine alfabetico. @return array[] righe di `people` */
	public static function active(): array {
		$db = Db::db();
		return $db->get_results( 'SELECT p.* FROM ' . Db::t( 'people' ) . ' p WHERE ' . self::where() . ' ORDER BY p.last_name, p.first_name, p.id', ARRAY_A ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- nessun dato esterno: solo nomi di tabella e tipi fissi
	}

	/** Un socio attivo (null se non lo è o non esiste). */
	public static function find( int $person_id ): ?array {
		$db  = Db::db();
		$row = $db->get_row( $db->prepare( 'SELECT p.* FROM ' . Db::t( 'people' ) . ' p WHERE p.id = %d AND ' . self::where(), $person_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- parte fissa, il solo dato è l'id
		return $row ?: null;
	}

	/** Soci attivi iscritti a un'attività (corso: iscrizione in corso; evento: prenotazione attiva a una data non annullata). @return array[] */
	public static function enrolled_in( int $activity_id ): array {
		$db   = Db::db();
		$kind = (string) $db->get_var( $db->prepare( 'SELECT kind FROM ' . Db::t( 'activities' ) . ' WHERE id = %d AND deleted_at IS NULL', $activity_id ) );
		if ( '' === $kind ) {
			return array();
		}
		if ( ActivityKind::uses_sessions( $kind ) ) {
			$sql = 'SELECT DISTINCT p.* FROM ' . Db::t( 'bookings' ) . ' b JOIN ' . Db::t( 'sessions' ) . ' s ON s.id = b.session_id AND s.cancelled_at IS NULL JOIN ' . Db::t( 'people' ) . " p ON p.id = b.person_id WHERE s.activity_id = %d AND b.status = 'booked' AND " . self::where() . ' ORDER BY p.last_name, p.first_name, p.id';
		} else {
			$sql = 'SELECT DISTINCT p.* FROM ' . Db::t( 'enrollments' ) . ' e JOIN ' . Db::t( 'people' ) . ' p ON p.id = e.person_id WHERE e.activity_id = %d AND e.end_month IS NULL AND ' . self::where() . ' ORDER BY p.last_name, p.first_name, p.id';
		}
		return $db->get_results( $db->prepare( $sql, $activity_id ), ARRAY_A ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- parti fisse, il solo dato è l'id
	}

	/** Nome per gli elenchi: cognome e nome. */
	public static function name( array $p ): string {
		return trim( (string) $p['last_name'] . ' ' . (string) $p['first_name'] );
	}
}
