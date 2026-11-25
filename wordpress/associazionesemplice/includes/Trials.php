<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Giorni di prova gratuiti dei corsi. La prova si registra (persona, corso, giorno) ma non crea iscrizione né debito.
 * Se la persona si iscrive nello stesso mese, il primo mese si paga dal giorno della prova compreso, in proporzione alle lezioni (senza orari: ai giorni);
 * se si iscrive più tardi la prova resta gratuita e chi iscrive legge che l'ha già fatta. Per il trimestre e il pagamento unico l'importo resta intero.
 * La proporzione si fissa all'iscrizione (lezioni dal giorno di prova e lezioni del mese): cambiare dopo il calendario non cambia il dovuto.
 */
final class Trials {

	/** Massimo di giorni di prova per corso. */
	const MAX_DAYS = 30;

	private static function db(): \wpdb {
		return Db::db();
	}

	/** Le prove di una persona a un corso, dalla prima. @return string[] date */
	public static function dates( int $activity_id, int $person_id ): array {
		return array_map( 'strval', self::db()->get_col( self::db()->prepare( 'SELECT trial_date FROM ' . Db::t( 'trials' ) . ' WHERE activity_id = %d AND person_id = %d ORDER BY trial_date', $activity_id, $person_id ) ) ?: array() );
	}

	/** Le prove di un corso, dalla più recente, con la persona. @return array[] */
	public static function of_activity( int $activity_id ): array {
		return self::db()->get_results(
			self::db()->prepare(
				'SELECT t.*, p.first_name, p.last_name, p.type, p.card_number, p.phone, p.email, e.start_month, e.end_month, e.trial_from, e.id AS enrollment_id FROM ' . Db::t( 'trials' ) . ' t JOIN ' . Db::t( 'people' ) . ' p ON p.id = t.person_id AND p.deleted_at IS NULL '
				. 'LEFT JOIN ' . Db::t( 'enrollments' ) . ' e ON e.activity_id = t.activity_id AND e.person_id = t.person_id WHERE t.activity_id = %d ORDER BY t.trial_date DESC, t.id DESC',
				$activity_id
			),
			ARRAY_A
		) ?: array();
	}

	/** Le prove di una persona in tutti i corsi, dalla più recente. @return array[] */
	public static function of_person( int $person_id ): array {
		return self::db()->get_results(
			self::db()->prepare( 'SELECT t.*, a.name AS activity_name FROM ' . Db::t( 'trials' ) . ' t JOIN ' . Db::t( 'activities' ) . ' a ON a.id = t.activity_id AND a.deleted_at IS NULL WHERE t.person_id = %d ORDER BY t.trial_date DESC', $person_id ),
			ARRAY_A
		) ?: array();
	}

	/** Chi ha fatto una prova dal giorno indicato e non è iscritto a quel corso (per la Bacheca). @return array[] */
	public static function not_enrolled( string $since ): array {
		return self::db()->get_results(
			self::db()->prepare(
				'SELECT t.activity_id, t.person_id, MAX(t.trial_date) AS last_date, COUNT(*) AS n, a.name AS activity_name, p.first_name, p.last_name, p.phone, p.email FROM ' . Db::t( 'trials' ) . ' t '
				. 'JOIN ' . Db::t( 'activities' ) . ' a ON a.id = t.activity_id AND a.deleted_at IS NULL JOIN ' . Db::t( 'people' ) . ' p ON p.id = t.person_id AND p.deleted_at IS NULL AND p.anonymized_at IS NULL '
				. 'LEFT JOIN ' . Db::t( 'enrollments' ) . ' e ON e.activity_id = t.activity_id AND e.person_id = t.person_id WHERE e.id IS NULL AND t.trial_date >= %s AND t.trial_date <= %s '
				. 'GROUP BY t.activity_id, t.person_id, a.name, p.first_name, p.last_name, p.phone, p.email ORDER BY last_date DESC LIMIT 30',
				$since,
				Db::today()
			),
			ARRAY_A
		) ?: array();
	}

