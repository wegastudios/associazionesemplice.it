<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Registro delle azioni: chi ha fatto cosa e quando. Serve soprattutto quando le persone che operano
 * sono più di una. Non contiene dati personali oltre all'id utente: i dettagli sono id e importi.
 */
final class Audit {

	public static function log( string $action, string $object_type = '', ?int $object_id = null, array $details = array() ): void {
		Db::db()->insert(
			Db::t( 'audit_log' ),
			array(
				'created_at'  => Db::now(),
				'user_id'     => get_current_user_id() ?: null,
				'action'      => substr( $action, 0, 60 ),
				'object_type' => substr( $object_type, 0, 30 ),
				'object_id'   => $object_id,
				'details'     => $details ? wp_json_encode( $details ) : null,
			)
		);
	}

	/** Cancella le righe prima della data indicata, a gruppi per non bloccare il database. @return int righe eliminate */
	private static function delete_before( string $cutoff ): int {
		$db    = Db::db();
		$total = 0;
		for ( $i = 0; $i < 200; $i++ ) { // fino a un milione di righe per passaggio
			$n = (int) $db->query( $db->prepare( 'DELETE FROM ' . Db::t( 'audit_log' ) . ' WHERE created_at < %s LIMIT 5000', $cutoff ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- nome tabella interno
			$total += $n;
			if ( $n < 5000 ) {
				break;
			}
		}
		return $total;
	}

	/** Lavoro giornaliero: elimina le righe più vecchie del limite scelto (Limiti e soglie → «registro azioni»). @return int righe eliminate */
	public static function purge_old(): int {
		$months = Limits::get( 'audit_keep_months' );
		return self::delete_before( gmdate( 'Y-m-d H:i:s', strtotime( '-' . $months . ' months', strtotime( Db::now() ) ) ) );
	}

	/** «Libera spazio»: elimina tutto ciò che non è dell'anno in corso e restringe la tabella. @return int righe eliminate */
	public static function free_space(): int {
		$n = self::delete_before( substr( Db::now(), 0, 4 ) . '-01-01 00:00:00' );
		if ( $n > 0 ) {
			Db::db()->query( 'OPTIMIZE TABLE ' . Db::t( 'audit_log' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- nome tabella interno
		}
		self::log( 'audit.freed', 'settings', 0, array( 'rows' => $n ) );
		return $n;
	}

	/** @return array{rows:int,bytes:int,oldest:string} righe, spazio occupato nel database (dati e indici) e data della più vecchia */
	public static function stats(): array {
		$db     = Db::db();
		$table  = Db::t( 'audit_log' );
		$rows   = (int) $db->get_var( 'SELECT COUNT(*) FROM ' . $table ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- nome tabella interno
		$oldest = (string) $db->get_var( 'SELECT MIN(created_at) FROM ' . $table ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- nome tabella interno
		$bytes  = (int) $db->get_var( $db->prepare( 'SELECT data_length + index_length FROM information_schema.TABLES WHERE table_schema = DATABASE() AND table_name = %s', $table ) );
		return array( 'rows' => $rows, 'bytes' => $bytes, 'oldest' => $oldest );
	}

	/** @return array[] dal più recente */
	public static function recent( int $limit = 100, string $action_prefix = '' ): array {
		$db  = Db::db();
		$sql = 'SELECT a.*, u.display_name FROM ' . Db::t( 'audit_log' ) . ' a LEFT JOIN ' . $db->users . ' u ON u.ID = a.user_id';
		$args = array();
		if ( '' !== $action_prefix ) {
			$sql   .= ' WHERE a.action LIKE %s';
			$args[] = $db->esc_like( $action_prefix ) . '%';
		}
		$sql   .= ' ORDER BY a.id DESC LIMIT %d';
		$args[] = max( 1, $limit );
		return $db->get_results( $db->prepare( $sql, $args ), ARRAY_A ) ?: array();
	}
}
