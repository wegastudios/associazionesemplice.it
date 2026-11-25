<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Calendario di corsi ed eventi: le lezioni settimanali dei corsi (giorno, orario, luogo, date di inizio e fine) e le date degli eventi.
 * Si legge in amministrazione e si collega a Google Calendar (o ad altri) con un indirizzo "da URL" segreto.
 */
final class Calendar {

	const SALT_OPTION = 'asem_ical_salt';
	const QUERY_VAR   = 'asem_ical';

	// ---------- Date ----------

	/**
	 * Lezioni e date degli eventi tra due date (incluse), in ordine di data e ora.
	 *
	 * @return array[] date, start, end, title, location, kind (course|event|recurring), activity_id, session_id
	 */
	public static function occurrences( string $from, string $to ): array {
		$sm  = Settings::start_month(); // imposta anche SocialYear::$legacy_month: entrambi entrano nella chiave (sono impostazioni, non tabelle)
		$all = SharedMemo::remember(
			'calendar',
			$from . '|' . $to . '|' . $sm . '|' . SocialYear::$legacy_month,
			static function () use ( $from, $to ): array {
				return self::read_occurrences( $from, $to );
			}
		);
		return Employees::only_allowed_kinds( $all ); // un dipendente a cui sono stati tolti i corsi (o gli eventi) non li vede nel calendario: si filtra dopo, per chi guarda
	}

