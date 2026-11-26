<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Corsi che si pagano a lezioni effettive: il contributo è il prezzo di una lezione e il dovuto di un mese è prezzo × lezioni del mese (dal giorno di
 * partenza in poi per il primo). Si paga in anticipo sulle lezioni previste: il mese si fissa alla prima di queste cose, e da lì non cambia con il
 * calendario: il promemoria del mese dopo, il primo incasso per quel mese, il giorno 1 (alla prima lettura, anche senza WP-Cron).
 * Il numero di lezioni è uno per corso e mese; il prezzo si fissa per persona e mese (variante, ospite). In un anno fiscale chiuso non si scrive nulla.
 */
final class CourseMonths {

	private static function db(): \wpdb {
		return Db::db();
	}

	public static function applies( array $activity ): bool {
		return 'lessons' === (string) ( $activity['billing'] ?? '' );
	}

	/**
	 * Le lezioni che contano per un mese secondo il calendario di adesso: quelle nate in quel mese (una lezione spostata resta nel suo mese di origine,
	 * così non si paga due volte), meno le annullate e i giorni senza lezione; una lezione con le presenze già segnate conta sempre.
	 *
	 * @return string[] date di origine
	 */
	public static function live( array $activity, string $month ): array {
		$from = $month . '-01';
		$to   = ( new \DateTimeImmutable( $from ) )->format( 'Y-m-t' );
		$ex   = LessonExceptions::between( $from, $to, (int) $activity['id'] );
		$all  = CourseCalendar::expand( ActivityService::lessons( $activity ), $from, $to, (string) ( $activity['starts_on'] ?? '' ), (string) ( $activity['ends_on'] ?? '' ), false, CourseCalendar::skip( $activity, $from, $to, $ex ) );
		$off  = array();
		foreach ( $ex as $e ) {
			if ( LessonExceptions::CANCEL === $e['action'] ) {
				$off[] = (string) $e['lesson_date'];
			}
		}
		if ( $off ) {
			$off = array_diff( $off, array_map( 'strval', (array) apply_filters( 'asem_lessons_with_records', array(), (int) $activity['id'], $off ) ) );
		}
		$all = array_values( array_diff( $all, $off ) );
		sort( $all );
		return $all;
	}

	/** Le date fissate di un mese (null = non ancora fissato). @return string[]|null */
	public static function fixed( int $activity_id, string $month ): ?array {
		return self::months_of( $activity_id )[ $month ] ?? null;
	}

	/** @return array<string,string[]> */
	private static function months_of( int $activity_id ): array {
		if ( ! RequestMemo::has( 'course_months', $activity_id ) ) {
			$map = array();
			foreach ( self::db()->get_results( self::db()->prepare( 'SELECT month, dates FROM ' . Db::t( 'course_months' ) . ' WHERE activity_id = %d', $activity_id ), ARRAY_A ) ?: array() as $r ) {
				$map[ (string) $r['month'] ] = array_values( array_filter( explode( ',', (string) $r['dates'] ) ) );
			}
			RequestMemo::put( 'course_months', $activity_id, $map, array( Db::t( 'course_months' ) ) );
		}
		return RequestMemo::get( 'course_months', $activity_id );
	}

	/** Il mese si può scrivere? (mai in un anno fiscale chiuso) */
	private static function writable( string $month ): bool {
		return null === YearLock::closed_year_of( $month . '-01' );
	}

