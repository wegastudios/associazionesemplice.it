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
	 * @return string[]
	 */
	public static function expand( array $lessons, string $from, string $to, ?string $base_from = null, ?string $base_to = null, bool $all_single = false ): array {
		$dates = array();
		foreach ( $lessons as $s ) {
			if ( 'single' === $s['type'] ) {
				if ( $all_single || ( $s['date'] >= $from && $s['date'] <= $to ) ) {
					$dates[] = (string) $s['date'];
				}
				continue;
			}
			foreach ( self::slot_dates( $s, $from, $to, $base_from, $base_to ) as $d ) {
				$dates[] = $d;
			}
		}
		return array_values( array_unique( $dates ) );
	}

	/** Le date in cui si tiene il corso tra due giorni (inclusi), con le lezioni annullate e spostate. @return string[] */
	public static function dates( array $a, string $from, string $to, bool $all_single = false ): array {
		return LessonExceptions::adjust_dates(
			self::expand( ActivityService::lessons( $a ), $from, $to, (string) ( $a['starts_on'] ?? '' ), (string) ( $a['ends_on'] ?? '' ), $all_single ),
			LessonExceptions::between( $from, $to, (int) $a['id'] ),
			$from,
			$to
		);
	}
}