	/** Le date di {@see Calendar::occurrences()} lette dal database, uguali per chiunque guardi. */
	private static function read_occurrences( string $from, string $to ): array {
		$db   = Db::db();
		$out  = array();
		// Le lezioni vengono solo dai corsi: gli eventi non si leggono, e nemmeno i corsi già finiti prima di $from che non hanno date proprie o ripetizioni con una fine esplicita.
		$acts = $db->get_results(
			$db->prepare(
				'SELECT * FROM ' . Db::t( 'activities' ) . " WHERE deleted_at IS NULL AND kind = %s AND NOT ( ends_on IS NOT NULL AND ends_on < %s AND ( lesson_slots IS NULL OR ( lesson_slots NOT LIKE '%%single%%' AND lesson_slots NOT LIKE '%%until\":\"%%' AND lesson_slots NOT LIKE '%%until\": \"%%' ) ) )", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnsupportedPlaceholder -- nome tabella interno
				ActivityKind::COURSE,
				$from
			),
			ARRAY_A
		) ?: array();
		$skip = array(); // lezioni annullate o spostate: activity_id => [data => true]
		$moves = array();
		foreach ( LessonExceptions::between( $from, $to ) as $e ) {
			$skip[ (int) $e['activity_id'] ][ (string) $e['lesson_date'] ] = true;
			if ( LessonExceptions::MOVE === $e['action'] && $e['new_date'] >= $from && $e['new_date'] <= $to ) {
				$moves[] = $e;
			}
		}
		$by_id = array();
		foreach ( $acts as $a ) {
			$by_id[ (int) $a['id'] ] = $a;
			if ( ActivityKind::COURSE !== $a['kind'] ) {
				continue;
			}
			$year  = SocialYear::from_label( $a['social_year'], Settings::start_month() );
			$base0 = $a['starts_on'] ?: $year->start()->format( 'Y-m-d' );
			$base1 = $a['ends_on'] ?: $year->end()->format( 'Y-m-d' );
			foreach ( ActivityService::lessons( $a ) as $slot ) {
				if ( 'single' === $slot['type'] ) {
					if ( $slot['date'] >= $from && $slot['date'] <= $to && empty( $skip[ (int) $a['id'] ][ $slot['date'] ] ) ) {
						$out[] = array(
							'date' => $slot['date'], 'start' => $slot['start'], 'end' => $slot['end'], 'title' => $a['name'], 'location' => (string) $a['location'],
							'kind' => 'course', 'activity_id' => (int) $a['id'], 'session_id' => 0,
						);
					}
					continue;
				}
				foreach ( CourseCalendar::slot_dates( $slot, $from, $to, $base0, $base1 ) as $d ) {
					if ( ! empty( $skip[ (int) $a['id'] ][ $d ] ) ) {
						continue; // lezione annullata o spostata
					}
					$out[] = array(
						'date' => $d, 'start' => $slot['start'], 'end' => $slot['end'], 'title' => $a['name'], 'location' => (string) $a['location'],
						'kind' => 'course', 'activity_id' => (int) $a['id'], 'session_id' => 0,
					);
				}
			}
		}
		$missing = array_values( array_diff( array_unique( array_map( 'intval', array_column( $moves, 'activity_id' ) ) ), array_keys( $by_id ) ) );
		if ( $missing ) { // una lezione spostata da un'attività non letta sopra (corso finito prima di $from): si legge solo quella
			foreach ( $db->get_results( 'SELECT * FROM ' . Db::t( 'activities' ) . ' WHERE deleted_at IS NULL AND id IN (' . implode( ',', $missing ) . ')', ARRAY_A ) ?: array() as $a ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- id interi
				$by_id[ (int) $a['id'] ] = $a;
			}
		}
		foreach ( $moves as $e ) { // lezioni spostate: compaiono nella nuova data, con l'orario nuovo o quello solito
			$a = $by_id[ (int) $e['activity_id'] ] ?? null;
			if ( ! $a ) {
				continue;
			}
			$slot = null;
			foreach ( ActivityService::lessons( $a ) as $s ) {
				if ( 'weekly' === $s['type'] && (int) $s['day'] === (int) gmdate( 'N', strtotime( $e['lesson_date'] ) ) ) {
					$slot = $s;
				}
			}
			$out[] = array(
				'date' => $e['new_date'], 'start' => $e['new_start'] ?: ( $slot ? $slot['start'] : null ), 'end' => $e['new_end'] ?: ( $slot ? $slot['end'] : null ), 'title' => $a['name'], 'location' => (string) $a['location'],
				'kind' => 'course', 'activity_id' => (int) $a['id'], 'session_id' => 0,
			);
		}
		$rows = $db->get_results(
			$db->prepare(
				'SELECT s.*, a.name, a.kind FROM ' . Db::t( 'sessions' ) . ' s JOIN ' . Db::t( 'activities' ) . ' a ON a.id = s.activity_id AND a.deleted_at IS NULL '
				. 'WHERE s.cancelled_at IS NULL AND s.session_date <= %s AND COALESCE(s.end_date, s.session_date) >= %s',
				$to,
				$from
			),
			ARRAY_A
		) ?: array();
		foreach ( $rows as $s ) {
			$out[] = array(
				'date' => $s['session_date'], 'end_date' => ! empty( $s['end_date'] ) ? (string) $s['end_date'] : null, 'start' => $s['start_time'], 'end' => $s['end_time'], 'title' => $s['name'], 'location' => (string) $s['location'],
				'kind' => $s['kind'], 'activity_id' => (int) $s['activity_id'], 'session_id' => (int) $s['id'],
			);
		}
		usort(
			$out,
			function ( $x, $y ) {
				return strcmp( $x['date'] . ( $x['start'] ?: '00:00' ) . $x['title'], $y['date'] . ( $y['start'] ?: '00:00' ) . $y['title'] );
			}
		);
		return $out;
	}

	/**
	 * Un evento su più giorni compare in ognuno dei suoi giorni (dentro $from..$to): ogni voce ha 'date' = quel giorno,
	 * 'day_n' e 'days' (giorno 2 di 3) e 'first' (il giorno di inizio, quello con l'orario di inizio). Le altre voci restano com'erano.
	 *
	 * @param array[] $occurrences vedi {@see Calendar::occurrences()}
	 * @return array[] in ordine di giorno e orario
	 */
	public static function spread( array $occurrences, string $from, string $to ): array {
		$out = array();
		foreach ( $occurrences as $o ) {
			$last = ! empty( $o['end_date'] ) && $o['end_date'] > $o['date'] ? (string) $o['end_date'] : (string) $o['date'];
			if ( $last === $o['date'] ) {
				$out[] = $o;
				continue;
			}
			$days = (int) ( new \DateTimeImmutable( (string) $o['date'] ) )->diff( new \DateTimeImmutable( $last ) )->days + 1;
			for ( $n = 1; $n <= $days; $n++ ) {
				$day = gmdate( 'Y-m-d', strtotime( $o['date'] . ' +' . ( $n - 1 ) . ' days 12:00:00 UTC' ) );
				if ( $day < $from || $day > $to ) {
					continue;
				}
				$out[] = array_merge( $o, array( 'date' => $day, 'day_n' => $n, 'days' => $days, 'first' => 1 === $n, 'start' => 1 === $n ? $o['start'] : null, 'end' => $n === $days ? $o['end'] : null ) );
			}
		}
		usort(
			$out,
			function ( $x, $y ) {
				return strcmp( $x['date'] . ( $x['start'] ?: '00:00' ) . $x['title'], $y['date'] . ( $y['start'] ?: '00:00' ) . $y['title'] );
			}
		);
		return $out;
	}