	/**
	 * Fissa il mese se non lo è già. La chiave unica (corso, mese) e la rilettura fanno sì che due richieste insieme abbiano lo stesso numero.
	 *
	 * @param string $source read | payment | reminder
	 * @return string[] le date del mese
	 */
	public static function fix( array $activity, string $month, string $source ): array {
		$id   = (int) $activity['id'];
		$have = self::fixed( $id, $month );
		if ( null !== $have ) {
			return $have;
		}
		$live = self::live( $activity, $month );
		if ( $id < 1 || ! self::writable( $month ) || ! in_array( $month, SocialYear::from_label( (string) $activity['social_year'], Settings::start_month() )->months(), true ) ) { // fuori dall'anno del corso non c'è nulla da fissare
			return $live;
		}
		$mine = (int) self::db()->query( self::db()->prepare( 'INSERT IGNORE INTO ' . Db::t( 'course_months' ) . ' (activity_id, month, dates, source, fixed_at) VALUES (%d, %s, %s, %s, %s)', $id, $month, implode( ',', $live ), $source, Db::now() ) );
		RequestMemo::forget( 'course_months' );
		// Lettura che blocca: dentro una transazione quella normale può vedere un'istantanea vecchia, senza la riga appena scritta da un'altra richiesta.
		$row = self::db()->get_var( self::db()->prepare( 'SELECT dates FROM ' . Db::t( 'course_months' ) . ' WHERE activity_id = %d AND month = %s FOR UPDATE', $id, $month ) );
		if ( null === $row ) {
			if ( 1 !== $mine ) { // la riga c'è (scritta da un'altra richiesta) ma qui non si può leggere: meglio fermarsi che usare un numero diverso
				throw new \InvalidArgumentException( self::busy_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- testo fisso, mostrato solo dopo esc_html
			}
			return $live;
		}
		$dates = array_values( array_filter( explode( ',', (string) $row ) ) );
		$map   = self::months_of( $id );
		RequestMemo::put( 'course_months', $id, array( $month => $dates ) + $map, array( Db::t( 'course_months' ) ) );
		return $dates;
	}

	/**
	 * «Ricalcola il mese»: rifà le lezioni fissate con il calendario di adesso, solo se nessuno ha ancora versato per quel mese e l'anno fiscale è aperto.
	 *
	 * @return int quante lezioni adesso
	 * @throws \InvalidArgumentException
	 */
	public static function recalc( int $activity_id, string $month ): int {
		$a = Plugin::activities()->get( $activity_id );
		if ( ! $a || ! self::applies( $a ) || ! preg_match( '/^\d{4}-(0[1-9]|1[0-2])$/', $month ) ) {
			throw new \InvalidArgumentException( 'Mese del corso non trovato.' );
		}
		YearLock::assert_date_open( $month . '-01' );
		$n = 0;
		Plugin::ledger()->in_batch( // la riga del corso si blocca come negli incassi: un incasso insieme non passa in mezzo
			function () use ( $a, $activity_id, $month, &$n ) {
				$db = self::db();
				$db->get_var( $db->prepare( 'SELECT id FROM ' . Db::t( 'activities' ) . ' WHERE id = %d FOR UPDATE', $activity_id ) );
				if ( self::paid( $activity_id, $month ) ) {
					throw new \InvalidArgumentException( 'Qualcuno ha già versato per questo mese: le lezioni restano quelle fissate.' );
				}
				$old = self::fixed( $activity_id, $month );
				if ( null === $old ) {
					throw new \InvalidArgumentException( 'Le lezioni di questo mese non sono ancora fissate.' );
				}
				$db->delete( Db::t( 'course_month_dues' ), array( 'activity_id' => $activity_id, 'month' => $month ) );
				$db->delete( Db::t( 'course_months' ), array( 'activity_id' => $activity_id, 'month' => $month ) );
				RequestMemo::forget( 'course_months' );
				RequestMemo::forget( 'course_dues' );
				$n = count( self::fix( $a, $month, 'recalc' ) );
				Audit::log( 'course.month_recalculated', 'activity', $activity_id, array( 'month' => $month, 'before' => count( $old ), 'after' => $n ) );
			}
		);
		return $n;
	}

	public static function busy_message(): string {
		return 'In questo momento un\'altra operazione sta fissando le lezioni dello stesso mese: riprova tra qualche secondo.';
	}

	/** Qualcuno ha versato per questo mese del corso? */
	public static function paid( int $activity_id, string $month ): bool {
		return (int) self::db()->get_var( self::db()->prepare( 'SELECT COUNT(*) FROM ' . Db::t( 'transactions' ) . " WHERE activity_id = %d AND type = 'income' AND voided_at IS NULL AND session_id IS NULL AND COALESCE(competence_month, DATE_FORMAT(tx_date, '%%Y-%%m')) = %s", $activity_id, $month ) ) > 0
			|| (int) self::db()->get_var( self::db()->prepare( 'SELECT COUNT(*) FROM ' . Db::t( 'course_credit_payments' ) . " WHERE activity_id = %d AND month = %s AND status = 'paid'", $activity_id, $month ) ) > 0; // anche in moneta interna
	}

	/** Le date di un mese senza scrivere nulla: quelle fissate o quelle previste. @return string[] */
	public static function peek( array $activity, string $month ): array {
		return self::fixed( (int) $activity['id'], $month ) ?? self::live( $activity, $month );
	}

	/** Le date di un mese per il dovuto di un iscritto: dal giorno 1 in poi si fissa adesso, prima valgono quelle previste. @return string[] */
	private static function dates( array $activity, string $month ): array {
		return $month . '-01' <= Db::today() ? self::fix( $activity, $month, 'read' ) : ( self::fixed( (int) $activity['id'], $month ) ?? self::live( $activity, $month ) );
	}

	/** Quante di queste date cadono dal giorno indicato in poi ('' = tutte). @param string[] $dates */
	public static function count_from( array $dates, string $from ): int {
		return '' === $from ? count( $dates ) : count( array_filter( $dates, function ( $d ) use ( $from ) {
			return $d >= $from;
		} ) );
	}

	/**
	 * Il dovuto di un mese per un iscritto. Con il mese fissato prezzo e lezioni della persona si scrivono una volta sola.
	 *
	 * @param int $price prezzo di una lezione per questa persona, adesso
	 */
	public static function due( array $activity, array $enrollment, int $price, string $month ): int {
		$aid   = (int) $activity['id'];
		$pid   = (int) $enrollment['person_id'];
		$dates = self::dates( $activity, $month );
		$saved = self::saved( $aid );
		if ( isset( $saved[ $pid . '|' . $month ] ) ) {
			return $saved[ $pid . '|' . $month ];
		}
		$n = self::count_from( $dates, Trials::trial_month( $enrollment ) === $month ? (string) $enrollment['trial_from'] : '' );
		if ( null !== self::fixed( $aid, $month ) && self::writable( $month ) ) {
			$mine = (int) self::db()->query( self::db()->prepare( 'INSERT IGNORE INTO ' . Db::t( 'course_month_dues' ) . ' (activity_id, person_id, month, price_cents, lessons, due_cents, created_at) VALUES (%d, %d, %s, %d, %d, %d, %s)', $aid, $pid, $month, $price, $n, $price * $n, Db::now() ) );
			RequestMemo::forget( 'course_dues' );
			$row = self::db()->get_var( self::db()->prepare( 'SELECT due_cents FROM ' . Db::t( 'course_month_dues' ) . ' WHERE activity_id = %d AND person_id = %d AND month = %s FOR UPDATE', $aid, $pid, $month ) ); // come in fix(): vale ciò che è scritto, non l'istantanea
			if ( null === $row && 1 !== $mine ) {
				throw new \InvalidArgumentException( self::busy_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- testo fisso, mostrato solo dopo esc_html
			}
			if ( 1 === $mine ) { // dovuto nuovo in un mese con lezioni già annullate (iscritto dopo): anche per lui la lezione esce dal dovuto
				CourseCredits::catch_up( $activity, $month );
			}
			return null === $row ? $price * $n : (int) $row;
		}
		return $price * $n;
	}

	/** Il dovuto previsto per chi non è ancora iscritto. */
	public static function expected( array $activity, int $person_id, int $price, string $month ): int {
		$f = Trials::enrollment_fields( $activity, $person_id, $month );
		return $price * self::count_from( self::fixed( (int) $activity['id'], $month ) ?? self::live( $activity, $month ), (string) $f['trial_from'] );
	}

	/** I dovuti già fissati di un corso. @return array<string,int> «persona|mese» => centesimi */
	private static function saved( int $activity_id ): array {
		if ( ! RequestMemo::has( 'course_dues', $activity_id ) ) {
			$map = array();
			foreach ( self::db()->get_results( self::db()->prepare( 'SELECT person_id, month, due_cents FROM ' . Db::t( 'course_month_dues' ) . ' WHERE activity_id = %d', $activity_id ), ARRAY_A ) ?: array() as $r ) {
				$map[ $r['person_id'] . '|' . $r['month'] ] = (int) $r['due_cents'];
			}
			RequestMemo::put( 'course_dues', $activity_id, $map, array( Db::t( 'course_month_dues' ) ) );
		}
		return RequestMemo::get( 'course_dues', $activity_id );
	}

	/**
	 * Iscrizione nuova o ricominciata da un mese: i dovuti fissati da un'iscrizione precedente, da quel mese in poi e senza versamenti, si rifanno.
	 *
	 * @param string[] $paid_months mesi con un versamento
	 */
	public static function restart( int $activity_id, int $person_id, string $month, array $paid_months ): void {
		foreach ( self::db()->get_results( self::db()->prepare( 'SELECT id, month FROM ' . Db::t( 'course_month_dues' ) . ' WHERE activity_id = %d AND person_id = %d AND month >= %s', $activity_id, $person_id, $month ), ARRAY_A ) ?: array() as $r ) {
			if ( ! in_array( (string) $r['month'], $paid_months, true ) && self::writable( (string) $r['month'] ) ) {
				self::db()->delete( Db::t( 'course_month_dues' ), array( 'id' => (int) $r['id'] ) );
			}
		}
	}

	/** La prima lezione di un mese, per sapere da quando è dovuto (null se il mese non ha lezioni in calendario: resta la regola del giorno della settimana). */
	public static function first_lesson( array $activity, string $month ): ?string {
		if ( ! ActivityService::lessons( $activity ) ) {
			return null;
		}
		$key = (int) $activity['id'] . '|' . $month;
		if ( ! RequestMemo::has( 'course_first', $key ) ) { // la stessa per tutti gli iscritti
			$first = $month . '-01';
			$dates = CourseCalendar::dates( $activity, $first, ( new \DateTimeImmutable( $first ) )->format( 'Y-m-t' ) );
			RequestMemo::put( 'course_first', $key, $dates ? $dates[0] : null, array( Db::t( 'activities' ), Db::t( 'lesson_exceptions' ), Db::db()->options ) );
		}
		return RequestMemo::get( 'course_first', $key );
	}
}