	/**
	 * Registra un giorno di prova. Oltre i giorni previsti dal corso non si blocca: chi registra legge l'avviso.
	 *
	 * @return string avviso ('' se non c'è)
	 * @throws \InvalidArgumentException
	 */
	public static function add( int $activity_id, int $person_id, string $date ): string {
		$a = Plugin::activities()->get( $activity_id );
		if ( ! $a || ActivityKind::COURSE !== $a['kind'] ) {
			throw new \InvalidArgumentException( 'La prova gratuita vale per i corsi.' );
		}
		$days = (int) ( $a['trial_days'] ?? 0 );
		if ( $days < 1 ) {
			throw new \InvalidArgumentException( 'Questo corso non prevede giorni di prova: si impostano nei dati del corso.' );
		}
		if ( ! Plugin::people()->get( $person_id ) ) {
			throw new \InvalidArgumentException( 'Persona non trovata.' );
		}
		$dt = \DateTime::createFromFormat( 'Y-m-d', $date );
		if ( ! $dt || $dt->format( 'Y-m-d' ) !== $date ) {
			throw new \InvalidArgumentException( 'Data non valida.' );
		}
		ActivityService::assert_in_social_year( (string) $a['social_year'], $date, 'La data della prova' );
		if ( ActivityService::lessons( $a ) && ! in_array( $date, ActivityReset::course_dates( $a, $date, $date ), true ) ) {
			throw new \InvalidArgumentException( 'In quel giorno il corso non ha lezione.' );
		}
		$e = self::db()->get_row( self::db()->prepare( 'SELECT start_month, end_month FROM ' . Db::t( 'enrollments' ) . ' WHERE activity_id = %d AND person_id = %d', $activity_id, $person_id ), ARRAY_A );
		$m = substr( $date, 0, 7 );
		if ( $e && $e['start_month'] <= $m && ( null === $e['end_month'] || $e['end_month'] >= $m ) ) {
			throw new \InvalidArgumentException( 'In quel mese la persona è già iscritta al corso: la prova non serve.' );
		}
		$done = self::dates( $activity_id, $person_id );
		if ( in_array( $date, $done, true ) ) {
			throw new \InvalidArgumentException( 'La prova di quel giorno è già registrata.' );
		}
		$ok = self::db()->insert( Db::t( 'trials' ), array( 'activity_id' => $activity_id, 'person_id' => $person_id, 'trial_date' => $date, 'created_by' => get_current_user_id() ?: null, 'created_at' => Db::now() ) );
		if ( ! $ok ) {
			throw new \InvalidArgumentException( 'Prova non registrata: riprova.' );
		}
		Audit::log( 'trial.added', 'activity', $activity_id, array( 'person_id' => $person_id, 'date' => $date ) );
		return count( $done ) >= $days ? 'Attenzione: ha già fatto ' . self::days_text( $done ) . ' e il corso ne prevede ' . $days . '.' : '';
	}

	/** Toglie un giorno di prova registrato per errore; non se il primo mese dell'iscrizione si paga da quel giorno. @throws \InvalidArgumentException */
	public static function remove( int $id ): void {
		$t = self::db()->get_row( self::db()->prepare( 'SELECT * FROM ' . Db::t( 'trials' ) . ' WHERE id = %d', $id ), ARRAY_A );
		if ( ! $t ) {
			throw new \InvalidArgumentException( 'Prova non trovata.' );
		}
		$used = self::db()->get_var( self::db()->prepare( 'SELECT id FROM ' . Db::t( 'enrollments' ) . ' WHERE activity_id = %d AND person_id = %d AND trial_from = %s', (int) $t['activity_id'], (int) $t['person_id'], $t['trial_date'] ) );
		if ( $used ) {
			throw new \InvalidArgumentException( 'La persona si è iscritta e paga da questo giorno: la prova non si può togliere.' );
		}
		self::db()->delete( Db::t( 'trials' ), array( 'id' => $id ) );
		Audit::log( 'trial.removed', 'activity', (int) $t['activity_id'], array( 'person_id' => (int) $t['person_id'], 'date' => $t['trial_date'] ) );
	}