	/** Etichetta «giorno 2 di 3» per le voci spalmate da {@see Calendar::spread()}; vuota per le altre. */
	public static function day_label( array $o ): string {
		return ! empty( $o['days'] ) ? ' (giorno ' . (int) $o['day_n'] . ' di ' . (int) $o['days'] . ')' : '';
	}

	/** Date comprese tra $from e $to che cadono nel giorno della settimana indicato (1 = lunedì … 7 = domenica). @return string[] */
	public static function weekly_dates( string $from, string $to, int $weekday ): array {
		return Schedule::weekly_dates( $from, $to, $weekday );
	}

	// ---------- Collegamento a Google Calendar ----------

	public static function enabled(): bool {
		return ! empty( Settings::get( 'ical_enabled' ) );
	}

	/** Codice segreto dell'indirizzo del calendario: si può rigenerare (il vecchio indirizzo smette di funzionare). */
	public static function token(): string {
		$salt = (string) get_option( self::SALT_OPTION, '' );
		if ( '' === $salt ) {
			$salt = bin2hex( random_bytes( 16 ) );
			update_option( self::SALT_OPTION, $salt, false );
		}
		return substr( hash_hmac( 'sha256', 'ical|' . $salt, wp_salt( 'auth' ) ), 0, 32 );
	}

	public static function regenerate_token(): void {
		update_option( self::SALT_OPTION, bin2hex( random_bytes( 16 ) ), false );
		Audit::log( 'calendar.token_regenerated', 'settings' );
	}

	/** Indirizzo del calendario di tutte le attività o, con $activity_id, di una sola. */
	public static function feed_url( ?int $activity_id = null ): string {
		$args = array( self::QUERY_VAR => self::token() );
		if ( $activity_id ) {
			$args['a'] = $activity_id;
		}
		return add_query_arg( $args, home_url( '/' ) );
	}

	/** Indirizzo con lo schema webcal:// (Apple Calendar, Outlook, Thunderbird). */
	public static function webcal_url( string $feed ): string {
		return preg_replace( '#^https?://#i', 'webcal://', $feed );
	}

	/** Collegamento che apre Google Calendar già pronto ad aggiungere il calendario. */
	public static function google_add_url( string $feed ): string {
		return 'https://calendar.google.com/calendar/r?cid=' . rawurlencode( self::webcal_url( $feed ) );
	}

	/** Il calendario in formato iCalendar: da 30 giorni fa a 13 mesi avanti. */
	public static function ics( ?string $today = null, ?int $activity_id = null ): string {
		$today  = $today ?: Db::today();
		$from   = ( new \DateTimeImmutable( $today ) )->modify( '-30 days' )->format( 'Y-m-d' );
		$to     = ( new \DateTimeImmutable( $today ) )->modify( '+13 months' )->format( 'Y-m-d' );
		$tz     = wp_timezone_string();
		$tz     = in_array( $tz, timezone_identifiers_list(), true ) ? $tz : 'Europe/Rome';
		$events = array();
		foreach ( self::occurrences( $from, $to ) as $o ) {
			if ( $activity_id && (int) $o['activity_id'] !== $activity_id ) {
				continue;
			}
			$events[] = array(
				'uid' => 'asem-' . ( $o['session_id'] ? 's' . $o['session_id'] : 'a' . $o['activity_id'] . '-' . $o['date'] ) . '@' . wp_parse_url( home_url(), PHP_URL_HOST ),
				'summary' => $o['title'], 'date' => $o['date'], 'start' => $o['start'], 'end' => $o['end'], 'location' => $o['location'],
			);
		}
		$name  = (string) Settings::get( 'association_name' );
		$act   = $activity_id ? Plugin::activities()->get( $activity_id ) : null;
		$title = $act ? $act['name'] : 'Corsi ed eventi';
		return Ics::calendar( ( '' !== $name ? $name . ' — ' : '' ) . $title, $events, $tz, gmdate( 'Ymd\THis\Z' ) );
	}

