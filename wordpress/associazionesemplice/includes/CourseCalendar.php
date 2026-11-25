<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Le date delle lezioni di un corso, calcolate in un posto solo: calendario, pagina pubblica, registro presenze, quote e rimborsi leggono da qui.
 * Lezioni settimanali (con inizio e fine proprie o del corso), date uniche, meno le lezioni annullate, con quelle spostate.
 */
final class CourseCalendar {

	/** Le date di una ripetizione settimanale tra due giorni. Senza inizio o fine propri valgono quelli indicati; senza nemmeno quelli, il periodo chiesto. @return string[] */
	public static function slot_dates( array $slot, string $from, string $to, ?string $base_from = null, ?string $base_to = null ): array {
		$lo = max( $from, (string) ( $slot['from'] ?: ( $base_from ?: $from ) ) );
		$hi = min( $to, (string) ( $slot['until'] ?: ( $base_to ?: $to ) ) );
		return Schedule::weekly_dates( $lo, $hi, (int) $slot['day'] );
	}

	/**
	 * Le date in calendario, prima delle eccezioni.
	 *
	 * @param array[] $lessons   righe di ActivityService::lessons()
	 * @param bool    $all_single date uniche anche fuori dal periodo (solo i rimborsi, che le leggono così)
	 * @param array   $skip       giorni senza lezione settimanale (data => motivo)
	 * @return string[]
	 */
	public static function expand( array $lessons, string $from, string $to, ?string $base_from = null, ?string $base_to = null, bool $all_single = false, array $skip = array() ): array {
		$dates = array();
		foreach ( $lessons as $s ) {
			if ( 'single' === $s['type'] ) {
				if ( $all_single || ( $s['date'] >= $from && $s['date'] <= $to ) ) {
					$dates[] = (string) $s['date'];
				}
				continue;
			}
			foreach ( self::slot_dates( $s, $from, $to, $base_from, $base_to ) as $d ) {
				if ( ! isset( $skip[ $d ] ) ) {
					$dates[] = $d;
				}
			}
		}
		return array_values( array_unique( $dates ) );
	}

	/** Le date in cui si tiene il corso tra due giorni (inclusi), con le lezioni annullate e spostate. @return string[] */
	public static function dates( array $a, string $from, string $to, bool $all_single = false ): array {
		$ex = LessonExceptions::between( $from, $to, (int) $a['id'] );
		return LessonExceptions::adjust_dates(
			self::expand( ActivityService::lessons( $a ), $from, $to, (string) ( $a['starts_on'] ?? '' ), (string) ( $a['ends_on'] ?? '' ), $all_single, self::skip( $a, $from, $to, $ex ) ),
			$ex,
			$from,
			$to
		);
	}

	/** Le lezioni settimanali saltate per festivi e chiusure tra due giorni. @return array<string,string> data => motivo */
	public static function red_lessons( array $a, string $from, string $to ): array {
		$skip  = self::skip( $a, $from, $to, LessonExceptions::between( $from, $to, (int) $a['id'] ) );
		$weeks = array_filter( ActivityService::lessons( $a ), function ( $s ) {
			return 'weekly' === $s['type'];
		} );
		return $skip ? array_intersect_key( $skip, array_flip( self::expand( $weeks, $from, $to, (string) ( $a['starts_on'] ?? '' ), (string) ( $a['ends_on'] ?? '' ) ) ) ) : array();
	}

	/**
	 * I giorni in cui il corso non ha la lezione settimanale perché festivi o di chiusura: nessuno se il corso non l'ha scelto, mai quelli che la segreteria
	 * tiene lo stesso né quelli con le presenze già segnate.
	 *
	 * @param array[] $ex eccezioni del corso nel periodo
	 * @return array<string,string> data => motivo
	 */
	public static function skip( array $a, string $from, string $to, array $ex ): array {
		if ( empty( $a['red_days'] ) ) {
			return array();
		}
		$red = RedDays::in_range( $from, $to );
		foreach ( $ex as $e ) {
			if ( LessonExceptions::KEEP === $e['action'] ) {
				unset( $red[ (string) $e['lesson_date'] ] );
			}
		}
		if ( $red ) { // una lezione con le presenze già segnate resta (il registro presenze è un'estensione)
			foreach ( (array) apply_filters( 'asem_lessons_with_records', array(), (int) $a['id'], array_keys( $red ) ) as $d ) {
				unset( $red[ (string) $d ] );
			}
		}
		return $red;
	}
}
