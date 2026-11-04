<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Eccezioni alle lezioni di un corso: una data annullata (festività, maltempo) o spostata a un altro giorno e orario.
 * Le lezioni dei corsi sono calcolate dal calendario settimanale; qui si registra solo ciò che cambia, senza toccare il resto.
 */
final class LessonExceptions {

	const CANCEL = 'cancel';
	const MOVE   = 'move';

	private static function db(): \wpdb {
		return Db::db();
	}

	/** Regole pure: sistema le date di un corso con le eccezioni (le annullate e le spostate spariscono, le nuove date di spostamento si aggiungono). @param string[] $dates @param array[] $exceptions */
	public static function adjust_dates( array $dates, array $exceptions, string $from, string $to ): array {
		foreach ( $exceptions as $e ) {
			$dates = array_values( array_diff( $dates, array( (string) $e['lesson_date'] ) ) );
			if ( self::MOVE === $e['action'] && ! empty( $e['new_date'] ) && $e['new_date'] >= $from && $e['new_date'] <= $to ) {
				$dates[] = (string) $e['new_date'];
			}
		}
		$dates = array_values( array_unique( $dates ) );
		sort( $dates );
		return $dates;
	}

	/** Le eccezioni che toccano il periodo (la data originale o quella nuova cade tra $from e $to). @return array[] */
	public static function between( string $from, string $to, ?int $activity_id = null ): array {
		$db  = self::db();
		$sql = 'SELECT * FROM ' . Db::t( 'lesson_exceptions' ) . ' WHERE ((lesson_date BETWEEN %s AND %s) OR (new_date IS NOT NULL AND new_date BETWEEN %s AND %s))';
		$arg = array( $from, $to, $from, $to );
		if ( $activity_id ) {
			$sql  .= ' AND activity_id = %d';
			$arg[] = $activity_id;
		}
		return $db->get_results( $db->prepare( $sql . ' ORDER BY lesson_date', $arg ), ARRAY_A ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- tabella interna, valori preparati
	}

	/** Tutte le eccezioni di un corso, dalle più recenti. @return array[] */
	public static function for_activity( int $activity_id ): array {
		return self::db()->get_results( self::db()->prepare( 'SELECT * FROM ' . Db::t( 'lesson_exceptions' ) . ' WHERE activity_id = %d ORDER BY lesson_date DESC', $activity_id ), ARRAY_A ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- tabella interna
	}

	/** Annulla una lezione. @throws \InvalidArgumentException */
	public static function cancel( int $activity_id, string $date, string $note = '' ): void {
		self::save( $activity_id, $date, self::CANCEL, '', '', '', $note );
	}

	/**
	 * Annulla questa lezione e le successive dello stesso giorno della settimana, fino alla fine del corso (o dell'anno sociale).
	 * Le lezioni passate non si toccano.
	 *
	 * @return int quante lezioni annullate
	 * @throws \InvalidArgumentException
	 */
	public static function cancel_from( int $activity_id, string $date, string $note = '' ): int {
		$a = Plugin::activities()->get( $activity_id );
		if ( ! $a || ActivityKind::COURSE !== $a['kind'] ) {
			throw new \InvalidArgumentException( 'Le lezioni si annullano o spostano per un corso.' );
		}
		if ( ! self::valid_date( $date ) ) {
			throw new \InvalidArgumentException( 'Data della lezione non valida.' );
		}
		$last = ! empty( $a['ends_on'] ) ? (string) $a['ends_on'] : SocialYear::from_label( (string) $a['social_year'], Settings::start_month() )->end()->format( 'Y-m-d' );
		$last = min( $last, gmdate( 'Y-m-d', strtotime( $date . ' +400 days' ) ) );
		$day  = (int) ( new \DateTimeImmutable( $date ) )->format( 'N' );
		$all  = array( $date );
		foreach ( ActivityService::slots( $a ) as $s ) {
			if ( (int) $s['day'] !== $day ) {
				continue;
			}
			$lo = max( $date, (string) ( $s['from'] ?: $date ) );
			$hi = min( $last, (string) ( $s['until'] ?: $last ) );
			foreach ( Schedule::weekly_dates( $lo, $hi, $day ) as $d ) {
				$all[] = $d;
			}
		}
		$all = array_values( array_unique( $all ) );
		foreach ( $all as $d ) {
			self::cancel( $activity_id, $d, $note );
		}
		return count( $all );
	}

	/** Sposta una lezione a un altro giorno (e, se serve, orario). @throws \InvalidArgumentException */
	public static function move( int $activity_id, string $date, string $new_date, string $start = '', string $end = '', string $note = '' ): void {
		if ( ! self::valid_date( $new_date ) ) {
			throw new \InvalidArgumentException( 'Scegli la nuova data della lezione.' );
		}
		if ( $new_date === $date ) {
			throw new \InvalidArgumentException( 'La nuova data è uguale a quella di prima.' );
		}
		self::save( $activity_id, $date, self::MOVE, $new_date, $start, $end, $note );
	}

	/** Rimette la lezione com'era. */
	public static function restore( int $activity_id, string $date ): void {
		$n = self::db()->delete( Db::t( 'lesson_exceptions' ), array( 'activity_id' => $activity_id, 'lesson_date' => $date ) );
		if ( $n ) {
			Audit::log( 'lesson.restored', 'activity', $activity_id, array( 'date' => $date ) );
		}
	}

	private static function valid_date( string $d ): bool {
		$dt = \DateTime::createFromFormat( 'Y-m-d', $d );
		return $dt && $dt->format( 'Y-m-d' ) === $d;
	}

	private static function valid_time( string $t ): bool {
		return '' === $t || (bool) preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $t );
	}

	private static function save( int $activity_id, string $date, string $action, string $new_date, string $start, string $end, string $note ): void {
		$a = Plugin::activities()->get( $activity_id );
		if ( ! $a || ActivityKind::COURSE !== $a['kind'] ) {
			throw new \InvalidArgumentException( 'Le lezioni si annullano o spostano per un corso.' );
		}
		if ( ! self::valid_date( $date ) ) {
			throw new \InvalidArgumentException( 'Data della lezione non valida.' );
		}
		if ( ! self::valid_time( $start ) || ! self::valid_time( $end ) ) {
			throw new \InvalidArgumentException( 'Orario non valido (scrivi ore e minuti, ad esempio 18:30).' );
		}
		$row = array(
			'activity_id' => $activity_id,
			'lesson_date' => $date,
			'action'      => $action,
			'new_date'    => '' === $new_date ? null : $new_date,
			'new_start'   => '' === $start ? null : $start,
			'new_end'     => '' === $end ? null : $end,
			'note'        => '' === trim( $note ) ? null : mb_substr( trim( $note ), 0, 190 ),
			'created_at'  => Db::now(),
		);
		$db  = self::db();
		$id  = (int) $db->get_var( $db->prepare( 'SELECT id FROM ' . Db::t( 'lesson_exceptions' ) . ' WHERE activity_id = %d AND lesson_date = %s', $activity_id, $date ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- tabella interna
		if ( $id ) {
			$db->update( Db::t( 'lesson_exceptions' ), $row, array( 'id' => $id ) );
		} else {
			$db->insert( Db::t( 'lesson_exceptions' ), $row );
		}
		Audit::log( self::CANCEL === $action ? 'lesson.cancelled' : 'lesson.moved', 'activity', $activity_id, array( 'date' => $date, 'to' => $new_date ) );
	}
}