	/** «il 20/11/2026», «il 20/11/2026 e il 27/11/2026». @param string[] $dates */
	public static function days_text( array $dates ): string {
		$d = array_map( function ( $x ) {
			return 'il ' . ( new \DateTimeImmutable( $x ) )->format( 'd/m/Y' );
		}, $dates );
		return 'la prova ' . ( count( $d ) > 1 ? implode( ', ', array_slice( $d, 0, -1 ) ) . ' e ' . end( $d ) : (string) reset( $d ) );
	}

	/** Le prove già fatte, in parole, per chi iscrive ('' se nessuna). */
	public static function note( int $activity_id, int $person_id ): string {
		$done = self::dates( $activity_id, $person_id );
		return $done ? 'Ha già fatto ' . self::days_text( $done ) . '.' : '';
	}

	/**
	 * Da quale giorno si paga chi si iscrive da questo mese: la prima prova fatta nel mese (non futura), con la proporzione fissata adesso.
	 * Solo per il rinnovo mensile.
	 *
	 * @return array{trial_from:?string,trial_left:?int,trial_total:?int} campi dell'iscrizione
	 */
	public static function enrollment_fields( array $activity, int $person_id, string $start_month ): array {
		$none = array( 'trial_from' => null, 'trial_left' => null, 'trial_total' => null );
		if ( 'monthly' !== (string) ( $activity['billing'] ?? 'monthly' ) ) {
			return $none;
		}
		foreach ( self::dates( (int) $activity['id'], $person_id ) as $d ) {
			if ( substr( $d, 0, 7 ) === $start_month && $d <= Db::today() ) {
				list( $left, $total ) = self::ratio( $activity, $d );
				return array( 'trial_from' => $d, 'trial_left' => $left, 'trial_total' => $total );
			}
		}
		return $none;
	}

	/** Lezioni del mese dal giorno indicato compreso, e lezioni di tutto il mese (senza orari: giorni). @return int[] */
	public static function ratio( array $activity, string $date ): array {
		$first = substr( $date, 0, 7 ) . '-01';
		$last  = ( new \DateTimeImmutable( $first ) )->format( 'Y-m-t' );
		$all   = array_values( array_filter( ActivityReset::course_dates( $activity, $first, $last ), function ( $d ) use ( $first, $last ) {
			return $d >= $first && $d <= $last;
		} ) );
		if ( ! $all ) {
			$total = (int) substr( $last, 8, 2 );
			return array( $total - (int) substr( $date, 8, 2 ) + 1, $total );
		}
		$left = count( array_filter( $all, function ( $d ) use ( $date ) {
			return $d >= $date;
		} ) );
		return array( max( 1, $left ), count( $all ) );
	}

	/** La quota del primo mese di un'iscrizione nata da una prova, oppure null se il mese si paga per intero. */
	public static function first_month_cents( array $activity, array $enrollment, int $fee ): ?int {
		$from  = (string) ( $enrollment['trial_from'] ?? '' );
		$total = (int) ( $enrollment['trial_total'] ?? 0 );
		if ( '' === $from || $total < 1 || 'monthly' !== (string) ( $activity['billing'] ?? 'monthly' ) || substr( $from, 0, 7 ) !== (string) $enrollment['start_month'] ) {
			return null;
		}
		return PaymentCalc::share( $fee, (int) $enrollment['trial_left'], $total );
	}

	/** Quota prevista del mese per chi non è ancora iscritto: con una prova nel mese si paga da quel giorno, altrimenti per intero. */
	public static function expected_cents( array $activity, int $person_id, string $month, int $fee ): int {
		$f = self::enrollment_fields( $activity, $person_id, $month );
		return null === $f['trial_from'] ? $fee : PaymentCalc::share( $fee, (int) $f['trial_left'], (int) $f['trial_total'] );
	}
}
