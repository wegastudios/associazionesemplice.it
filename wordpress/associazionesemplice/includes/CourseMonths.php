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
		if ( ! RequestMemo::has( 'course_months', $activity_id ) ) {
			$map = array();
			foreach ( self::db()->get_results( self::db()->prepare( 'SELECT month, dates FROM ' . Db::t( 'course_months' ) . ' WHERE activity_id = %d', $activity_id ), ARRAY_A ) ?: array() as $r ) {
				$map[ (string) $r['month'] ] = array_values( array_filter( explode( ',', (string) $r['dates'] ) ) );
			}
			RequestMemo::put( 'course_months', $activity_id, $map, array( Db::t( 'course_months' ) ) );
		}
		return RequestMemo::get( 'course_months', $activity_id )[ $month ] ?? null;
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
		self::db()->query( self::db()->prepare( 'INSERT IGNORE INTO ' . Db::t( 'course_months' ) . ' (activity_id, month, dates, source, fixed_at) VALUES (%d, %s, %s, %s, %s)', $id, $month, implode( ',', $live ), $source, Db::now() ) );
		RequestMemo::forget( 'course_months' );
		return self::fixed( $id, $month ) ?? $live;
	}

	/** Le date di un mese: quelle fissate; dal giorno 1 in poi si fissa adesso, prima valgono quelle previste. @return string[] */
	public static function dates( array $activity, string $month ): array {
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
			self::db()->query( self::db()->prepare( 'INSERT IGNORE INTO ' . Db::t( 'course_month_dues' ) . ' (activity_id, person_id, month, price_cents, lessons, due_cents, created_at) VALUES (%d, %d, %s, %d, %d, %d, %s)', $aid, $pid, $month, $price, $n, $price * $n, Db::now() ) );
			RequestMemo::forget( 'course_dues' );
			return self::saved( $aid )[ $pid . '|' . $month ] ?? $price * $n;
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