	/** Dopo quanti secondi il file ricordato si rifà comunque (oltre al cambio della generazione delle pagine pubbliche). */
	const FEED_TTL = 3600;

	/**
	 * Il file del calendario ricordato: si rifà solo quando cambia qualcosa (la «generazione» delle pagine pubbliche, che ogni scrittura su
	 * attività, date e lezioni spostate cambia), al cambio di giorno o dopo un'ora. Chi è collegato (ad esempio un dipendente a cui sono stati tolti
	 * dei tipi di attività) riceve sempre un file fresco e mai uno ricordato.
	 *
	 * @return array{t:int,b:string,e:string} momento di creazione, testo, ETag
	 */
	public static function feed_entry( ?int $activity_id = null ): array {
		if ( get_current_user_id() > 0 ) {
			$now = time();
			return array( 't' => $now, 'b' => self::ics( null, $activity_id ), 'e' => '' );
		}
		$gen     = PageCache::generation();
		$version = md5( wp_json_encode( array( $gen, Db::today(), (int) $activity_id, home_url( '/' ), wp_timezone_string(), determine_locale() ) ) );
		$key     = 'asem_ics_' . ( $activity_id ? 'a' . (int) $activity_id : 'all' );
		$stored  = get_transient( $key );
		if ( ! is_array( $stored ) || ( $stored['v'] ?? '' ) !== $version || ! isset( $stored['t'], $stored['b'] ) ) {
			$stored = array( 'v' => $version, 't' => time(), 'b' => self::ics( null, $activity_id ) );
			if ( PageCache::stored_generation() === $gen ) { // se nel frattempo i dati sono cambiati non si salva una copia vecchia
				set_transient( $key, $stored, self::FEED_TTL );
			}
		}
		return array( 't' => (int) $stored['t'], 'b' => (string) $stored['b'], 'e' => '"' . md5( $version . '|' . (int) $stored['t'] ) . '"' );
	}

	/** Il programma del telefono ha già questa versione? ETag o data di modifica uguali a quelle che ha inviato. */
	public static function not_modified( string $etag, int $modified, string $if_none_match, string $if_modified_since ): bool {
		if ( '' !== $if_none_match ) {
			if ( '' === $etag ) {
				return false;
			}
			foreach ( explode( ',', $if_none_match ) as $candidate ) {
				$candidate = trim( $candidate );
				if ( '*' === $candidate || ( 0 === strpos( $candidate, 'W/' ) ? substr( $candidate, 2 ) : $candidate ) === $etag ) {
					return true;
				}
			}
			return false;
		}
		if ( '' !== $if_modified_since && $modified > 0 ) {
			$since = strtotime( $if_modified_since );
			return false !== $since && $modified <= $since;
		}
		return false;
	}

	/** Risponde all'indirizzo segreto del calendario. */
	public static function serve(): void {
		if ( ! isset( $_GET[ self::QUERY_VAR ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$given = sanitize_text_field( wp_unslash( $_GET[ self::QUERY_VAR ] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! self::enabled() || ! hash_equals( self::token(), $given ) ) {
			status_header( 404 );
			exit;
		}
		$entry = self::feed_entry( isset( $_GET['a'] ) ? absint( $_GET['a'] ) : null ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- sola lettura pubblica, nessuna modifica
		nocache_headers();
		if ( '' !== $entry['e'] ) {
			header( 'ETag: ' . $entry['e'] );
			header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', $entry['t'] ) . ' GMT' );
			$inm = isset( $_SERVER['HTTP_IF_NONE_MATCH'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_IF_NONE_MATCH'] ) ) : '';
			$ims = isset( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ) : '';
			if ( self::not_modified( $entry['e'], $entry['t'], $inm, $ims ) ) {
				status_header( 304 );
				exit;
			}
		}
		header( 'Content-Type: text/calendar; charset=utf-8' );
		header( 'Content-Disposition: inline; filename="associazionesemplice.ics"' );
		Download::bytes( $entry['b'] );
		exit;
	}
}
