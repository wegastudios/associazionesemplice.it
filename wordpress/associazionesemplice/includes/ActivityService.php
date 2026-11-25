<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Attività, iscrizioni/prenotazioni e situazione pagamenti.
 *
 * Tre tipi (vedi {@see ActivityKind}), tutti gratuiti o con contributo, con contributo ospiti eventualmente diverso:
 *  - corso: iscrizione per mesi (`enrollments`), contributo mensile;
 *  - evento una tantum: una data (`sessions`), prenotazione obbligatoria (`bookings`), un contributo;
 *  - evento ricorrente: molte date, prenotazione obbligatoria al singolo evento, contributo per evento.
 * Le attività sono tenute solo da "soci e volontari"; vi partecipano soci e ospiti.
 */
class ActivityService {

	private function db(): \wpdb {
		return Db::db();
	}

	public function get( int $id ): ?array {
		$row = $this->db()->get_row( $this->db()->prepare( 'SELECT * FROM ' . Db::t( 'activities' ) . ' WHERE id = %d AND deleted_at IS NULL', $id ), ARRAY_A );
		return $row ?: null;
	}

	/** Attività di un anno sociale (etichetta "2025/2026"), con nome del referente. */
	public function for_year( string $label ): array {
		// Con l'anno sociale a gennaio («2026») le attività create prima, con l'etichetta «2025/2026», non spariscono: compaiono negli anni solari che toccano.
		$old = preg_match( '/^\d{4}$/', $label ) ? $label . '/%' : '';
		$old_end = '' !== $old ? '%/' . $label : '';
		return Employees::only_allowed_kinds( $this->db()->get_results(
			$this->db()->prepare(
				'SELECT a.*, CONCAT(i.first_name, " ", i.last_name) AS instructor_name FROM ' . Db::t( 'activities' ) . ' a '
				. 'LEFT JOIN ' . Db::t( 'people' ) . ' i ON i.id = a.instructor_person_id '
				. 'WHERE (a.social_year = %s OR (%s <> \'\' AND (a.social_year LIKE %s OR a.social_year LIKE %s))) AND a.deleted_at IS NULL ORDER BY a.name',
				$label,
				$old,
				'' === $old ? '-' : $old,
				'' === $old_end ? '-' : $old_end
			),
			ARRAY_A
		) ?: array() ); // un dipendente a cui sono stati tolti i corsi (o gli eventi) non li vede nemmeno negli elenchi
	}

	/** Contributo dovuto da una persona per questa attività (ospiti: contributo ospiti se impostato). */
	public function fee_for( array $activity, string $person_type ): int {
		return Pricing::fee_for(
			(int) $activity['fee_cents'],
			null === $activity['guest_fee_cents'] || '' === $activity['guest_fee_cents'] ? null : (int) $activity['guest_fee_cents'],
			$person_type
		);
	}

	// ---------- Attività ----------

	/**
	 * @param array $in name, social_year, kind, instructor_person_id, fee_cents (o monthly_fee_cents), guest_fee_cents (null = come i soci), notes
	 *                  e, per l'evento una tantum, session: [session_date, start_time?, location?, capacity?]
	 */
	public function create( array $in ): int {
		$in = $this->with_fee_alias( $in );
		$d  = $this->normalize( $in );
		$this->validate( $d );
		$session = null;
		if ( ActivityKind::EVENT === $d['kind'] ) {
			$session = $this->normalize_session( (array) ( $in['session'] ?? array() ) );
			$this->validate_session( $session );
			self::assert_in_social_year( $d['social_year'], (string) $session['session_date'], 'La data dell\'evento' ); // prima di scrivere: niente attività a metà
		}
		$this->db()->insert(
			Db::t( 'activities' ),
			array(
				'name'                 => $d['name'],
				'social_year'          => $d['social_year'],
				'kind'                 => $d['kind'],
				'instructor_person_id' => $d['instructor_person_id'],
				'fee_cents'            => $d['fee_cents'],
				'guest_fee_cents'      => $d['guest_fee_cents'],
				'price_lists'          => $d['price_lists'],
				'sport'                => $d['sport'],
				'discipline'           => $d['discipline'],
				'vat_rate'             => $d['vat_rate'],
				'hold_hours'           => ActivityKind::uses_sessions( $d['kind'] ) ? $d['hold_hours'] : 0,
				'cancellable'          => ActivityKind::uses_sessions( $d['kind'] ) ? $d['cancellable'] : 0,
				'cancel_policy'        => ActivityKind::uses_sessions( $d['kind'] ) ? $d['cancel_policy'] : null,
				'booking_qr'           => ActivityKind::uses_sessions( $d['kind'] ) ? $d['booking_qr'] : 0,
				'lesson_weekday'       => ActivityKind::COURSE === $d['kind'] ? $d['lesson_weekday'] : 0,
				'billing'              => ActivityKind::COURSE === $d['kind'] ? $d['billing'] : 'monthly',
				'trial_days'           => ActivityKind::COURSE === $d['kind'] ? $d['trial_days'] : 0,
				'red_days'             => ActivityKind::COURSE === $d['kind'] ? $d['red_days'] : 0,
				'first_month'          => $d['first_month'],
				'red_periods'          => ActivityKind::COURSE === $d['kind'] ? RedDays::periods_after( $d['red_periods'], 1 === $d['red_days'], Db::today() ) : '',
				'lesson_start'        => ActivityKind::COURSE === $d['kind'] ? $d['lesson_start'] : null,
				'lesson_slots'        => ActivityKind::COURSE === $d['kind'] ? $d['lesson_slots'] : null,
				'lesson_end'          => ActivityKind::COURSE === $d['kind'] ? $d['lesson_end'] : null,
				'location'            => ActivityKind::COURSE === $d['kind'] ? $d['location'] : null,
				'starts_on'           => ActivityKind::COURSE === $d['kind'] ? $d['starts_on'] : null,
				'ends_on'             => ActivityKind::COURSE === $d['kind'] ? $d['ends_on'] : null,
				'fund_mode'            => $d['fund_mode'],
				'fund_value'           => $d['fund_value'],
				'notes'                => $d['notes'],
				'created_at'           => Db::now(),
			)
		);
		$id = (int) $this->db()->insert_id;
		if ( $session ) {
			$this->insert_session( $id, $session );
		}
		do_action( 'asem_activity_created', $id ); // la pagina pubblica nasce da sola (PublicEvents)
		Audit::log( 'activity.created', 'activity', $id, array( 'name' => $d['name'], 'kind' => $d['kind'] ) );
		return $id;
	}

	/** Ci sono incassi registrati (non annullati) su questa attività? */
	public function has_income( int $id ): bool {
		return (int) $this->db()->get_var( $this->db()->prepare( 'SELECT COUNT(*) FROM ' . Db::t( 'transactions' ) . " WHERE activity_id = %d AND type = 'income' AND voided_at IS NULL", $id ) ) > 0;
	}

	public static function billing_locked_message(): string {
		return 'Ci sono già incassi su questo corso: per passare a un costo a lezioni (o toglierlo) chiudi questo corso e creane uno nuovo con «Riproponi».';
	}

	public function update( int $id, array $in ): void {
		$current = $this->get( $id );
		if ( ! $current ) {
			throw new \InvalidArgumentException( 'Attività non trovata.' );
		}
		unset( $current['monthly_fee_cents'] );
		if ( ! isset( $in['lesson_slots'] ) && ( isset( $in['lesson_weekday'] ) || isset( $in['lesson_start'] ) || isset( $in['lesson_end'] ) ) ) {
			unset( $current['lesson_slots'] ); // modifica con i campi singoli: ripartono da quelli
		}
		$d = $this->normalize( array_merge( $current, $this->with_fee_alias( $in ) ) );
		$d['kind'] = $current['kind']; // il tipo non si cambia dopo la creazione
		$this->validate( $d );
		if ( $d['billing'] !== $current['billing'] && in_array( 'lessons', array( $d['billing'], $current['billing'] ), true ) && $this->has_income( $id ) ) {
			throw new \InvalidArgumentException( self::billing_locked_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		$this->db()->update(
			Db::t( 'activities' ),
			array(
				'name'                 => $d['name'],
				'instructor_person_id' => $d['instructor_person_id'],
				'fee_cents'            => $d['fee_cents'],
				'guest_fee_cents'      => $d['guest_fee_cents'],
				'price_lists'          => $d['price_lists'],
				'sport'                => $d['sport'],
				'discipline'           => $d['discipline'],
				'vat_rate'             => $d['vat_rate'],
				'hold_hours'           => ActivityKind::uses_sessions( $d['kind'] ) ? $d['hold_hours'] : 0,
				'trial_days'           => ActivityKind::COURSE === $d['kind'] ? $d['trial_days'] : 0,
				'red_days'             => ActivityKind::COURSE === $d['kind'] ? $d['red_days'] : 0,
				'first_month'          => $d['first_month'],
				'red_periods'          => ActivityKind::COURSE === $d['kind'] ? RedDays::periods_after( $d['red_periods'], 1 === $d['red_days'], Db::today() ) : '',
				'cancellable'          => ActivityKind::uses_sessions( $current['kind'] ) ? $d['cancellable'] : 0,
				'cancel_policy'        => ActivityKind::uses_sessions( $current['kind'] ) ? $d['cancel_policy'] : null,
				'booking_qr'           => ActivityKind::uses_sessions( $current['kind'] ) ? $d['booking_qr'] : 0,
				'lesson_weekday'       => ActivityKind::COURSE === $d['kind'] ? $d['lesson_weekday'] : 0,
				'billing'              => ActivityKind::COURSE === $d['kind'] ? $d['billing'] : 'monthly',
				'lesson_start'        => ActivityKind::COURSE === $d['kind'] ? $d['lesson_start'] : null,
				'lesson_slots'        => ActivityKind::COURSE === $d['kind'] ? $d['lesson_slots'] : null,
				'lesson_end'          => ActivityKind::COURSE === $d['kind'] ? $d['lesson_end'] : null,
				'location'            => ActivityKind::COURSE === $d['kind'] ? $d['location'] : null,
				'starts_on'           => ActivityKind::COURSE === $d['kind'] ? $d['starts_on'] : null,
				'ends_on'             => ActivityKind::COURSE === $d['kind'] ? $d['ends_on'] : null,
				'fund_mode'            => $d['fund_mode'],
				'fund_value'           => $d['fund_value'],
				'notes'                => $d['notes'],
			),
			array( 'id' => $id )
		);
		Audit::log( 'activity.updated', 'activity', $id );
		do_action( 'asem_activity_updated', $id, (string) $current['name'] );
	}

	/** Compatibilità: `monthly_fee_cents` era il nome del contributo prima dei tipi di attività. */
	private function with_fee_alias( array $in ): array {
		if ( isset( $in['monthly_fee_cents'] ) && ! isset( $in['fee_cents'] ) ) {
			$in['fee_cents'] = $in['monthly_fee_cents'];
		}
		unset( $in['monthly_fee_cents'] );
		return $in;
	}

	private static function clean_time( $v ): ?string {
		$v = trim( (string) $v );
		return preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $v ) ? $v : null;
	}

	private static function clean_date( $v ): ?string {
		$v  = trim( (string) $v );
		$dt = \DateTime::createFromFormat( 'Y-m-d', $v );
		return $dt && $dt->format( 'Y-m-d' ) === $v ? $v : null;
	}

	/** Tutte le lezioni del corso: settimanali (type weekly: day 1..7, start, end, from, until) e date uniche (type single: date, start, end). */
	public static function lessons( array $a ): array {
		$raw = ! empty( $a['lesson_slots'] ) ? json_decode( (string) $a['lesson_slots'], true ) : null;
		if ( is_array( $raw ) ) {
			return self::clean_slots( array( 'lesson_slots' => $raw ) );
		}
		return self::clean_slots( $a );
	}

	/** Lezioni settimanali: [ ['day' => 1..7 (lunedì = 1), 'start' => 'HH:MM'|null, 'end' => 'HH:MM'|null, 'from' => data|null, 'until' => data|null], ... ] */
	public static function slots( array $a ): array {
		return array_values( array_filter( self::lessons( $a ), function ( $s ) {
			return 'weekly' === $s['type'];
		} ) );
	}

	/** Date uniche di un corso (non ricorrenti). */
	public static function lesson_dates( array $a ): array {
		return array_values( array_filter( self::lessons( $a ), function ( $s ) {
			return 'single' === $s['type'];
		} ) );
	}

	/** Giorni della settimana (1..7) in cui si tiene il corso. @return int[] */
	public static function slot_days( array $a ): array {
		return array_values( array_unique( array_map( function ( $s ) {
			return (int) $s['day'];
		}, self::slots( $a ) ) ) );
	}

	/** Le lezioni come righe del modulo: data, orario, ricorrente (con "ogni giorno della settimana") e data di fine. @return array[] */
	public static function schedule_rows( array $a, string $default_from ): array {
		$rows = array();
		foreach ( self::lessons( $a ) as $s ) {
			if ( 'single' === $s['type'] ) {
				$rows[] = array( 'date' => $s['date'], 'from' => (string) $s['start'], 'to' => (string) $s['end'], 'recurring' => false, 'end' => '', 'repeat' => 'weekly' );
				continue;
			}
			$from   = (string) ( $s['from'] ?: ( $a['starts_on'] ?: $default_from ) );
			$date   = Schedule::weekly_dates( $from, ( new \DateTimeImmutable( $from ) )->modify( '+6 days' )->format( 'Y-m-d' ), (int) $s['day'] ); // serve solo il primo giorno giusto: cade sempre nei sette giorni da $from
			$rows[] = array( 'date' => $date ? $date[0] : $default_from, 'from' => (string) $s['start'], 'to' => (string) $s['end'], 'recurring' => true, 'end' => (string) ( $s['until'] ?: '' ), 'repeat' => 'weekly' );
		}
		return $rows;
	}

	private static function clean_slots( array $in ): array {
		$raw = array();
		if ( isset( $in['lesson_slots'] ) ) {
			$src = is_string( $in['lesson_slots'] ) ? json_decode( $in['lesson_slots'], true ) : $in['lesson_slots'];
			$raw = is_array( $src ) ? $src : array();
		} elseif ( (int) ( $in['lesson_weekday'] ?? 0 ) >= 1 ) {
			$raw = array( array( 'day' => $in['lesson_weekday'], 'start' => $in['lesson_start'] ?? '', 'end' => $in['lesson_end'] ?? '' ) );
		}
		$out = array();
		foreach ( $raw as $s ) {
			$start = self::clean_time( $s['start'] ?? '' );
			$end   = self::clean_time( $s['end'] ?? '' );
			if ( 'single' === ( $s['type'] ?? '' ) ) {
				$date = self::clean_date( $s['date'] ?? '' );
				if ( $date ) {
					$out[ 's|' . $date . '|' . (string) $start ] = array( 'type' => 'single', 'date' => $date, 'start' => $start, 'end' => $end );
				}
				continue;
			}
			$day = (int) ( $s['day'] ?? 0 );
			if ( $day < 1 || $day > 7 ) {
				continue;
			}
			$from  = self::clean_date( $s['from'] ?? '' );
			$until = self::clean_date( $s['until'] ?? '' );
			$out[ 'w|' . $day . '|' . (string) $start . '|' . (string) $from ] = array( 'type' => 'weekly', 'day' => $day, 'start' => $start, 'end' => $end, 'from' => $from, 'until' => $until );
		}
		$out = array_values( $out );
		usort( $out, function ( $x, $y ) {
			$kx = 'weekly' === $x['type'] ? '0' . $x['day'] . (string) $x['start'] : '1' . $x['date'] . (string) $x['start'];
			$ky = 'weekly' === $y['type'] ? '0' . $y['day'] . (string) $y['start'] : '1' . $y['date'] . (string) $y['start'];
			return strcmp( $kx, $ky );
		} );
		return array_slice( $out, 0, 60 );
	}

	private function normalize( array $in ): array {
		$slots  = self::clean_slots( $in );
		$weekly = array_values( array_filter( $slots, function ( $s ) {
			return 'weekly' === $s['type'];
		} ) );
		$guest = null;
		if ( array_key_exists( 'guest_fee_cents', $in ) && null !== $in['guest_fee_cents'] && '' !== $in['guest_fee_cents'] ) {
			$guest = (int) $in['guest_fee_cents'];
		}
		return array(
			'name'                 => Text::plain( (string) ( $in['name'] ?? '' ) ),
			'social_year'          => trim( (string) ( $in['social_year'] ?? '' ) ),
			'kind'                 => (string) ( $in['kind'] ?? ActivityKind::COURSE ),
			'instructor_person_id' => ! empty( $in['instructor_person_id'] ) ? (int) $in['instructor_person_id'] : null,
			'fee_cents'            => (int) ( $in['fee_cents'] ?? 0 ),
			'guest_fee_cents'      => $guest,
			'price_lists'          => PriceLists::encode( PriceLists::clean( $in['price_lists'] ?? null ) ),
			'sport'                => SportCatalog::clean( (string) ( $in['sport'] ?? '' ) ),
			'discipline'           => '' === trim( (string) ( $in['discipline'] ?? '' ) ) ? null : mb_substr( trim( wp_strip_all_tags( (string) $in['discipline'] ) ), 0, 120 ),
			'vat_rate'             => Fiscal::clean_rate( $in['vat_rate'] ?? null ),
			'hold_hours'           => max( 0, min( 720, (int) ( $in['hold_hours'] ?? 0 ) ) ),
			'cancellable'          => ! empty( $in['cancellable'] ) ? 1 : 0,
			'cancel_policy'        => isset( $in['cancel_policy'] ) && CancelPolicy::is_valid( (string) $in['cancel_policy'] ) ? (string) $in['cancel_policy'] : null,
			'booking_qr'           => ! empty( $in['booking_qr'] ) ? 1 : 0,
			'lesson_weekday'       => $weekly ? $weekly[0]['day'] : 0,
			'slots'                => $slots,
			'lesson_slots'         => $slots ? wp_json_encode( $slots ) : null,
			'billing'              => isset( $in['billing'] ) && in_array( $in['billing'], array( 'once', 'quarterly', 'lessons' ), true ) ? (string) $in['billing'] : 'monthly',
			'red_days'             => empty( $in['red_days'] ) ? 0 : 1,
			'first_month'          => in_array( (string) ( $in['first_month'] ?? '' ), array( 'full', 'share' ), true ) ? (string) $in['first_month'] : Trials::first_month_default(),
			'red_periods'          => (string) ( $in['red_periods'] ?? '' ),
			'trial_days'           => max( 0, min( Trials::MAX_DAYS, (int) ( $in['trial_days'] ?? 0 ) ) ),
			'lesson_start'        => $weekly ? $weekly[0]['start'] : null,
			'lesson_end'          => $weekly ? $weekly[0]['end'] : null,
			'location'            => isset( $in['location'] ) && '' !== Text::plain( (string) $in['location'] ) ? mb_substr( Text::plain( (string) $in['location'] ), 0, 190 ) : null,
			'starts_on'           => self::clean_date( $in['starts_on'] ?? '' ),
			'ends_on'             => self::clean_date( $in['ends_on'] ?? '' ),
			'fund_mode'            => isset( $in['fund_mode'] ) && in_array( (string) $in['fund_mode'], array( FundShare::FIXED, FundShare::PERCENT ), true ) ? (string) $in['fund_mode'] : FundShare::NONE,
			'fund_value'           => max( 0, (int) ( $in['fund_value'] ?? 0 ) ),
			'notes'                => isset( $in['notes'] ) && '' !== Text::plain( (string) $in['notes'] ) ? Text::plain( (string) $in['notes'] ) : null,
		);
	}

	private function validate( array $d ): void {
		$instructor = $d['instructor_person_id'] ? Plugin::people()->get( $d['instructor_person_id'] ) : null;
		$errors     = Rules::validate_activity( $d, $instructor );
		$errors      = array_merge( $errors, FundShare::validate( $d['fund_mode'], $d['fund_value'], null !== $instructor ) );
		if ( ActivityKind::COURSE === $d['kind'] && 'lessons' === $d['billing'] && ! $d['slots'] ) {
			$errors[] = 'Per pagare a lezioni effettive servono i giorni delle lezioni: aggiungili in «Quando».';
		}
		foreach ( $d['slots'] as $slot ) {
			if ( $slot['start'] && $slot['end'] && $slot['end'] <= $slot['start'] ) {
				$errors[] = 'L\'orario di fine deve essere dopo quello di inizio.';
				break;
			}
		}
		if ( $d['starts_on'] && $d['ends_on'] && $d['ends_on'] < $d['starts_on'] ) {
			$errors[] = 'La data di fine del corso è prima di quella di inizio.';
		}
		foreach ( array( 'starts_on' => 'La data di inizio del corso', 'ends_on' => 'La data di fine del corso' ) as $k => $label ) { // un corso inizia e finisce nello stesso anno sociale
			if ( ! empty( $d[ $k ] ) ) {
				try {
					self::assert_in_social_year( (string) $d['social_year'], (string) $d[ $k ], $label );
				} catch ( \InvalidArgumentException $e ) {
					$errors[] = $e->getMessage();
					break;
				}
			}
		}
		if ( '' === $d['social_year'] ) {
			$errors[] = 'Scegli l\'anno delle attività.';
		}
		if ( $errors ) {
			throw new \InvalidArgumentException( implode( ' ', $errors ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
	}

	// ---------- Date (eventi) ----------

	private function normalize_session( array $s ): array {
		$cap = isset( $s['capacity'] ) && '' !== $s['capacity'] && null !== $s['capacity'] ? (int) $s['capacity'] : null;
		$t   = function ( $k ) use ( $s ) {
			$v = isset( $s[ $k ] ) ? trim( (string) $s[ $k ] ) : '';
			return '' === $v ? null : $v;
		};
		$end_date = $t( 'end_date' );
		if ( $end_date === (string) ( $s['session_date'] ?? '' ) ) {
			$end_date = null; // finisce lo stesso giorno: è come non averla
		}
		return array(
			'session_date' => (string) ( $s['session_date'] ?? '' ),
			'end_date'     => $end_date,
			'start_time'   => $t( 'start_time' ),
			'end_time'     => $t( 'end_time' ),
			'location'     => $t( 'location' ),
			'capacity'     => $cap,
			'notes'        => $t( 'notes' ),
		);
	}

	/**
	 * Si può prenotare online? Una data di un giorno solo, finché non è passata (anche il giorno stesso). Una data su più giorni
	 * (con fine in un giorno diverso dall'inizio) solo se inizia da domani in poi: dal giorno di inizio, e mentre è in corso, prenota la segreteria.
	 */
	public static function online_bookable( array $s ): bool {
		$start = (string) ( $s['session_date'] ?? '' );
		$today = current_time( 'Y-m-d' );
		$end   = (string) ( $s['end_date'] ?? '' );
		return '' !== $end && $end !== $start ? $start > $today : $start >= $today;
	}

	/** Ultimo giorno di una data: la data di fine se c'è, altrimenti il giorno stesso. Un evento è «passato» solo dopo questo giorno e «in corso» fino ad allora. */
	public static function last_day( array $s ): string {
		return '' !== (string) ( $s['end_date'] ?? '' ) ? (string) $s['end_date'] : (string) ( $s['session_date'] ?? '' );
	}

	private function validate_session( array $s ): void {
		$errors = Rules::validate_session( $s );
		if ( $errors ) {
			throw new \InvalidArgumentException( implode( ' ', $errors ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
	}

	/**
	 * Un corso o un evento inizia e finisce nello stesso anno sociale: ogni data deve cadere nell'anno sociale dell'attività.
	 *
	 * @throws \InvalidArgumentException
	 */
	public static function assert_in_social_year( string $social_year_label, string $date, string $what = 'La data' ): void {
		if ( '' === $date || '' === $social_year_label ) {
			return;
		}
		$sy   = SocialYear::from_label( $social_year_label, Settings::start_month() );
		$from = $sy->start()->format( 'Y-m-d' );
		$to   = $sy->end()->format( 'Y-m-d' );
		if ( $date < $from || $date > $to ) {
			throw new \InvalidArgumentException( $what . ' (' . ( new \DateTimeImmutable( $date ) )->format( 'd/m/Y' ) . ') deve cadere nell\'anno delle attività ' . $social_year_label . ' (dal ' . ( new \DateTimeImmutable( $from ) )->format( 'd/m/Y' ) . ' al ' . ( new \DateTimeImmutable( $to ) )->format( 'd/m/Y' ) . '): corsi ed eventi iniziano e finiscono nello stesso anno.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
	}

	private function insert_session( int $activity_id, array $s ): int {
		$act = $this->get( $activity_id );
		if ( $act ) {
			self::assert_in_social_year( (string) $act['social_year'], (string) ( $s['session_date'] ?? '' ), 'La data dell\'evento' );
			if ( ! empty( $s['end_date'] ) ) {
				self::assert_in_social_year( (string) $act['social_year'], (string) $s['end_date'], 'La data di fine dell\'evento' );
			}
		}
		$this->db()->insert( Db::t( 'sessions' ), array_merge( $s, array( 'activity_id' => $activity_id, 'created_at' => Db::now() ) ) );
		$id = (int) $this->db()->insert_id;
		Audit::log( 'session.created', 'activity', $activity_id, array( 'session' => $id, 'date' => $s['session_date'] ) );
		return $id;
	}

	public function session( int $id ): ?array {
		$row = $this->db()->get_row( $this->db()->prepare( 'SELECT * FROM ' . Db::t( 'sessions' ) . ' WHERE id = %d', $id ), ARRAY_A );
		return $row ?: null;
	}

	/** Date di un'attività, in ordine, con il numero di prenotazioni attive. */
	public function sessions( int $activity_id ): array {
		return $this->db()->get_results(
			$this->db()->prepare(
				'SELECT s.*, (SELECT COUNT(*) FROM ' . Db::t( 'bookings' ) . " b WHERE b.session_id = s.id AND b.status = 'booked') AS booked_count "
				. 'FROM ' . Db::t( 'sessions' ) . ' s WHERE s.activity_id = %d ORDER BY s.session_date, s.start_time, s.id',
				$activity_id
			),
			ARRAY_A
		) ?: array();
	}

	private function assert_uses_sessions( ?array $activity ): array {
		if ( ! $activity ) {
			throw new \InvalidArgumentException( 'Attività non trovata.' );
		}
		if ( ! ActivityKind::uses_sessions( $activity['kind'] ) ) {
			throw new \InvalidArgumentException( 'I corsi non hanno date: ci si iscrive per mesi.' );
		}
		return $activity;
	}

	public function add_session( int $activity_id, array $s ): int {
		$a = $this->assert_uses_sessions( $this->get( $activity_id ) );
		if ( ActivityKind::EVENT === $a['kind'] && $this->sessions( $activity_id ) ) {
			throw new \InvalidArgumentException( 'Un evento una tantum ha una sola data: modifica quella esistente.' );
		}
		$n = $this->normalize_session( $s );
		$this->validate_session( $n );
		$this->assert_dates_free( $activity_id, array( array( 'date' => $n['session_date'], 'end_date' => $n['end_date'], 'start' => $n['start_time'], 'end' => $n['end_time'] ) ) );
		return $this->insert_session( $activity_id, $n );
	}

	/** Le estensioni (risorse) possono rifiutare date che si sovrappongono a qualcosa. @throws \InvalidArgumentException */
	private function assert_dates_free( int $activity_id, array $dates ): void {
		$errors = (array) apply_filters( 'asem_session_errors', array(), $activity_id, $dates );
		if ( $errors ) {
			throw new \InvalidArgumentException( esc_html( implode( ' ', array_map( 'strval', $errors ) ) ) );
		}
	}

	public function update_session( int $session_id, array $s ): void {
		$cur = $this->session( $session_id );
		if ( ! $cur ) {
			throw new \InvalidArgumentException( 'Data non trovata.' );
		}
		$n = $this->normalize_session( array_merge( $cur, $s ) );
		$this->validate_session( $n );
		$act = $this->get( (int) $cur['activity_id'] );
		if ( $act && $n['session_date'] !== (string) $cur['session_date'] ) { // si controlla solo se la data cambia: le date già presenti non bloccano altre modifiche
			self::assert_in_social_year( (string) $act['social_year'], (string) $n['session_date'], 'La data dell\'evento' );
		}
		if ( $act && ! empty( $n['end_date'] ) && $n['end_date'] !== (string) ( $cur['end_date'] ?? '' ) ) {
			self::assert_in_social_year( (string) $act['social_year'], (string) $n['end_date'], 'La data di fine dell\'evento' );
		}
		if ( $n['session_date'] !== (string) $cur['session_date'] || $n['end_date'] !== ( $cur['end_date'] ?? null ) || $n['start_time'] !== $cur['start_time'] || $n['end_time'] !== $cur['end_time'] ) { // si controlla solo se cambia quando si tiene
			$this->assert_dates_free( (int) $cur['activity_id'], array( array( 'date' => $n['session_date'], 'end_date' => $n['end_date'], 'start' => $n['start_time'], 'end' => $n['end_time'] ) ) );
		}
		$this->db()->update( Db::t( 'sessions' ), $n, array( 'id' => $session_id ) );
		Audit::log( 'session.updated', 'activity', (int) $cur['activity_id'], array( 'session' => $session_id ) );
		Waitlist::promote( $session_id ); // più posti: entrano quelli in lista d'attesa
	}

	/**
	 * Aggiunge le date descritte da regole (date uniche, giorni ricorrenti con data di fine): vedi {@see Schedule::expand()}.
	 * Le date già presenti (stesso giorno e orario) non si duplicano. Un evento una tantum ha una sola data.
	 *
	 * @return int date create
	 */
	public function add_dates( int $activity_id, array $rows, ?string $location = null, ?int $capacity = null ): int {
		$a     = $this->assert_uses_sessions( $this->get( $activity_id ) );
		$dates = Schedule::expand( $rows );
		if ( ! $dates ) {
			throw new \InvalidArgumentException( 'Aggiungi almeno una data.' );
		}
		if ( ActivityKind::EVENT === $a['kind'] && ( count( $dates ) > 1 || $this->sessions( $activity_id ) ) ) {
			throw new \InvalidArgumentException( 'Un evento una tantum ha una sola data: per più date scegli «Evento ricorrente».' );
		}
		foreach ( $dates as $d ) { // tutte le date prima di scriverne una: o entrano tutte o nessuna
			self::assert_in_social_year( (string) $a['social_year'], (string) $d['date'], 'La data dell\'evento' );
			if ( ! empty( $d['end_date'] ) ) {
				self::assert_in_social_year( (string) $a['social_year'], (string) $d['end_date'], 'La data di fine' );
			}
		}
		$have = array();
		foreach ( $this->sessions( $activity_id ) as $s ) {
			$have[ $s['session_date'] . '|' . (string) $s['start_time'] ] = true;
		}
		$this->assert_dates_free( $activity_id, array_values( array_filter( $dates, function ( $d ) use ( $have ) {
			return ! isset( $have[ $d['date'] . '|' . (string) $d['start'] ] );
		} ) ) );
		$made = 0;
		foreach ( $dates as $d ) {
			if ( isset( $have[ $d['date'] . '|' . (string) $d['start'] ] ) ) {
				continue;
			}
			$n = $this->normalize_session( array( 'session_date' => $d['date'], 'start_time' => $d['start'], 'end_date' => $d['end_date'] ?? null, 'end_time' => $d['end'], 'location' => $location, 'capacity' => $capacity ) );
			$this->validate_session( $n );
			$this->insert_session( $activity_id, $n );
			$made++;
		}
		return $made;
	}

	/** Crea una data ogni 7 giorni da $from a $to compresi (max 120). @return int date create */
	public function generate_weekly( int $activity_id, string $from, string $to, ?string $time = null, ?string $location = null, ?int $capacity = null ): int {
		$a = $this->assert_uses_sessions( $this->get( $activity_id ) );
		self::assert_in_social_year( (string) $a['social_year'], $from, 'La prima data' );
		self::assert_in_social_year( (string) $a['social_year'], $to, 'L\'ultima data' );
		if ( ActivityKind::RECURRING !== $a['kind'] ) {
			throw new \InvalidArgumentException( 'La ricorrenza vale solo per gli eventi ricorrenti.' );
		}
		$base = array( 'start_time' => $time, 'location' => $location, 'capacity' => $capacity );
		$this->validate_session( $this->normalize_session( array_merge( $base, array( 'session_date' => $from ) ) ) );
		$this->validate_session( $this->normalize_session( array_merge( $base, array( 'session_date' => $to ) ) ) );
		$d   = new \DateTimeImmutable( $from );
		$end = new \DateTimeImmutable( $to );
		if ( $end < $d ) {
			throw new \InvalidArgumentException( 'La data finale è prima di quella iniziale.' );
		}
		$existing = array_column( $this->sessions( $activity_id ), 'session_date' );
		$new      = array();
		for ( $c = $d; $c <= $end && count( $new ) < 121; $c = $c->modify( '+7 days' ) ) {
			if ( ! in_array( $c->format( 'Y-m-d' ), $existing, true ) ) {
				$new[] = array( 'date' => $c->format( 'Y-m-d' ), 'start' => $time, 'end' => null );
			}
		}
		$this->assert_dates_free( $activity_id, $new );
		$made = 0;
		while ( $d <= $end ) {
			if ( $made >= 120 ) {
				throw new \InvalidArgumentException( 'Troppe date in una volta (massimo 120): accorcia il periodo.' );
			}
			$date = $d->format( 'Y-m-d' );
			if ( ! in_array( $date, $existing, true ) ) {
				$this->insert_session( $activity_id, $this->normalize_session( array_merge( $base, array( 'session_date' => $date ) ) ) );
				$made++;
			}
			$d = $d->modify( '+7 days' );
		}
		return $made;
	}

	/** Annulla una data. Le prenotazioni restano ma non contano più; eventuali pagamenti vanno rimborsati a mano. */
	public function cancel_session( int $session_id, bool $and_future = false ): int {
		$s = $this->session( $session_id );
		if ( ! $s ) {
			throw new \InvalidArgumentException( 'Data non trovata.' );
		}
		$this->db()->update( Db::t( 'sessions' ), array( 'cancelled_at' => Db::now() ), array( 'id' => $session_id ) );
		$n = 1;
		if ( $and_future ) { // questa e le successive; le date passate non si toccano
			$more = (int) $this->db()->query( $this->db()->prepare( 'UPDATE ' . Db::t( 'sessions' ) . ' SET cancelled_at = %s WHERE activity_id = %d AND session_date > %s AND cancelled_at IS NULL', Db::now(), (int) $s['activity_id'], (string) $s['session_date'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- tabella interna, valori preparati
			$n   += $more;
		}
		Audit::log( 'session.cancelled', 'activity', (int) $s['activity_id'], array( 'session' => $session_id, 'future' => $and_future ? $n - 1 : 0 ) );
		return $n;
	}

	// ---------- Prenotazioni ----------

	/**
	 * Prenota una persona a una data. Il contributo dovuto è fissato adesso (socio o ospite, oppure il listino scelto).
	 *
	 * @param array $opt price_list (chiave del listino), guest_of (PriceLists::GUEST_*: solo segreteria), use_package (true = scala un ingresso da un pacchetto già pagato)
	 */
	public function book( int $session_id, int $person_id, array $opt = array() ): int {
		$s = $this->session( $session_id );
		if ( ! $s || $s['cancelled_at'] ) {
			throw new \InvalidArgumentException( 'Questa data non è più disponibile.' );
		}
		$a      = $this->get( (int) $s['activity_id'] );
		$person = Plugin::people()->get( $person_id );
		if ( ! $a || ! $person ) {
			throw new \InvalidArgumentException( 'Attività o persona non trovata.' );
		}
		if ( empty( $opt['approved'] ) && ! current_user_can( Plugin::CAP ) && ! current_user_can( Plugin::CAP_OPS ) && PublicEvents::options( (int) $a['id'] )['approval'] ) { // evento su richiesta: la prenotazione diretta è della segreteria, oppure dopo l'approvazione
			throw new \InvalidArgumentException( 'Questo evento è su richiesta: chiedi di partecipare dalla pagina dell\'evento e la segreteria ti risponde.' );
		}
		$conflict = (string) apply_filters( 'asem_booking_errors', '', $session_id, $person_id ); // un'estensione (risorse) può rifiutare una fascia occupata da altro
		if ( '' !== $conflict ) {
			throw new \InvalidArgumentException( $conflict ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		if ( null !== $s['capacity'] && (int) ( $a['hold_hours'] ?? 0 ) > 0 ) {
			Holds::release_expired( $session_id ); // prima di dire «posti esauriti» si liberano le prenotazioni non pagate entro la tolleranza
		}
		$tbl = Db::t( 'bookings' );
		// Posti e doppie prenotazioni: controllo e scrittura sotto blocco, così due richieste insieme non superano la capienza.
		list( $id, $fee ) = Db::with_lock(
			'asem_book_' . $session_id,
			function () use ( $tbl, $s, $a, $person, $session_id, $person_id, $opt ) {
				$existing = $this->db()->get_row( $this->db()->prepare( "SELECT * FROM $tbl WHERE session_id = %d AND person_id = %d", $session_id, $person_id ), ARRAY_A );
				if ( $existing && 'booked' === $existing['status'] ) {
					throw new \InvalidArgumentException( 'Questa persona è già prenotata a questa data.' );
				}
				if ( null !== $s['capacity'] ) {
					$taken = (int) $this->db()->get_var( $this->db()->prepare( "SELECT COUNT(*) FROM $tbl WHERE session_id = %d AND status = 'booked'", $session_id ) );
					if ( $taken >= (int) $s['capacity'] ) {
						throw new \InvalidArgumentException( 'Posti esauriti per questa data (' . (int) $s['capacity'] . ').' );
					}
				}
				$price = $this->price_data( $a, $person, $opt );
				$fee   = $price['fee_due_cents'];
				if ( $existing ) {
					$this->db()->update( $tbl, array_merge( $price, array( 'status' => 'booked', 'cancelled_at' => null, 'transferred_to' => null, 'transferred_from' => null, 'booked_by' => null, 'declined_at' => null, 'confirmed_at' => null ) ), array( 'id' => (int) $existing['id'] ) );
					return array( (int) $existing['id'], $fee );
				}
				if ( ! $this->db()->insert( $tbl, array_merge( $price, array( 'session_id' => $session_id, 'person_id' => $person_id, 'status' => 'booked', 'created_at' => Db::now() ) ) ) ) {
					throw new \InvalidArgumentException( 'Prenotazione non riuscita: riprova.' );
				}
				return array( (int) $this->db()->insert_id, $fee );
			}
		);
		Audit::log( 'booking.created', 'activity', (int) $a['id'], array( 'session' => $session_id, 'person_id' => $person_id, 'fee' => $fee ) );
		return $id;
	}

	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- i messaggi sono interni e si mostrano solo dopo esc_html; il motivo è un codice fisso
	/**
	 * Controlli di una prenotazione di gruppo che NON dipendono da chi sono le persone: data e attività, evento su richiesta, massimo di posti per volta e
	 * posti liberi per tutte (indicativi: il controllo che vale si rifà sotto blocco). Vanno fatti PRIMA di cercare un altro socio, così nessun
	 * messaggio di questo elenco può rivelare qualcosa su di lui.
	 *
	 * @param int $count quante persone in tutto (anche quelle ancora da creare)
	 * @return array{0:array,1:array} data e attività
	 * @throws BookingFailure
	 */
	public function group_precheck( int $session_id, int $count, int $booker_id ): array {
		$s = $this->session( $session_id );
		if ( ! $s || $s['cancelled_at'] ) {
			throw new BookingFailure( 'Questa data non è più disponibile.', BookingFailure::GONE );
		}
		$a = $this->get( (int) $s['activity_id'] );
		if ( ! $a ) {
			throw new BookingFailure( 'Attività non trovata.', BookingFailure::GONE );
		}
		$opt = PublicEvents::options( (int) $a['id'] );
		if ( ! current_user_can( Plugin::CAP ) && ! current_user_can( Plugin::CAP_OPS ) && $opt['approval'] ) {
			throw new BookingFailure( 'Questo evento è su richiesta: chiedi di partecipare dalla pagina dell\'evento e la segreteria ti risponde.', BookingFailure::APPROVAL );
		}
		if ( (int) $opt['max_seats'] > 0 && $count > (int) $opt['max_seats'] ) {
			throw new BookingFailure( 'Per questo evento si possono prenotare al massimo ' . (int) $opt['max_seats'] . ' posti per volta.', BookingFailure::LIMIT );
		}
		$busy = (string) apply_filters( 'asem_booking_errors', '', $session_id, $booker_id ); // fascia occupata: dipende dalla data, si dice prima di cercare chiunque
		if ( '' !== $busy ) {
			throw new BookingFailure( $busy, BookingFailure::CONFLICT, $booker_id );
		}
		if ( null !== $s['capacity'] ) {
			if ( (int) ( $a['hold_hours'] ?? 0 ) > 0 ) {
				Holds::release_expired( $session_id );
			}
			$left = (int) $s['capacity'] - (int) $this->db()->get_var( $this->db()->prepare( 'SELECT COUNT(*) FROM ' . Db::t( 'bookings' ) . " WHERE session_id = %d AND status = 'booked'", $session_id ) );
			if ( $count > $left ) {
				throw new BookingFailure( $left <= 0 ? 'Posti esauriti per questa data (' . (int) $s['capacity'] . ').' : 'Non ci sono posti per tutti: ne restano ' . $left . '.', BookingFailure::FULL );
			}
		}
		return array( $s, $a );
	}

	/** Le tabelle che un gruppo scrive (prenotazioni e persone) permettono le transazioni (InnoDB)? Senza, non si può prenotare «tutto o niente» in sicurezza. */
	private function transactional(): bool {
		static $ok = null;
		if ( null === $ok ) {
			$n  = (int) $this->db()->get_var( $this->db()->prepare( "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (%s, %s) AND LOWER(ENGINE) = 'innodb'", Db::t( 'bookings' ), Db::t( 'people' ) ) );
			$ok = 2 === $n;
		}
		return $ok;
	}

	/** Motivo mostrato quando una prenotazione annullata conserva un versamento di chi la pagava prima. */
	const PAID_BY_ANOTHER = 'Per questa data c\'è una prenotazione precedente da sistemare: scrivi alla segreteria.';

	/**
	 * Un socio può prenotare per altri soci? No se i pagamenti online sono di un'estensione che non conosce chi paga al posto di chi partecipa
	 * ({@see OfflinePayments::books_payer()}): il gratuito si può aggiornare da solo, e con l'estensione di prima il pagamento finirebbe «da controllare».
	 */
	public static function others_allowed(): bool {
		return (bool) apply_filters( 'asem_book_others_allowed', Plugin::payments()->books_payer() );
	}

	/** Chi risponde del contributo di una prenotazione: chi l'ha fatta per altri, altrimenti la persona prenotata. */
	public static function debtor_id( array $booking ): int {
		return ! empty( $booking['booked_by'] ) ? (int) $booking['booked_by'] : (int) $booking['person_id'];
	}

	/**
	 * C'è una prenotazione non attiva con un versamento (non annullato) e a risponderne era un'altra persona? Se si riattivasse, il denaro di uno pagherebbe il
	 * debito dell'altro: prima la segreteria sistema. Per gli ospiti e i minori di chi prenotava non vale: pagava già chi ne risponde.
	 */
	public function paid_by_another( int $session_id, int $person_id, int $new_debtor ): bool {
		$b = $this->booking( $session_id, $person_id );
		if ( ! $b || 'booked' === $b['status'] ) {
			return false;
		}
		if ( empty( $b['booked_by'] ) && $new_debtor === $person_id ) {
			return false; // prenotazione normale che si rifà da sé: come sempre
		}
		$p    = Plugin::people()->get( $person_id );
		$host = $p ? (int) $p['host_person_id'] : 0;
		$same = function ( int $id ) use ( $person_id, $host ): bool {
			return $id === $person_id || ( $host > 0 && $id === $host ) || Guardians::is_guardian_of( $id, $person_id );
		};
		$managed = $host > 0 || Guardians::has_guardian( $person_id );
		// Conta chi ha versato davvero (il pagante della voce), non solo di chi era il debito: la segreteria può aver incassato da chi partecipa
		$payers = array_map( 'intval', $this->db()->get_col( $this->db()->prepare( 'SELECT DISTINCT COALESCE(payer_person_id, person_id) FROM ' . Db::t( 'transactions' ) . " WHERE type = 'income' AND voided_at IS NULL AND amount_cents > 0 AND session_id = %d AND person_id = %d", $session_id, $person_id ) ) );
		foreach ( $payers as $payer ) {
			if ( $payer !== $new_debtor && ! ( $managed && $same( $payer ) && $same( $new_debtor ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Prenota più persone insieme a una data, a nome di chi prenota ($booker_id): tutte o nessuna, in una sola transazione sotto lo stesso blocco
	 * dei posti (il blocco è obbligatorio: senza non si procede). Il COMMIT avviene PRIMA che il blocco si rilasci (lo rilascia with_lock all'uscita).
	 * Il contributo di ciascuno è fissato adesso e resta di chi prenota. Email e altre azioni esterne partono solo dopo, dal chiamante.
	 * I controlli sulle persone (regolamento, tessera, consenso dell'altro socio) sono del chiamante: qui posti, doppioni, evento su richiesta, estensioni e prezzo.
	 * Gli errori sono BookingFailure, con il motivo e la persona: il chiamante non deve leggere il testo.
	 *
	 * @param int[] $person_ids persone già esistenti da prenotare (senza ripetizioni)
	 * @param int   $booker_id  chi prenota; per la propria prenotazione il campo booked_by resta vuoto
	 * @param array $opt        price_lists: [person_id => chiave del listino]; new_people: callable che CREA dentro la transazione le persone nuove (ospiti) e
	 *                          restituisce [person_id => chiave del listino]; extra: quante sono (per i posti)
	 * @return array<int,int> person_id => id della prenotazione
	 * @throws BookingFailure
	 */
	public function book_many( int $session_id, array $person_ids, int $booker_id, array $opt = array() ): array {
		$person_ids = array_values( array_unique( array_map( 'intval', $person_ids ) ) );
		$extra      = max( 0, (int) ( $opt['extra'] ?? 0 ) );
		if ( ! $person_ids && ! $extra ) {
			throw new BookingFailure( 'Nessuna persona da prenotare.', BookingFailure::GONE );
		}
		list( $s, $a ) = $this->group_precheck( $session_id, count( $person_ids ) + $extra, $booker_id );
		$lists         = isset( $opt['price_lists'] ) && is_array( $opt['price_lists'] ) ? $opt['price_lists'] : array();
		$people        = array();
		foreach ( $person_ids as $pid ) {
			$people[ $pid ] = Plugin::people()->get( $pid );
			if ( ! $people[ $pid ] ) {
				throw new BookingFailure( 'Persona non trovata.', BookingFailure::GONE, $pid );
			}
			$conflict = (string) apply_filters( 'asem_booking_errors', '', $session_id, $pid ); // un'estensione (risorse) può rifiutare una fascia occupata da altro
			if ( '' !== $conflict ) {
				throw new BookingFailure( $conflict, BookingFailure::CONFLICT, $pid );
			}
		}
		if ( ! $this->transactional() ) {
			throw new BookingFailure( 'Questo sito non permette di prenotare più persone insieme in sicurezza: prenota una persona per volta.', BookingFailure::SYSTEM );
		}
		$tbl = Db::t( 'bookings' );
		$db  = $this->db();
		try {
			$out = Db::with_lock(
				'asem_book_' . $session_id,
				function () use ( $tbl, $db, $s, $a, $people, $lists, $session_id, $booker_id, $opt, $extra ) {
					$old = array();
					foreach ( $people as $pid => $person ) {
						$existing = $db->get_row( $db->prepare( "SELECT * FROM $tbl WHERE session_id = %d AND person_id = %d", $session_id, $pid ), ARRAY_A );
						if ( $existing && 'booked' === $existing['status'] ) {
							throw new BookingFailure( 'Questa persona ha già una prenotazione per questa data.', BookingFailure::ALREADY, $pid );
						}
						if ( $existing && ( ( ! empty( $existing['declined_at'] ) && (int) $pid !== $booker_id ) || $this->paid_by_another( $session_id, (int) $pid, $booker_id ) ) ) { // si è tolta, o c'è un versamento di chi pagava prima: si ricontrolla sotto blocco
							throw new BookingFailure( self::PAID_BY_ANOTHER, BookingFailure::ALREADY, $pid );
						}
						$old[ $pid ] = $existing;
					}
					if ( null !== $s['capacity'] ) {
						$taken = (int) $db->get_var( $db->prepare( "SELECT COUNT(*) FROM $tbl WHERE session_id = %d AND status = 'booked'", $session_id ) );
						if ( $taken + count( $people ) + $extra > (int) $s['capacity'] ) {
							throw new BookingFailure( 'Posti esauriti per questa data (' . (int) $s['capacity'] . ').', BookingFailure::FULL );
						}
					}
					return Plugin::ledger()->in_batch(
						function () use ( $tbl, $db, $a, $people, $old, $lists, $session_id, $booker_id, $opt ) {
							$all = $people;
							if ( ! empty( $opt['new_people'] ) && is_callable( $opt['new_people'] ) ) { // le persone nuove nascono dentro la transazione: se qualcosa fallisce non ne resta traccia
								foreach ( (array) call_user_func( $opt['new_people'] ) as $pid => $key ) {
									$all[ (int) $pid ]   = Plugin::people()->get( (int) $pid );
									$lists[ (int) $pid ] = (string) $key;
									$old[ (int) $pid ]   = null;
								}
							}
							$ids = array();
							foreach ( $all as $pid => $person ) {
								if ( ! $person ) {
									throw new BookingFailure( 'Persona non trovata.', BookingFailure::GONE, (int) $pid );
								}
								$row = array_merge( $this->price_data( $a, $person, array( 'price_list' => (string) ( $lists[ $pid ] ?? '' ) ) ), array( 'booked_by' => (int) $pid === $booker_id ? null : $booker_id, 'declined_at' => null, 'confirmed_at' => null ) );
								if ( ! empty( $old[ $pid ] ) ) {
									$ok          = false !== $db->update( $tbl, array_merge( $row, array( 'status' => 'booked', 'created_at' => Db::now(), 'cancelled_at' => null, 'transferred_to' => null, 'transferred_from' => null ) ), array( 'id' => (int) $old[ $pid ]['id'] ) ); // il tempo per pagare parte da adesso
									$ids[ $pid ] = (int) $old[ $pid ]['id'];
								} else {
									$ok          = (bool) $db->insert( $tbl, array_merge( $row, array( 'session_id' => $session_id, 'person_id' => (int) $pid, 'status' => 'booked', 'created_at' => Db::now() ) ) );
									$ids[ $pid ] = (int) $db->insert_id;
								}
								if ( ! $ok ) {
									throw new BookingFailure( 'Prenotazione non riuscita: riprova.', BookingFailure::SYSTEM, (int) $pid );
								}
							}
							if ( isset( $opt['expected'] ) ) { // il totale che chi prenota ha visto: se non torna non si prenota nessuno
								$sum = (int) $db->get_var( "SELECT COALESCE(SUM(fee_due_cents), 0) FROM $tbl WHERE id IN (" . implode( ',', array_map( 'intval', $ids ) ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- id interi
								if ( $sum !== (int) $opt['expected'] ) {
									throw new BookingFailure( 'Il contributo è cambiato nel frattempo: controlla di nuovo il riepilogo.', BookingFailure::SYSTEM );
								}
							}
							return $ids;
						}
					); // il COMMIT è già fatto qui, prima che with_lock rilasci il blocco
				},
				5,
				true // senza il blocco non si procede
			);
		} catch ( BookingFailure $e ) {
			throw $e;
		} catch ( \InvalidArgumentException $e ) { // «un'altra operazione è in corso», o un errore di prezzo/listino
			throw new BookingFailure( $e->getMessage(), BookingFailure::SYSTEM );
		} catch ( \RuntimeException $e ) { // transazione non avviata o non confermata
			throw new BookingFailure( 'Prenotazione non riuscita: riprova.', BookingFailure::SYSTEM );
		}
		foreach ( $out as $pid => $bid ) {
			Audit::log( 'booking.created', 'activity', (int) $a['id'], array( 'session' => $session_id, 'person_id' => $pid, 'fee' => (int) $db->get_var( $db->prepare( "SELECT fee_due_cents FROM $tbl WHERE id = %d", $bid ) ), 'booked_by' => $booker_id ) );
		}
		return $out;
	}

	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	/** Contributo (in centesimi) di una persona di questo tipo con questo listino: lo stesso calcolo della prenotazione, da mostrare PRIMA di confermare. */
	public function preview_cents( array $activity, string $type, string $list_key = '' ): int {
		return (int) $this->price_data( $activity, array( 'id' => 0, 'type' => $type ), array( 'price_list' => $list_key ) )['fee_due_cents'];
	}

	/** Ingressi già usati di un pacchetto (la prenotazione che l'ha pagato conta come uno). */
	public function package_used( int $paying_booking_id ): int {
		$tbl = Db::t( 'bookings' );
		return 1 + (int) $this->db()->get_var( $this->db()->prepare( "SELECT COUNT(*) FROM $tbl WHERE package_of = %d AND status = 'booked'", $paying_booking_id ) );
	}

	/**
	 * Chi ha un pacchetto pagato con ingressi ancora disponibili per un'attività: [{person_id, name, label, left, total}], per cognome.
	 * Serve alla segreteria per vedere, mentre prenota, chi ha giornate già pagate.
	 */
	public function packages_overview( int $activity_id ): array {
		$b   = Db::t( 'bookings' );
		$s   = Db::t( 'sessions' );
		$p   = Db::t( 'people' );
		$ids = $this->db()->get_col(
			$this->db()->prepare( "SELECT DISTINCT b.person_id FROM $b b JOIN $s s ON s.id = b.session_id WHERE s.activity_id = %d AND b.status = 'booked' AND b.package_entries > 1", $activity_id )
		) ?: array();
		$out = array();
		foreach ( $ids as $pid ) {
			$person = $this->db()->get_row( $this->db()->prepare( "SELECT first_name, last_name FROM $p WHERE id = %d", (int) $pid ), ARRAY_A );
			foreach ( $this->open_packages( (int) $pid, $activity_id ) as $pk ) {
				$total = (int) $this->db()->get_var( $this->db()->prepare( "SELECT package_entries FROM $b WHERE id = %d", (int) $pk['id'] ) );
				$out[] = array( 'person_id' => (int) $pid, 'name' => $person ? trim( $person['first_name'] . ' ' . $person['last_name'] ) : '', 'label' => $pk['label'], 'left' => (int) $pk['left'], 'total' => $total );
			}
		}
		usort(
			$out,
			function ( $a, $c ) {
				return strcasecmp( $a['name'], $c['name'] );
			}
		);
		return $out;
	}

	/** Tutti i pacchetti pagati di una persona con ingressi ancora disponibili, di ogni attività: [{activity_id, activity_name, label, left, total}]. */
	public function person_packages( int $person_id ): array {
		$b   = Db::t( 'bookings' );
		$s   = Db::t( 'sessions' );
		$ids = $this->db()->get_col(
			$this->db()->prepare( "SELECT DISTINCT s.activity_id FROM $b b JOIN $s s ON s.id = b.session_id WHERE b.person_id = %d AND b.status = 'booked' AND b.package_entries > 1", $person_id )
		) ?: array();
		$out = array();
		foreach ( $ids as $aid ) {
			$a = $this->get( (int) $aid );
			foreach ( $this->open_packages( $person_id, (int) $aid ) as $pk ) {
				$out[] = array( 'activity_id' => (int) $aid, 'activity_name' => $a ? $a['name'] : '', 'label' => $pk['label'], 'left' => (int) $pk['left'], 'total' => (int) $this->db()->get_var( $this->db()->prepare( "SELECT package_entries FROM $b WHERE id = %d", (int) $pk['id'] ) ) );
			}
		}
		return $out;
	}

	/** Pacchetti pagati da una persona per un'attività con ingressi ancora disponibili: [{id, label, left}]. */
	public function open_packages( int $person_id, int $activity_id ): array {
		$b   = Db::t( 'bookings' );
		$s   = Db::t( 'sessions' );
		$out = array();
		$rows = $this->db()->get_results(
			$this->db()->prepare( "SELECT b.id, b.price_list, b.package_entries FROM $b b JOIN $s s ON s.id = b.session_id WHERE b.person_id = %d AND s.activity_id = %d AND b.status = 'booked' AND b.package_entries > 1 ORDER BY b.id", $person_id, $activity_id ),
			ARRAY_A
		) ?: array();
		$a = $this->get( $activity_id );
		foreach ( $rows as $r ) {
			$left = (int) $r['package_entries'] - $this->package_used( (int) $r['id'] );
			if ( $left > 0 ) {
				$l     = $a ? PriceLists::find( $a, (string) $r['price_list'] ) : null;
				$out[] = array( 'id' => (int) $r['id'], 'label' => $l ? $l['label'] : 'Pacchetto', 'left' => $left );
			}
		}
		return $out;
	}

	/** Campi di prezzo di una prenotazione: listino, ospite della segreteria, pacchetto. */
	private function price_data( array $a, array $person, array $opt ): array {
		$out = array( 'fee_due_cents' => 0, 'price_list' => null, 'guest_of' => null, 'waived_cents' => 0, 'package_entries' => 0, 'package_of' => null );
		if ( ! empty( $opt['use_package'] ) ) {
			$pk = $this->open_packages( (int) $person['id'], (int) $a['id'] );
			if ( ! $pk ) {
				throw new \InvalidArgumentException( 'Questa persona non ha un pacchetto con ingressi disponibili per questa attività.' );
			}
			$out['package_of'] = $pk[0]['id'];
			$out['price_list'] = $this->db()->get_var( $this->db()->prepare( 'SELECT price_list FROM ' . Db::t( 'bookings' ) . ' WHERE id = %d', $pk[0]['id'] ) );
			return $out;
		}
		$price   = $this->fee_for( $a, $person['type'] );
		$entries = 0;
		$key     = (string) ( $opt['price_list'] ?? '' );
		if ( '' !== $key ) {
			$list = PriceLists::find( $a, $key );
			if ( ! $list ) {
				throw new \InvalidArgumentException( 'Listino non trovato per questa attività.' );
			}
			list( $price, $entries ) = PriceLists::price( $list );
			$out['price_list']       = $key;
			$out['package_entries']  = $entries > 1 ? $entries : 0;
		}
		$guest = (string) ( $opt['guest_of'] ?? '' );
		if ( '' !== $guest ) {
			if ( ! isset( PriceLists::guest_kinds()[ $guest ] ) ) {
				throw new \InvalidArgumentException( 'Tipo di ospite non valido.' );
			}
			$g                   = PriceLists::guest_due( $a, $price, $guest );
			$price               = $g['due'];
			$out['guest_of']     = $guest;
			$out['waived_cents'] = $g['waived'];
		}
		$out['fee_due_cents'] = $price;
		return $out;
	}

	/** Annulla la prenotazione. Gli eventuali pagamenti restano registrati (rimborso a mano). */
	public function cancel_booking( int $session_id, int $person_id ): void {
		$s = $this->session( $session_id );
		$this->db()->update( Db::t( 'bookings' ), array( 'status' => 'cancelled', 'cancelled_at' => Db::now() ), array( 'session_id' => $session_id, 'person_id' => $person_id ) );
		Audit::log( 'booking.cancelled', 'activity', $s ? (int) $s['activity_id'] : null, array( 'session' => $session_id, 'person_id' => $person_id ) );
		Waitlist::promote( $session_id ); // si è liberato un posto: entra il primo della lista d'attesa
	}

	private function booking_row( array $b, int $paid ): array {
		return array_merge(
			$b,
			array(
				'paid'      => $paid,
				'state'     => Pricing::booking_state( (int) $b['fee_due_cents'], $paid ),
				'remaining' => Pricing::remaining( (int) $b['fee_due_cents'], $paid ),
				'active'    => 'booked' === $b['status'],
			)
		);
	}

	/** Prenotazioni di una data con situazione pagamenti, attive per prime. */
	public function bookings_for_session( int $session_id ): array {
		$rows = $this->db()->get_results(
			$this->db()->prepare(
				'SELECT b.*, p.first_name, p.last_name, p.type, p.card_number, p.email, p.host_person_id, p.phone AS person_phone FROM ' . Db::t( 'bookings' ) . ' b '
				. 'JOIN ' . Db::t( 'people' ) . ' p ON p.id = b.person_id AND p.deleted_at IS NULL WHERE b.session_id = %d '
				. "ORDER BY (b.status = 'booked') DESC, p.last_name, p.first_name",
				$session_id
			),
			ARRAY_A
		) ?: array();
		$paid = array();
		foreach ( $this->db()->get_results(
			$this->db()->prepare( 'SELECT person_id, SUM(amount_cents + discount_cents) AS s FROM ' . Db::t( 'transactions' ) . " WHERE type = 'income' AND voided_at IS NULL AND session_id = %d GROUP BY person_id", $session_id ),
			ARRAY_A
		) ?: array() as $r ) {
			$paid[ (int) $r['person_id'] ] = (int) $r['s'];
		}
		return array_map(
			function ( $b ) use ( $paid ) {
				return $this->booking_row( $b, $paid[ (int) $b['person_id'] ] ?? 0 );
			},
			$rows
		);
	}

	/** Prenotazioni di una persona (con attività e data), dalla più recente. */
	public function bookings_for_person( int $person_id ): array {
		$rows = $this->db()->get_results(
			$this->db()->prepare(
				'SELECT b.*, s.session_date, s.end_date, s.start_time, s.location, s.cancelled_at AS session_cancelled_at, s.activity_id, a.name AS activity_name, a.kind, a.booking_qr '
				. 'FROM ' . Db::t( 'bookings' ) . ' b JOIN ' . Db::t( 'sessions' ) . ' s ON s.id = b.session_id '
				. 'JOIN ' . Db::t( 'activities' ) . ' a ON a.id = s.activity_id AND a.deleted_at IS NULL WHERE b.person_id = %d ORDER BY s.session_date DESC, s.id DESC',
				$person_id
			),
			ARRAY_A
		) ?: array();
		$paid = array();
		foreach ( $this->db()->get_results(
			$this->db()->prepare( 'SELECT session_id, SUM(amount_cents + discount_cents) AS s FROM ' . Db::t( 'transactions' ) . " WHERE type = 'income' AND voided_at IS NULL AND person_id = %d AND session_id IS NOT NULL GROUP BY session_id", $person_id ),
			ARRAY_A
		) ?: array() as $r ) {
			$paid[ (int) $r['session_id'] ] = (int) $r['s'];
		}
		return array_map(
			function ( $b ) use ( $paid ) {
				$row           = $this->booking_row( $b, $paid[ (int) $b['session_id'] ] ?? 0 );
				$row['active'] = $row['active'] && empty( $b['session_cancelled_at'] );
				return $row;
			},
			$rows
		);
	}

	/**
	 * Prenotazioni attive con un contributo ancora da pagare di cui risponde questa persona: le sue e quelle che ha fatto per altri (person_id = chi partecipa).
	 * Le prenotazioni che altri hanno fatto per lei non ci sono: il debito è di chi ha prenotato.
	 */
	public function unpaid_bookings_for_person( int $person_id ): array {
		return array_values(
			array_filter(
				array_merge( $this->bookings_for_person( $person_id ), $this->bookings_made_for_others( $person_id ) ),
				function ( $b ) use ( $person_id ) {
					return $b['active'] && $b['remaining'] > 0 && self::debtor_id( $b ) === $person_id;
				}
			)
		);
	}

	/** Le prenotazioni che questa persona ha fatto per altri, con gli stessi campi di {@see bookings_for_person()}. */
	private function bookings_made_for_others( int $person_id ): array {
		$rows = $this->db()->get_results(
			$this->db()->prepare(
				'SELECT b.*, s.session_date, s.end_date, s.start_time, s.location, s.cancelled_at AS session_cancelled_at, s.activity_id, a.name AS activity_name, a.kind, a.booking_qr, '
				. '(SELECT COALESCE(SUM(t.amount_cents + t.discount_cents), 0) FROM ' . Db::t( 'transactions' ) . " t WHERE t.type = 'income' AND t.voided_at IS NULL AND t.session_id = b.session_id AND t.person_id = b.person_id) AS paid_sum "
				. 'FROM ' . Db::t( 'bookings' ) . ' b JOIN ' . Db::t( 'sessions' ) . ' s ON s.id = b.session_id '
				. 'JOIN ' . Db::t( 'activities' ) . ' a ON a.id = s.activity_id AND a.deleted_at IS NULL WHERE b.booked_by = %d AND b.person_id <> %d ORDER BY s.session_date DESC, s.id DESC',
				$person_id,
				$person_id
			),
			ARRAY_A
		) ?: array();
		return array_map(
			function ( $b ) {
				$row           = $this->booking_row( $b, (int) $b['paid_sum'] );
				$row['active'] = $row['active'] && empty( $b['session_cancelled_at'] );
				return $row;
			},
			$rows
		);
	}

	/** Il contributo di questa prenotazione è a carico di un'altra persona (chi l'ha fatta)? */
	public static function owed_by_another( array $b ): bool {
		return self::debtor_id( $b ) !== (int) $b['person_id'];
	}

	/** Come si nomina chi partecipa a chi ne paga il contributo: le persone che gestisce col nome, un altro socio solo con la tessera. */
	public static function participant_name( array $participant, int $payer_id ): string {
		$managed = ! MemberType::is_member( (string) $participant['type'] ) || Guardians::is_guardian_of( $payer_id, (int) $participant['id'] ) || (int) $participant['id'] === $payer_id;
		return $managed ? trim( $participant['first_name'] . ' ' . $participant['last_name'] ) : 'socio con tessera n. ' . $participant['card_number'];
	}

	/**
	 * Prenotazione che un altro socio ha fatto per questo socio e che lui non ha ancora confermato: può togliersi sempre fino all'inizio, oppure confermarla.
	 * Gli ospiti e i minori di chi prenota non contano (li gestisce chi ne risponde).
	 */
	public static function booked_by_other_member( array $b ): bool {
		if ( empty( $b['booked_by'] ) || (int) $b['booked_by'] === (int) $b['person_id'] || ! empty( $b['confirmed_at'] ) ) {
			return false;
		}
		$p = Plugin::people()->get( (int) $b['person_id'] );
		return $p && MemberType::is_member( (string) $p['type'] ) && ! Guardians::is_guardian_of( (int) $b['booked_by'], (int) $b['person_id'] );
	}

	/** Prima dell'inizio e senza ingresso registrato? */
	private function before_start( array $s ): bool {
		return empty( $s['cancelled_at'] ) && CancelPolicy::can_transfer( $s['session_date'], $s['start_time'] ?: null, current_datetime() );
	}

	/**
	 * Il socio prenotato da un altro si toglie: sempre possibile fino all'inizio, anche se la regola di annullamento lo negherebbe. Il posto si libera e per questa data
	 * nessun altro lo può prenotare; il versamento, se c'è, resta da restituire a chi ha prenotato (lo fa la segreteria).
	 *
	 * @return array la prenotazione com'era, con 'paid' (centesimi già versati)
	 * @throws \InvalidArgumentException
	 */
	public function decline_booking( int $session_id, int $person_id ): array {
		$s = $this->session( $session_id );
		$b = $this->booking( $session_id, $person_id );
		if ( ! $s || ! $b || 'booked' !== $b['status'] || ! self::booked_by_other_member( $b ) ) {
			throw new \InvalidArgumentException( 'Prenotazione non trovata.' );
		}
		if ( ! $this->before_start( $s ) || ! empty( $b['checked_in_at'] ) ) {
			throw new \InvalidArgumentException( 'L\'evento è già iniziato.' );
		}
		$b['paid'] = $this->paid_cents( $session_id, $person_id );
		$n         = $this->db()->query( $this->db()->prepare( 'UPDATE ' . Db::t( 'bookings' ) . " SET declined_at = %s WHERE id = %d AND status = 'booked' AND confirmed_at IS NULL", Db::now(), (int) $b['id'] ) ); // solo se nel frattempo non è stata confermata o annullata
		if ( 1 !== (int) $n ) {
			throw new \InvalidArgumentException( 'Prenotazione non trovata.' );
		}
		$this->cancel_booking( $session_id, $person_id );
		Audit::log( 'booking.declined', 'activity', (int) $s['activity_id'], array( 'session' => $session_id, 'person_id' => $person_id ) );
		return $b;
	}

	/** Il socio prenotato da un altro conferma: da qui la prenotazione è sua, con le regole normali, e chi l'ha fatta non la annulla più. Il contributo resta di chi ha prenotato. */
	public function confirm_booking( int $session_id, int $person_id ): void {
		$s = $this->session( $session_id );
		$b = $this->booking( $session_id, $person_id );
		if ( ! $s || ! $b || 'booked' !== $b['status'] || ! self::booked_by_other_member( $b ) ) {
			throw new \InvalidArgumentException( 'Prenotazione non trovata.' );
		}
		if ( ! $this->before_start( $s ) ) {
			throw new \InvalidArgumentException( 'L\'evento è già iniziato.' );
		}
		$n = $this->db()->query( $this->db()->prepare( 'UPDATE ' . Db::t( 'bookings' ) . " SET confirmed_at = %s WHERE id = %d AND status = 'booked' AND confirmed_at IS NULL", Db::now(), (int) $b['id'] ) );
		if ( 1 !== (int) $n ) {
			throw new \InvalidArgumentException( 'Prenotazione non trovata.' );
		}
		Audit::log( 'booking.confirmed', 'activity', (int) $s['activity_id'], array( 'session' => $session_id, 'person_id' => $person_id ) );
	}

	/** Ha fatto prenotazioni ancora attive per altre persone a date future? (finché ci sono non si cancella né si rende anonima: il contributo è suo) */
	public function has_future_for_others( int $person_id ): bool {
		return (bool) $this->db()->get_var(
			$this->db()->prepare(
				'SELECT COUNT(*) FROM ' . Db::t( 'bookings' ) . ' b JOIN ' . Db::t( 'sessions' ) . " s ON s.id = b.session_id WHERE b.booked_by = %d AND b.person_id <> %d AND b.status = 'booked' AND s.session_date >= %s AND s.cancelled_at IS NULL",
				$person_id,
				$person_id,
				Db::today()
			)
		);
	}

	/** Versato (senza sconti) per la prenotazione di una persona a una data. */
	public function paid_cents( int $session_id, int $person_id ): int {
		return (int) $this->db()->get_var(
			$this->db()->prepare( 'SELECT COALESCE(SUM(amount_cents), 0) FROM ' . Db::t( 'transactions' ) . " WHERE type = 'income' AND voided_at IS NULL AND session_id = %d AND person_id = %d", $session_id, $person_id )
		);
	}

	/** Prenotazioni attive e future che questa persona ha fatto per altri soci e che loro non hanno ancora confermato. @return array[] con activity_name, session_date, start_time, card_number */
	public function booked_for_others( int $person_id ): array {
		$rows = $this->db()->get_results(
			$this->db()->prepare(
				'SELECT b.*, s.session_date, s.end_date, s.start_time, s.activity_id, a.name AS activity_name, p.card_number FROM ' . Db::t( 'bookings' ) . ' b '
				. 'JOIN ' . Db::t( 'sessions' ) . ' s ON s.id = b.session_id AND s.cancelled_at IS NULL JOIN ' . Db::t( 'activities' ) . ' a ON a.id = s.activity_id AND a.deleted_at IS NULL '
				. 'JOIN ' . Db::t( 'people' ) . " p ON p.id = b.person_id WHERE b.booked_by = %d AND b.person_id <> %d AND b.status = 'booked' AND b.confirmed_at IS NULL AND COALESCE(s.end_date, s.session_date) >= %s ORDER BY s.session_date, s.id, b.id",
				$person_id,
				$person_id,
				Db::today()
			),
			ARRAY_A
		) ?: array();
		return array_values( array_filter( $rows, array( __CLASS__, 'booked_by_other_member' ) ) );
	}

	/** La persona si è tolta da una prenotazione fatta da altri per questa data? Da allora nessun altro la può più prenotare per quella data. */
	public function declined_by_member( int $session_id, int $person_id ): bool {
		return (bool) $this->db()->get_var(
			$this->db()->prepare( 'SELECT COUNT(*) FROM ' . Db::t( 'bookings' ) . ' WHERE session_id = %d AND person_id = %d AND declined_at IS NOT NULL', $session_id, $person_id )
		);
	}

	/** True se la persona ha una prenotazione attiva per quella data. */
	public function has_active_booking( int $session_id, int $person_id ): bool {
		return (bool) $this->db()->get_var(
			$this->db()->prepare( 'SELECT COUNT(*) FROM ' . Db::t( 'bookings' ) . " WHERE session_id = %d AND person_id = %d AND status = 'booked'", $session_id, $person_id )
		);
	}

	// ---------- Partecipazioni degli ospiti (limite prima di doversi iscrivere) ----------

	/** Soglia di segnalazione: dopo quante partecipazioni un ospite viene evidenziato come da invitare a iscriversi (0 = nessuna segnalazione). Non blocca nulla. */
	public function guest_limit(): int {
		return max( 0, (int) Settings::get( 'guest_max_events' ) );
	}

	/**
	 * Tutto ciò a cui una persona partecipa: eventi prenotati (date non annullate) e corsi a cui è iscritta. Le più recenti per prime.
	 *
	 * @return array[] type (event|course), activity_id, activity_name, when (data o mese), session_id, checked_in (bool), ended (bool)
	 */
	public function participations( int $person_id ): array {
		$db  = $this->db();
		$out = array();
		foreach ( $db->get_results(
			$db->prepare(
				'SELECT b.session_id, b.checked_in_at, s.session_date, s.start_time, a.id AS activity_id, a.name FROM ' . Db::t( 'bookings' ) . ' b '
				. 'JOIN ' . Db::t( 'sessions' ) . ' s ON s.id = b.session_id AND s.cancelled_at IS NULL '
				. 'JOIN ' . Db::t( 'activities' ) . " a ON a.id = s.activity_id AND a.deleted_at IS NULL WHERE b.person_id = %d AND b.status = 'booked'",
				$person_id
			),
			ARRAY_A
		) ?: array() as $r ) {
			$out[] = array(
				'type' => 'event', 'activity_id' => (int) $r['activity_id'], 'activity_name' => $r['name'], 'when' => $r['session_date'], 'time' => $r['start_time'],
				'session_id' => (int) $r['session_id'], 'checked_in' => ! empty( $r['checked_in_at'] ), 'ended' => (string) ( $r['end_date'] ?? '' ) !== '' ? $r['end_date'] < current_time( 'Y-m-d' ) : $r['session_date'] < current_time( 'Y-m-d' ),
			);
		}
		foreach ( $db->get_results(
			$db->prepare(
				'SELECT e.activity_id, e.start_month, e.end_month, a.name FROM ' . Db::t( 'enrollments' ) . ' e JOIN ' . Db::t( 'activities' ) . ' a ON a.id = e.activity_id AND a.deleted_at IS NULL WHERE e.person_id = %d',
				$person_id
			),
			ARRAY_A
		) ?: array() as $r ) {
			$out[] = array(
				'type' => 'course', 'activity_id' => (int) $r['activity_id'], 'activity_name' => $r['name'], 'when' => $r['start_month'], 'time' => null,
				'session_id' => 0, 'checked_in' => false, 'ended' => null !== $r['end_month'],
			);
		}
		usort( $out, function ( $a, $b ) {
			return strcmp( (string) $b['when'], (string) $a['when'] );
		} );
		return $out;
	}

	/** @param int[] $person_ids @return array<int,int> persona => numero di partecipazioni (una sola interrogazione per più persone) */
	public function participation_counts( array $person_ids ): array {
		$ids = array_values( array_filter( array_map( 'intval', $person_ids ) ) );
		if ( ! $ids ) {
			return array();
		}
		$in  = implode( ',', $ids );
		$db  = $this->db();
		$out = array_fill_keys( $ids, 0 );
		foreach ( $db->get_results(
			'SELECT b.person_id, COUNT(*) AS n FROM ' . Db::t( 'bookings' ) . ' b JOIN ' . Db::t( 'sessions' ) . ' s ON s.id = b.session_id AND s.cancelled_at IS NULL '
			. 'JOIN ' . Db::t( 'activities' ) . " a ON a.id = s.activity_id AND a.deleted_at IS NULL WHERE b.status = 'booked' AND b.person_id IN ($in) GROUP BY b.person_id",
			ARRAY_A
		) ?: array() as $r ) {
			$out[ (int) $r['person_id'] ] += (int) $r['n'];
		}
		foreach ( $db->get_results(
			'SELECT e.person_id, COUNT(*) AS n FROM ' . Db::t( 'enrollments' ) . ' e JOIN ' . Db::t( 'activities' ) . " a ON a.id = e.activity_id AND a.deleted_at IS NULL WHERE e.person_id IN ($in) GROUP BY e.person_id",
			ARRAY_A
		) ?: array() as $r ) {
			$out[ (int) $r['person_id'] ] += (int) $r['n'];
		}
		return $out;
	}

	/**
	 * Tra le persone indicate, quelle con almeno una prenotazione attiva a una data non ancora finita (oggi compreso; date annullate e attività cancellate non contano).
	 * Serve a limitare «ospiti da invitare» a chi sta per tornare: gli altri non servono a nessuno nell'elenco.
	 *
	 * @param int[] $person_ids
	 * @return int[]
	 */
	public function with_future_booking( array $person_ids ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $person_ids ) ) ) );
		if ( ! $ids ) {
			return array();
		}
		$db = $this->db();
		$in = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$sql = 'SELECT DISTINCT b.person_id FROM ' . Db::t( 'bookings' ) . ' b JOIN ' . Db::t( 'sessions' ) . ' s ON s.id = b.session_id AND s.cancelled_at IS NULL '
			. 'JOIN ' . Db::t( 'activities' ) . " a ON a.id = s.activity_id AND a.deleted_at IS NULL WHERE b.status = 'booked' AND b.person_id IN ($in) AND COALESCE(s.end_date, s.session_date) >= %s";
		return array_map( 'intval', $db->get_col( $db->prepare( $sql, array_merge( $ids, array( current_time( 'Y-m-d' ) ) ) ) ) ?: array() ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- tabelle interne, segnaposto generati
	}

	/** Partecipazioni di una persona e soglia di segnalazione (nessun blocco). @return array count, max (soglia, 0 = nessuna segnalazione), flagged (ha raggiunto la soglia), items */
	public function guest_status( int $person_id ): array {
		$items = $this->participations( $person_id );
		$max   = $this->guest_limit();
		$n     = count( $items );
		return array( 'count' => $n, 'max' => $max, 'flagged' => $max > 0 && $n >= $max, 'items' => $items );
	}


	// ---------- Gestori dell'evento e registrazione degli ingressi ----------

	/** Soci abilitati alla gestione di un evento (oltre al referente e agli amministratori). */
	public function staff( int $activity_id ): array {
		return $this->db()->get_results(
			$this->db()->prepare(
				'SELECT s.id AS staff_id, p.id AS person_id, p.first_name, p.last_name, p.type, p.card_number, s.can_cash FROM ' . Db::t( 'activity_staff' ) . ' s '
				. 'JOIN ' . Db::t( 'people' ) . ' p ON p.id = s.person_id AND p.deleted_at IS NULL WHERE s.activity_id = %d ORDER BY p.last_name, p.first_name',
				$activity_id
			),
			ARRAY_A
		) ?: array();
	}

	public function is_staff( int $activity_id, int $person_id ): bool {
		return (bool) $this->db()->get_var(
			$this->db()->prepare( 'SELECT COUNT(*) FROM ' . Db::t( 'activity_staff' ) . ' WHERE activity_id = %d AND person_id = %d', $activity_id, $person_id )
		);
	}

	/** @throws \InvalidArgumentException */
	public function add_staff( int $activity_id, int $person_id, bool $can_cash = false ): void {
		$a = $this->get( $activity_id );
		$p = Plugin::people()->get( $person_id );
		if ( ! $a || ! ActivityKind::uses_sessions( $a['kind'] ) ) {
			throw new \InvalidArgumentException( 'I gestori si indicano per gli eventi e gli eventi ricorrenti.' );
		}
		if ( ! $p || ! MemberType::is_member( $p['type'] ) ) {
			throw new \InvalidArgumentException( 'Può gestire un evento solo un socio o volontario (non un ospite).' );
		}
		if ( $a['instructor_person_id'] && (int) $a['instructor_person_id'] === $person_id ) {
			throw new \InvalidArgumentException( 'È già il referente dell\'evento: ha già tutti i permessi.' );
		}
		if ( $this->is_staff( $activity_id, $person_id ) ) {
			throw new \InvalidArgumentException( 'È già tra i gestori dell\'evento.' );
		}
		$this->db()->insert( Db::t( 'activity_staff' ), array( 'activity_id' => $activity_id, 'person_id' => $person_id, 'can_cash' => $can_cash ? 1 : 0, 'created_at' => Db::now() ) );
		Audit::log( 'event_staff.added', 'activity', $activity_id, array( 'person' => $person_id, 'can_cash' => $can_cash ) );
	}

	/** Il gestore può incassare il biglietto sul posto? (il referente e gli amministratori lo possono sempre: qui si valuta solo lo staff indicato). */
	public function staff_can_cash( int $activity_id, int $person_id ): bool {
		return (bool) $this->db()->get_var(
			$this->db()->prepare( 'SELECT can_cash FROM ' . Db::t( 'activity_staff' ) . ' WHERE activity_id = %d AND person_id = %d', $activity_id, $person_id )
		);
	}

	public function set_staff_cash( int $activity_id, int $person_id, bool $can_cash ): void {
		$this->db()->update( Db::t( 'activity_staff' ), array( 'can_cash' => $can_cash ? 1 : 0 ), array( 'activity_id' => $activity_id, 'person_id' => $person_id ) );
		Audit::log( 'event_staff.cash', 'activity', $activity_id, array( 'person' => $person_id, 'can_cash' => $can_cash ) );
	}

	/** Posti di una data: capienza (null = illimitati), prenotati, liberi (null = illimitati). */
	public function seats( int $session_id ): array {
		$s = $this->session( $session_id );
		if ( ! $s ) {
			return array( 'capacity' => null, 'taken' => 0, 'free' => null );
		}
		$taken = (int) $this->db()->get_var( $this->db()->prepare( 'SELECT COUNT(*) FROM ' . Db::t( 'bookings' ) . " WHERE session_id = %d AND status = 'booked'", $session_id ) );
		$cap   = null === $s['capacity'] ? null : (int) $s['capacity'];
		return array( 'capacity' => $cap, 'taken' => $taken, 'free' => null === $cap ? null : max( 0, $cap - $taken ) );
	}

	public function remove_staff( int $activity_id, int $person_id ): void {
		$this->db()->delete( Db::t( 'activity_staff' ), array( 'activity_id' => $activity_id, 'person_id' => $person_id ) );
		Audit::log( 'event_staff.removed', 'activity', $activity_id, array( 'person' => $person_id ) );
	}

	/** Eventi (con date) che una persona gestisce: quelli che tiene come referente e quelli in cui è tra i gestori. @return int[] */
	public function managed_activity_ids( int $person_id ): array {
		$ids = $this->db()->get_col(
			$this->db()->prepare(
				'SELECT a.id FROM ' . Db::t( 'activities' ) . " a WHERE a.deleted_at IS NULL AND a.kind IN ('event','recurring') AND (a.instructor_person_id = %d OR a.id IN (SELECT activity_id FROM " . Db::t( 'activity_staff' ) . ' WHERE person_id = %d)) ORDER BY a.social_year DESC, a.name',
				$person_id,
				$person_id
			)
		) ?: array();
		return array_map( 'intval', $ids );
	}

	/**
	 * Registra (o annulla) l'ingresso di una persona prenotata a una data.
	 *
	 * @param bool $force consente di registrare anche in un giorno diverso da quello dell'evento (solo amministratori)
	 * @return array status (recorded|already|undone|none), at (ora, o null), invite (solo con recorded: l'avviso per chi registra, vuoto se non c'è)
	 * @throws \InvalidArgumentException
	 */
	public function check_in( int $session_id, int $person_id, bool $undo = false, bool $force = false ): array {
		$s = $this->session( $session_id );
		$b = $this->booking( $session_id, $person_id );
		if ( ! $s || ! $b || 'booked' !== $b['status'] ) {
			throw new \InvalidArgumentException( 'Nessuna prenotazione attiva per questa persona.' );
		}
		if ( ! empty( $s['cancelled_at'] ) ) {
			throw new \InvalidArgumentException( 'Questa data dell\'evento è stata annullata.' );
		}
		$tbl = Db::t( 'bookings' );
		if ( $undo ) {
			if ( empty( $b['checked_in_at'] ) ) {
				return array( 'status' => 'none', 'at' => null );
			}
			$this->db()->update( $tbl, array( 'checked_in_at' => null, 'checked_in_by' => null ), array( 'id' => (int) $b['id'] ) );
			Audit::log( 'checkin.undone', 'activity', (int) $s['activity_id'], array( 'session' => $session_id, 'person' => $person_id ) );
			return array( 'status' => 'undone', 'at' => null );
		}
		if ( ! empty( $b['checked_in_at'] ) ) {
			return array( 'status' => 'already', 'at' => $b['checked_in_at'] );
		}
		$today = current_time( 'Y-m-d' );
		$last  = (string) ( $s['end_date'] ?? '' ) !== '' ? (string) $s['end_date'] : (string) $s['session_date'];
		if ( ! $force && ( $today < (string) $s['session_date'] || $today > $last ) ) { // gli ingressi si registrano in uno qualunque dei giorni dell'evento
			$msg = $last !== (string) $s['session_date']
				? 'Gli ingressi si registrano nei giorni dell\'evento, dal ' . ( new \DateTimeImmutable( $s['session_date'] ) )->format( 'd/m/Y' ) . ' al ' . ( new \DateTimeImmutable( $last ) )->format( 'd/m/Y' ) . '.'
				: 'Gli ingressi si registrano nel giorno dell\'evento (' . ( new \DateTimeImmutable( $s['session_date'] ) )->format( 'd/m/Y' ) . ').';
			throw new \InvalidArgumentException( $msg ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		$now = Db::now();
		$this->db()->update( $tbl, array( 'checked_in_at' => $now, 'checked_in_by' => get_current_user_id() ?: null ), array( 'id' => (int) $b['id'] ) );
		Audit::log( 'checkin.recorded', 'activity', (int) $s['activity_id'], array( 'session' => $session_id, 'person' => $person_id ) );
		return array( 'status' => 'recorded', 'at' => $now, 'invite' => GuestInvite::on_check_in( $s, $person_id ) ); // l'avviso «da invitare ad associarsi» per un ospite non socio (vuoto per gli altri)
	}

	/** Persone distinte con una prenotazione attiva a una data non annullata di questa attività. */
	public function booked_people( int $activity_id ): array {
		return $this->db()->get_results(
			$this->db()->prepare(
				'SELECT DISTINCT p.id AS person_id, p.first_name, p.last_name, p.type, p.email FROM ' . Db::t( 'bookings' ) . ' b '
				. 'JOIN ' . Db::t( 'sessions' ) . ' s ON s.id = b.session_id AND s.cancelled_at IS NULL '
				. 'JOIN ' . Db::t( 'people' ) . " p ON p.id = b.person_id AND p.deleted_at IS NULL WHERE s.activity_id = %d AND b.status = 'booked' ORDER BY p.last_name, p.first_name",
				$activity_id
			),
			ARRAY_A
		) ?: array();
	}

	// ---------- Quanto si può ancora incassare ----------

	/** La persona non è (più) iscritta al corso nel mese indicato: iscrizione assente o terminata prima di quel mese. */
	public function enrollment_ended( int $activity_id, int $person_id, string $month ): bool {
		$enr = $this->db()->get_row( $this->db()->prepare( 'SELECT end_month FROM ' . Db::t( 'enrollments' ) . ' WHERE activity_id = %d AND person_id = %d', $activity_id, $person_id ), ARRAY_A );
		return ! $enr || ( ! empty( $enr['end_month'] ) && (string) $enr['end_month'] < $month );
	}

	/**
	 * Quanto manca da incassare per un'iscrizione a un corso nel mese (o nel periodo, per la quota a trimestre) indicato: contributo dell'iscritto
	 * (variante di listino compresa) meno ciò che è già stato versato, sconti compresi. Con la quota unica vale per tutta l'iscrizione.
	 * Null se la persona non è iscritta o il contributo non è definito: allora non si sa cosa sia dovuto e non si blocca nulla.
	 */
	public function enrollment_left( int $activity_id, int $person_id, string $month ): ?int {
		$activity = $this->get( $activity_id );
		$person   = Plugin::people()->get( $person_id );
		$enr      = $this->db()->get_row( $this->db()->prepare( 'SELECT * FROM ' . Db::t( 'enrollments' ) . ' WHERE activity_id = %d AND person_id = %d', $activity_id, $person_id ), ARRAY_A );
		if ( ! $activity || ! $person || ! $enr ) {
			return null;
		}
		$fee = $this->fee_for( $activity, (string) $person['type'] );
		if ( ! empty( $enr['price_list'] ) ) {
			$list = PriceLists::find( $activity, (string) $enr['price_list'] );
			$fee  = $list ? (int) $list['cents'] : $fee;
		}
		if ( $fee <= 0 ) {
			return null;
		}
		$paid = $this->paid_map( array( $activity_id ) )[ $activity_id ][ $person_id ] ?? array();
		if ( 'once' === ( $activity['billing'] ?? 'monthly' ) ) {
			return max( 0, $fee - (int) array_sum( $paid ) );
		}
		if ( CourseMonths::applies( $activity ) ) {
			return max( 0, CourseMonths::due( $activity, $enr, $fee, $month ) - (int) ( $paid[ $month ] ?? 0 ) );
		}
		$step = 'quarterly' === ( $activity['billing'] ?? 'monthly' ) ? 3 : 1;
		if ( $step > 1 ) {
			$paid  = PaymentCalc::group_by_period( $paid, (string) $enr['start_month'], $step );
			$keys  = array_keys( PaymentCalc::group_by_period( array( $month => 1 ), (string) $enr['start_month'], $step ) );
			$month = (string) reset( $keys );
		}
		if ( 1 === $step && Trials::trial_month( $enr ) === $month ) { // primo mese di chi ha fatto la prova: si paga dal giorno della prova
			$first = Trials::first_month_cents( $activity, $enr, $fee );
			$fee   = null === $first ? $fee : $first;
		}
		return max( 0, $fee - (int) ( $paid[ $month ] ?? 0 ) );
	}

	/**
	 * La quota prevista di un mese di corso per una persona, prima di incassarla: per il primo mese di chi ha fatto la prova si paga da quel giorno
	 * (iscritta o da iscrivere), altrimenti la quota intera.
	 */
	public function course_month_fee( array $activity, array $person, string $month ): int {
		$fee = $this->fee_for( $activity, (string) $person['type'] );
		$enr = $this->enrollments_of( (int) $person['id'] )[ (int) $activity['id'] ] ?? null;
		if ( $enr && ( null === $enr['end_month'] || $enr['end_month'] >= $month ) ) {
			if ( CourseMonths::applies( $activity ) ) {
				return CourseMonths::due( $activity, $enr, $this->enrollment_price( $activity, $enr, (string) $person['type'] ), $month );
			}
			if ( Trials::trial_month( $enr ) !== $month ) {
				return $fee;
			}
			if ( ! empty( $enr['price_list'] ) ) {
				$list = PriceLists::find( $activity, (string) $enr['price_list'] );
				$fee  = $list && ! empty( $enr['trial_from'] ) ? (int) $list['cents'] : $fee;
			}
			return Trials::first_month_cents( $activity, $enr, $fee ) ?? $fee;
		}
		return Trials::expected_cents( $activity, (int) $person['id'], $month, $fee );
	}

	/** Le iscrizioni di una persona, per corso: una lettura sola per richiesta (si svuota a ogni scrittura). @return array<int,array> */
	private function enrollments_of( int $person_id ): array {
		if ( ! RequestMemo::has( 'person_enrollments', $person_id ) ) {
			$map = array();
			foreach ( $this->db()->get_results( $this->db()->prepare( 'SELECT * FROM ' . Db::t( 'enrollments' ) . ' WHERE person_id = %d', $person_id ), ARRAY_A ) ?: array() as $r ) {
				$map[ (int) $r['activity_id'] ] = $r;
			}
			RequestMemo::put( 'person_enrollments', $person_id, $map, array( Db::t( 'enrollments' ) ) );
		}
		return RequestMemo::get( 'person_enrollments', $person_id );
	}

	/** Quanto manca da incassare per la prenotazione a una data di un evento (contributo dovuto meno il versato, sconti compresi). Null se non c'è una prenotazione con un contributo. */
	public function booking_left( int $session_id, int $person_id ): ?int {
		$b = $this->booking( $session_id, $person_id );
		if ( ! $b || (int) $b['fee_due_cents'] <= 0 ) {
			return null;
		}
		$paid = (int) $this->db()->get_var(
			$this->db()->prepare( 'SELECT COALESCE(SUM(amount_cents + discount_cents), 0) FROM ' . Db::t( 'transactions' ) . " WHERE type = 'income' AND voided_at IS NULL AND session_id = %d AND person_id = %d", $session_id, $person_id )
		);
		return max( 0, (int) $b['fee_due_cents'] - $paid );
	}

	// ---------- Cancellazione e cambio di nominativo ----------

	/** Riga di prenotazione grezza (anche annullata o trasferita). */
	public function booking( int $session_id, int $person_id ): ?array {
		$row = $this->db()->get_row(
			$this->db()->prepare( 'SELECT * FROM ' . Db::t( 'bookings' ) . ' WHERE session_id = %d AND person_id = %d', $session_id, $person_id ),
			ARRAY_A
		);
		return $row ?: null;
	}

	/**
	 * Cosa può fare il socio con una prenotazione attiva: annullarla (regole di {@see CancelPolicy}) e/o cambiare nominativo.
	 *
	 * @return array ['allowed'=>bool, 'reason'=>string, 'deadline'=>?\DateTimeImmutable, 'message'=>string, 'can_transfer'=>bool]
	 */
	public function cancellation_for( int $session_id, int $person_id ): array {
		$b = $this->booking( $session_id, $person_id );
		$s = $this->session( $session_id );
		$a = $s ? $this->get( (int) $s['activity_id'] ) : null;
		if ( ! $b || 'booked' !== $b['status'] || ! $s || ! $a ) {
			return array( 'allowed' => false, 'reason' => 'none', 'deadline' => null, 'message' => 'Prenotazione non trovata.', 'can_transfer' => false );
		}
		$now  = current_datetime();
		$eval = CancelPolicy::evaluate(
			(int) $b['fee_due_cents'],
			! empty( $a['cancellable'] ),
			$a['cancel_policy'] ?: null,
			(string) Settings::get( 'cancel_policy_default' ),
			$s['session_date'],
			$s['start_time'] ?: null,
			$now
		);
		$eval['message']      = CancelPolicy::message( $eval );
		$eval['can_transfer'] = CancelPolicy::can_transfer( $s['session_date'], $s['start_time'] ?: null, $now ) && ! self::booked_by_other_member( $b ); // un posto prenotato da altri si conferma prima di cederlo
		return $eval;
	}

	/**
	 * Cambia nominativo: la prenotazione (e quanto già pagato) passa a un'altra persona. Se il nuovo partecipante deve
	 * un contributo maggiore (es. un ospite) la differenza risulta "da pagare"; se è minore non c'è rimborso.
	 * I pagamenti già registrati vengono intestati al nuovo partecipante (con una nota nella descrizione).
	 *
	 * @param bool $enforce_time true per i soci dal sito (non dopo l'inizio dell'evento); false per l'amministratore
	 */
	public function transfer_booking( int $session_id, int $from_id, int $to_id, bool $enforce_time = true ): void {
		$s = $this->session( $session_id );
		if ( ! $s || $s['cancelled_at'] ) {
			throw new \InvalidArgumentException( 'Questa data non è più disponibile.' );
		}
		$a    = $this->get( (int) $s['activity_id'] );
		$from = $this->booking( $session_id, $from_id );
		$to   = Plugin::people()->get( $to_id );
		$from_p = Plugin::people()->get( $from_id );
		if ( ! $a || ! $from || 'booked' !== $from['status'] ) {
			throw new \InvalidArgumentException( 'La prenotazione da cambiare non è attiva.' );
		}
		if ( ! $to || ! $from_p ) {
			throw new \InvalidArgumentException( 'Persona non trovata.' );
		}
		if ( $from_id === $to_id ) {
			throw new \InvalidArgumentException( 'Scegli una persona diversa.' );
		}
		if ( $enforce_time && ! CancelPolicy::can_transfer( $s['session_date'], $s['start_time'] ?: null, current_datetime() ) ) {
			throw new \InvalidArgumentException( 'L\'evento è già iniziato: non si può più cedere il posto.' );
		}
		if ( $this->has_active_booking( $session_id, $to_id ) ) {
			throw new \InvalidArgumentException( $to['first_name'] . ' ha già una prenotazione per questa data.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		$tbl  = Db::t( 'bookings' );
		$fee  = $this->fee_for( $a, $to['type'] );
		$note = ' [posto ceduto da ' . trim( $from_p['first_name'] . ' ' . $from_p['last_name'] ) . ' a ' . trim( $to['first_name'] . ' ' . $to['last_name'] ) . ']';
		// la cessione riscrive persona e descrizione degli incassi del posto: se uno è in un esercizio chiuso non si può (il rendiconto è fotografato e la prima nota di un anno chiuso non si tocca)
		YearLock::assert_rows_open( 'transactions', 'tx_date', $this->db()->prepare( "session_id = %d AND person_id = %d AND type = 'income' AND voided_at IS NULL", $session_id, $from_id ) );
		$this->in_transaction(
			function () use ( $tbl, $session_id, $from, $from_id, $to_id, $fee, $note ) {
				$this->db()->update( $tbl, array( 'status' => 'transferred', 'transferred_to' => $to_id, 'cancelled_at' => Db::now() ), array( 'id' => (int) $from['id'] ) );
				$existing = $this->booking( $session_id, $to_id );
				$data     = array( 'status' => 'booked', 'fee_due_cents' => $fee, 'cancelled_at' => null, 'transferred_to' => null, 'transferred_from' => $from_id, 'booked_by' => null, 'declined_at' => null, 'confirmed_at' => null ); // chi riceve il posto ne risponde lui: il debito non resta a chi aveva prenotato per altri
				if ( $existing ) {
					$this->db()->update( $tbl, $data, array( 'id' => (int) $existing['id'] ) );
				} else {
					$this->db()->insert( $tbl, array_merge( $data, array( 'session_id' => $session_id, 'person_id' => $to_id, 'created_at' => Db::now() ) ) );
				}
				$this->db()->query(
					$this->db()->prepare(
						'UPDATE ' . Db::t( 'transactions' ) . " SET person_id = %d, description = LEFT(CONCAT(description, %s), 255) WHERE session_id = %d AND person_id = %d AND type = 'income' AND voided_at IS NULL",
						$to_id,
						$note,
						$session_id,
						$from_id
					)
				);
			}
		);
		Audit::log( 'booking.transferred', 'activity', (int) $a['id'], array( 'session' => $session_id, 'from' => $from_id, 'to' => $to_id, 'fee' => $fee ) );
	}

	/** Una sola transazione per tutto il plugin (quella della prima nota): dentro un'operazione unica ci si unisce, invece di confermarla a metà. */
	private function in_transaction( callable $fn ) {
		return Plugin::ledger()->in_batch( $fn );
	}

	// ---------- Corsi: iscrizioni per mese ----------

	private function assert_month( string $m ): void {
		if ( ! preg_match( '/^\d{4}-(0[1-9]|1[0-2])$/', $m ) ) {
			throw new \InvalidArgumentException( 'Mese non valido: ' . $m ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
	}

	/** Iscrive (o riattiva) una persona a un CORSO: le mensilità sono dovute da $start_month. */
	public function enroll( int $activity_id, int $person_id, string $start_month, ?string $pay_from = null ): void {
		$this->assert_month( $start_month );
		$a = $this->get( $activity_id );
		if ( ! $a ) {
			throw new \InvalidArgumentException( 'Attività non trovata.' );
		}
		if ( ActivityKind::COURSE !== $a['kind'] ) {
			throw new \InvalidArgumentException( 'Agli eventi ci si prenota a una data: usa la prenotazione.' );
		}
		$enrollee = Plugin::people()->get( $person_id );
		if ( ! $enrollee ) {
			throw new \InvalidArgumentException( 'Persona non trovata.' );
		}
		$pay_from = self::clean_pay_from( $a, $pay_from );
		$tbl   = Db::t( 'enrollments' );
		$row   = $this->db()->get_row( $this->db()->prepare( "SELECT * FROM $tbl WHERE activity_id = %d AND person_id = %d", $activity_id, $person_id ), ARRAY_A );
		$trial = Trials::enrollment_fields( $a, $person_id, $start_month, $pay_from ); // a mese iniziato si paga in proporzione, se il corso lo prevede
		$covered = $row && $row['start_month'] <= $start_month && ( null === $row['end_month'] || $row['end_month'] >= $start_month );
		if ( $covered || ( $row && ! empty( $row['trial_from'] ) && Trials::trial_month( $row ) >= $start_month ) ) {
			$trial = array(); // il mese era già di questa iscrizione: ciò che è dovuto non cambia
		} elseif ( ! empty( $trial['trial_from'] ) ) { // incassi già registrati in quel mese (iscrizione tolta e rifatta): il dovuto non scende sotto il versato
			$paid = (int) ( $this->paid_map( array( $activity_id ) )[ $activity_id ][ $person_id ][ $start_month ] ?? 0 );
			if ( $paid > 0 ) {
				$trial = Trials::at_least_paid( $a, $trial, $this->fee_for( $a, (string) $enrollee['type'] ) * ( CourseMonths::applies( $a ) ? (int) $trial['trial_total'] : 1 ), $paid );
			}
		}
		if ( $row ) {
			$this->db()->update( $tbl, array( 'start_month' => $start_month, 'end_month' => null ) + $trial, array( 'id' => (int) $row['id'] ) );
		} else {
			$this->db()->insert( $tbl, array( 'activity_id' => $activity_id, 'person_id' => $person_id, 'start_month' => $start_month, 'created_at' => Db::now() ) + $trial );
		}
		if ( CourseMonths::applies( $a ) && ! $covered ) { // iscrizione nuova o ricominciata: i dovuti rimasti da prima, senza versamenti, si rifanno
			CourseMonths::restart( $activity_id, $person_id, $start_month, array_keys( array_filter( $this->paid_map( array( $activity_id ) )[ $activity_id ][ $person_id ] ?? array() ) ) );
		}
		$log = array( 'person_id' => $person_id, 'from' => $start_month );
		if ( null !== $pay_from && Db::today() !== $pay_from && substr( $pay_from, 0, 7 ) === $start_month && Trials::shares( $a ) ) {
			$log['pay_from'] = $pay_from; // giorno di partenza scelto a mano, diverso da oggi
		}
		Audit::log( 'activity.enrolled', 'activity', $activity_id, $log );
	}

	/** Il giorno di partenza scelto: una data vera, dentro l'anno delle attività del corso e non in un anno fiscale chiuso. Vuoto = nessuno. @throws \InvalidArgumentException */
	private static function clean_pay_from( array $a, ?string $date ): ?string {
		$date = trim( (string) $date );
		if ( '' === $date ) {
			return null;
		}
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			throw new \InvalidArgumentException( 'Il giorno da cui si paga non è una data valida.' );
		}
		self::assert_in_social_year( (string) $a['social_year'], $date, 'Il giorno da cui si paga' );
		YearLock::assert_date_open( $date );
		return $date;
	}

	/**
	 * La variante del contributo (listino) e la nota libera di un'iscrizione: la sceglie la segreteria, il socio paga la quota base.
	 *
	 * @throws \InvalidArgumentException
	 */
	public function set_enrollment_price( int $activity_id, int $person_id, string $price_list, string $note = '' ): void {
		$a = $this->get( $activity_id );
		if ( ! $a || ActivityKind::COURSE !== $a['kind'] ) {
			throw new \InvalidArgumentException( 'Le varianti del contributo valgono per i corsi.' );
		}
		$price_list = sanitize_key( $price_list );
		if ( '' !== $price_list && ! PriceLists::find( $a, $price_list ) ) {
			throw new \InvalidArgumentException( 'Variante del contributo non trovata: controlla i listini del corso.' );
		}
		$n = $this->db()->update(
			Db::t( 'enrollments' ),
			array( 'price_list' => '' === $price_list ? null : $price_list, 'fee_note' => '' === trim( $note ) ? null : mb_substr( trim( $note ), 0, 190 ) ),
			array( 'activity_id' => $activity_id, 'person_id' => $person_id )
		);
		if ( false === $n ) {
			throw new \InvalidArgumentException( 'Variante non salvata: riprova.' );
		}
		Audit::log( 'activity.fee_variant', 'activity', $activity_id, array( 'person_id' => $person_id, 'price_list' => $price_list ) );
	}

	/**
	 * «Non rinnova»: l'iscritto resta nel corso fino alla fine del periodo già pagato (o del mese in corso) e dopo non deve più nulla.
	 * Non si restituisce niente: il periodo pagato è fruito.
	 *
	 * @return string ultimo mese dovuto (YYYY-MM)
	 */
	public function stop_renewal( int $activity_id, int $person_id ): string {
		$last = substr( Db::today(), 0, 7 );
		foreach ( $this->status_for_activity( $activity_id ) as $row ) {
			if ( (int) $row['enrollment']['person_id'] !== $person_id ) {
				continue;
			}
			foreach ( (array) ( $row['summary']['months'] ?? array() ) as $mo ) {
				if ( (int) $mo['paid'] > 0 && (string) $mo['month'] > $last ) {
					$last = (string) $mo['month']; // pagato in anticipo: resta fino a quel mese
				}
			}
		}
		$this->cancel( $activity_id, $person_id, $last );
		return $last;
	}

	/** Cancella da un corso: $last_month è l'ultimo mese ancora dovuto. I pagamenti restano registrati. */
	public function cancel( int $activity_id, int $person_id, string $last_month ): void {
		$this->assert_month( $last_month );
		$this->db()->update( Db::t( 'enrollments' ), array( 'end_month' => $last_month ), array( 'activity_id' => $activity_id, 'person_id' => $person_id ) );
		Audit::log( 'activity.unenrolled', 'activity', $activity_id, array( 'person_id' => $person_id, 'last_month' => $last_month ) );
	}

	/** Corsi a cui la persona è iscritta (iscrizione attiva). */
	public function active_activity_ids( int $person_id ): array {
		return array_map(
			'intval',
			$this->db()->get_col( $this->db()->prepare( 'SELECT activity_id FROM ' . Db::t( 'enrollments' ) . ' WHERE person_id = %d AND end_month IS NULL', $person_id ) )
		);
	}

	/** Partecipanti: iscritti attivi per i corsi, persone distinte con una prenotazione attiva per gli eventi. */
	public function active_participants( int $activity_id ): int {
		$a = $this->get( $activity_id );
		if ( $a && ActivityKind::uses_sessions( $a['kind'] ) ) {
			return (int) $this->db()->get_var(
				$this->db()->prepare(
					'SELECT COUNT(DISTINCT b.person_id) FROM ' . Db::t( 'bookings' ) . ' b JOIN ' . Db::t( 'sessions' ) . " s ON s.id = b.session_id AND s.cancelled_at IS NULL WHERE s.activity_id = %d AND b.status = 'booked'",
					$activity_id
				)
			);
		}
		return (int) $this->db()->get_var( $this->db()->prepare( 'SELECT COUNT(*) FROM ' . Db::t( 'enrollments' ) . ' WHERE activity_id = %d AND end_month IS NULL', $activity_id ) );
	}

	/**
	 * Partecipanti di più attività insieme: gli stessi numeri di {@see active_participants()} con due interrogazioni in tutto (una per gli eventi, una per i corsi)
	 * invece di due per attività. Servono alle righe con i totali (Bacheca, elenco delle attività).
	 *
	 * @param array[] $activities righe di attività (con id e kind)
	 * @return array<int,int> attività => partecipanti
	 */
	public function active_participants_many( array $activities ): array {
		$out    = array();
		$events = array();
		$course = array();
		foreach ( $activities as $a ) {
			$id         = (int) $a['id'];
			$out[ $id ] = 0;
			if ( ActivityKind::uses_sessions( (string) $a['kind'] ) ) {
				$events[] = $id;
			} else {
				$course[] = $id;
			}
		}
		if ( $events ) {
			foreach ( $this->db()->get_results(
				'SELECT s.activity_id, COUNT(DISTINCT b.person_id) AS n FROM ' . Db::t( 'bookings' ) . ' b JOIN ' . Db::t( 'sessions' ) . " s ON s.id = b.session_id AND s.cancelled_at IS NULL WHERE b.status = 'booked' AND s.activity_id IN (" . implode( ',', $events ) . ') GROUP BY s.activity_id', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- id interi
				ARRAY_A
			) ?: array() as $r ) {
				$out[ (int) $r['activity_id'] ] = (int) $r['n'];
			}
		}
		if ( $course ) {
			foreach ( $this->db()->get_results(
				'SELECT activity_id, COUNT(*) AS n FROM ' . Db::t( 'enrollments' ) . ' WHERE end_month IS NULL AND activity_id IN (' . implode( ',', $course ) . ') GROUP BY activity_id', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- id interi
				ARRAY_A
			) ?: array() as $r ) {
				$out[ (int) $r['activity_id'] ] = (int) $r['n'];
			}
		}
		return $out;
	}

	/**
	 * Le date di più attività insieme, come {@see sessions()} (stesso ordine e stesse colonne) ma con una sola interrogazione.
	 *
	 * @param int[] $activity_ids
	 * @return array<int,array[]> attività => date
	 */
	public function sessions_many( array $activity_ids ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $activity_ids ) ) ) );
		$out = array_fill_keys( $ids, array() );
		if ( ! $ids ) {
			return $out;
		}
		foreach ( $this->db()->get_results(
			'SELECT s.*, (SELECT COUNT(*) FROM ' . Db::t( 'bookings' ) . " b WHERE b.session_id = s.id AND b.status = 'booked') AS booked_count "
			. 'FROM ' . Db::t( 'sessions' ) . ' s WHERE s.activity_id IN (' . implode( ',', $ids ) . ') ORDER BY s.activity_id, s.session_date, s.start_time, s.id', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- id interi
			ARRAY_A
		) ?: array() as $r ) {
			$out[ (int) $r['activity_id'] ][] = $r;
		}
		return $out;
	}

	/**
	 * Le prenotazioni di più date insieme, come {@see bookings_for_session()} (stesse colonne, stessa situazione dei pagamenti, attive per prime) con due interrogazioni in tutto.
	 *
	 * @param int[] $session_ids
	 * @return array<int,array[]> data => prenotazioni
	 */
	public function bookings_for_sessions( array $session_ids ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $session_ids ) ) ) );
		$out = array_fill_keys( $ids, array() );
		if ( ! $ids ) {
			return $out;
		}
		$in   = implode( ',', $ids );
		$rows = $this->db()->get_results(
			'SELECT b.*, p.first_name, p.last_name, p.type, p.card_number, p.email, p.host_person_id, p.phone AS person_phone FROM ' . Db::t( 'bookings' ) . ' b '
			. 'JOIN ' . Db::t( 'people' ) . " p ON p.id = b.person_id AND p.deleted_at IS NULL WHERE b.session_id IN ($in) " // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- id interi
			. "ORDER BY b.session_id, (b.status = 'booked') DESC, p.last_name, p.first_name, b.person_id",
			ARRAY_A
		) ?: array();
		$paid = array();
		foreach ( $this->db()->get_results(
			'SELECT session_id, person_id, SUM(amount_cents + discount_cents) AS s FROM ' . Db::t( 'transactions' ) . " WHERE type = 'income' AND voided_at IS NULL AND session_id IN ($in) GROUP BY session_id, person_id", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- id interi
			ARRAY_A
		) ?: array() as $r ) {
			$paid[ (int) $r['session_id'] ][ (int) $r['person_id'] ] = (int) $r['s'];
		}
		foreach ( $rows as $b ) {
			$out[ (int) $b['session_id'] ][] = $this->booking_row( $b, $paid[ (int) $b['session_id'] ][ (int) $b['person_id'] ] ?? 0 );
		}
		return $out;
	}

	/**
	 * Gli iscritti in corso (iscrizione non finita prima del mese $ym) di più corsi insieme, nell'ordine di persona di ciascun corso.
	 *
	 * @param int[] $activity_ids
	 * @return array<int,int[]> corso => id delle persone
	 */
	public function current_enrollees_many( array $activity_ids, string $ym ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $activity_ids ) ) ) );
		$out = array_fill_keys( $ids, array() );
		if ( ! $ids ) {
			return $out;
		}
		$until = $this->db()->prepare( ' AND (end_month IS NULL OR end_month >= %s)', $ym );
		foreach ( $this->db()->get_results(
			'SELECT activity_id, person_id FROM ' . Db::t( 'enrollments' ) . ' WHERE activity_id IN (' . implode( ',', $ids ) . ')' . $until . ' ORDER BY activity_id, person_id', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- id interi e condizione già preparata
			ARRAY_A
		) ?: array() as $r ) {
			$out[ (int) $r['activity_id'] ][] = (int) $r['person_id'];
		}
		return $out;
	}

	// ---------- Corsi: situazione pagamenti ----------

	/** @return array ['activity_id' => ['person_id' => ['YYYY-MM' => centesimi]]] (solo incassi senza data di evento) */
	private function paid_map( array $activity_ids ): array {
		if ( ! $activity_ids ) {
			return array();
		}
		$ids  = implode( ',', array_map( 'intval', $activity_ids ) );
		$rows = $this->db()->get_results(
			"SELECT activity_id, person_id, COALESCE(competence_month, DATE_FORMAT(tx_date, '%Y-%m')) AS ym, SUM(amount_cents + discount_cents) AS s "
			. 'FROM ' . Db::t( 'transactions' ) . " WHERE type = 'income' AND voided_at IS NULL AND session_id IS NULL AND person_id IS NOT NULL AND activity_id IN ($ids) "
			. 'GROUP BY activity_id, person_id, ym',
			ARRAY_A
		) ?: array();
		$out = array();
		foreach ( $rows as $r ) {
			$out[ (int) $r['activity_id'] ][ (int) $r['person_id'] ][ $r['ym'] ] = (int) $r['s'];
		}
		return $out;
	}

	/** La quota di un'iscrizione: la variante scelta dalla segreteria (2° corso, famiglia, attività parziale…) o quella della persona. Nei corsi a lezioni è il prezzo di una. */
	private function enrollment_price( array $activity, array $enrollment, string $person_type ): int {
		$fee = $this->fee_for( $activity, $person_type );
		if ( ! empty( $enrollment['price_list'] ) ) {
			$list = PriceLists::find( $activity, (string) $enrollment['price_list'] );
			$fee  = $list ? (int) $list['cents'] : $fee;
		}
		return $fee;
	}

	private function summarize( array $activity, array $enrollment, array $paid_by_month, string $person_type ): array {
		$fee = $this->enrollment_price( $activity, $enrollment, $person_type );
		if ( 'once' === ( $activity['billing'] ?? 'monthly' ) ) {
			return PaymentCalc::compute_once( $fee, $enrollment['start_month'], $enrollment['end_month'], $paid_by_month );
		}
		$end = $enrollment['end_month'];
		if ( ! empty( $activity['ends_on'] ) ) { // il corso ha una data di fine: dopo quel mese non si rinnova più
			$last = substr( (string) $activity['ends_on'], 0, 7 );
			$end  = null === $end ? $last : min( $end, $last );
		}
		$now   = substr( Db::today(), 0, 7 );
		$year  = SocialYear::from_label( $activity['social_year'], Settings::start_month() );
		$map   = null;
		$first = array();
		if ( CourseMonths::applies( $activity ) ) {
			$map = array();
			foreach ( $year->months() as $m ) {
				if ( $fee > 0 && $m >= $enrollment['start_month'] && $m <= $now && ( null === $end || $m <= $end ) ) {
					$map[ $m ] = CourseMonths::due( $activity, $enrollment, $fee, $m );
				}
			}
		}
		if ( ( null === $end || $now <= $end ) && ( self::slot_days( $activity ) || null !== $map ) ) { // il mese in corso è dovuto dalla prima lezione vera (festivi, chiusure e lezioni annullate compresi)
			$f = CourseMonths::first_lesson( $activity, $now );
			if ( null !== $f ) {
				$first[ $now ] = $f;
			}
		}
		return PaymentCalc::compute(
			$fee,
			$enrollment['start_month'],
			$end,
			$now,
			$year,
			$paid_by_month,
			self::slot_days( $activity ),
			Db::today(),
			'quarterly' === ( $activity['billing'] ?? 'monthly' ) ? 3 : 1,
			Trials::first_month_cents( $activity, $enrollment, $fee ),
			Trials::trial_month( $enrollment ),
			$map,
			$first
		);
	}

	/** Iscritti (attivi e cancellati) di un corso con situazione pagamenti, attivi per primi. */
	public function status_for_activity( int $activity_id ): array {
		$activity = $this->get( $activity_id );
		if ( ! $activity ) {
			return array();
		}
		$rows = $this->db()->get_results(
			$this->db()->prepare(
				'SELECT e.*, p.first_name, p.last_name, p.type, p.card_number, p.email, p.phone, p.host_person_id FROM ' . Db::t( 'enrollments' ) . ' e '
				. 'JOIN ' . Db::t( 'people' ) . ' p ON p.id = e.person_id AND p.deleted_at IS NULL WHERE e.activity_id = %d',
				$activity_id
			),
			ARRAY_A
		) ?: array();
		return $this->status_rows( $activity, $rows, $this->paid_map( array( $activity_id ) ) );
	}

	/**
	 * Come {@see status_for_activity()} per più corsi insieme (le righe di attività sono quelle già lette, ad esempio da for_year): tre interrogazioni in tutto
	 * invece di tre per corso. Stesso risultato per ogni corso, stesso ordine degli iscritti.
	 *
	 * @param array[] $activities righe di attività
	 * @return array<int,array[]> corso => iscritti con la situazione pagamenti
	 */
	public function status_for_activities( array $activities ): array {
		$by_id = array();
		foreach ( $activities as $a ) {
			$by_id[ (int) $a['id'] ] = $a;
		}
		$out = array_fill_keys( array_keys( $by_id ), array() );
		if ( ! $by_id ) {
			return $out;
		}
		$rows = array();
		foreach ( $this->db()->get_results(
			'SELECT e.*, p.first_name, p.last_name, p.type, p.card_number, p.email, p.phone, p.host_person_id FROM ' . Db::t( 'enrollments' ) . ' e '
			. 'JOIN ' . Db::t( 'people' ) . ' p ON p.id = e.person_id AND p.deleted_at IS NULL WHERE e.activity_id IN (' . implode( ',', array_keys( $by_id ) ) . ') ORDER BY e.activity_id, e.person_id', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- id interi
			ARRAY_A
		) ?: array() as $e ) {
			$rows[ (int) $e['activity_id'] ][] = $e;
		}
		$paid = $this->paid_map( array_keys( $by_id ) );
		foreach ( $by_id as $id => $a ) {
			$out[ $id ] = $this->status_rows( $a, $rows[ $id ] ?? array(), $paid );
		}
		return $out;
	}

	/** Le righe degli iscritti di un corso con la situazione pagamenti, attivi per primi (in comune a {@see status_for_activity()} e {@see status_for_activities()}). */
	private function status_rows( array $activity, array $rows, array $paid ): array {
		$activity_id = (int) $activity['id'];
		$out         = array();
		foreach ( $rows as $e ) {
			$out[] = array(
				'enrollment' => $e,
				'activity'   => $activity,
				'summary'    => $this->summarize( $activity, $e, $paid[ $activity_id ][ (int) $e['person_id'] ] ?? array(), $e['type'] ),
			);
		}
		usort(
			$out,
			function ( $a, $b ) {
				$act = ( null === $a['enrollment']['end_month'] ? 0 : 1 ) <=> ( null === $b['enrollment']['end_month'] ? 0 : 1 );
				return $act ?: strcmp( Text::lower( $a['enrollment']['last_name'] . $a['enrollment']['first_name'] ), Text::lower( $b['enrollment']['last_name'] . $b['enrollment']['first_name'] ) );
			}
		);
		return $out;
	}

	/** Corsi di una persona con la sua situazione pagamenti, dal più recente. */
	public function status_for_person( int $person_id ): array {
		$person = Plugin::people()->get( $person_id );
		if ( ! $person ) {
			return array();
		}
		$rows = $this->db()->get_results(
			$this->db()->prepare(
				'SELECT e.*, a.name AS activity_name, a.social_year, a.kind, a.fee_cents, a.guest_fee_cents, a.lesson_weekday, a.lesson_slots, a.billing, a.price_lists, a.starts_on, a.ends_on, a.red_days, a.red_periods, a.lesson_start, a.lesson_end FROM ' . Db::t( 'enrollments' ) . ' e '
				. 'JOIN ' . Db::t( 'activities' ) . ' a ON a.id = e.activity_id AND a.deleted_at IS NULL WHERE e.person_id = %d ORDER BY a.social_year DESC, a.name',
				$person_id
			),
			ARRAY_A
		) ?: array();
		$paid = $this->paid_map( array_column( $rows, 'activity_id' ) );
		$out  = array();
		foreach ( $rows as $e ) {
			$activity = array(
				'id' => $e['activity_id'], 'name' => $e['activity_name'], 'social_year' => $e['social_year'], 'kind' => $e['kind'],
				'fee_cents' => $e['fee_cents'], 'guest_fee_cents' => $e['guest_fee_cents'], 'lesson_weekday' => $e['lesson_weekday'], 'lesson_slots' => $e['lesson_slots'], 'billing' => $e['billing'], 'price_lists' => $e['price_lists'], 'ends_on' => $e['ends_on'],
				'starts_on' => $e['starts_on'], 'red_days' => $e['red_days'], 'red_periods' => $e['red_periods'], 'lesson_start' => $e['lesson_start'], 'lesson_end' => $e['lesson_end'],
			);
			$out[]    = array(
				'enrollment' => $e,
				'activity'   => $activity,
				'summary'    => $this->summarize( $activity, $e, $paid[ (int) $e['activity_id'] ][ $person_id ] ?? array(), $person['type'] ),
			);
		}
		return $out;
	}

	/** Primo mese dovuto e non (del tutto) pagato di un corso: serve a proporlo in fase di incasso. */
	public function first_unpaid_month( int $activity_id, int $person_id ): ?string {
		foreach ( $this->status_for_activity( $activity_id ) as $s ) {
			if ( (int) $s['enrollment']['person_id'] === $person_id && null === $s['enrollment']['end_month'] ) {
				return $s['summary']['unpaid_months'][0]['month'] ?? null;
			}
		}
		return null;
	}

	// ---------- Per l'area soci e i contenuti riservati ----------

	/** Attività a cui la persona partecipa: corsi con iscrizione non conclusa, eventi con una prenotazione attiva. */
	public function person_activity_ids( int $person_id ): array {
		$ym      = substr( Db::today(), 0, 7 );
		$courses = $this->db()->get_col(
			$this->db()->prepare( 'SELECT activity_id FROM ' . Db::t( 'enrollments' ) . ' WHERE person_id = %d AND (end_month IS NULL OR end_month >= %s)', $person_id, $ym )
		);
		$events  = $this->db()->get_col(
			$this->db()->prepare(
				'SELECT DISTINCT s.activity_id FROM ' . Db::t( 'bookings' ) . ' b JOIN ' . Db::t( 'sessions' ) . " s ON s.id = b.session_id AND s.cancelled_at IS NULL WHERE b.person_id = %d AND b.status = 'booked'",
				$person_id
			)
		);
		return array_values( array_unique( array_map( 'intval', array_merge( $courses, $events ) ) ) );
	}

	/** Attività tenute da una persona (referente). */
	public function taught_activity_ids( int $person_id ): array {
		return array_map(
			'intval',
			$this->db()->get_col( $this->db()->prepare( 'SELECT id FROM ' . Db::t( 'activities' ) . ' WHERE instructor_person_id = %d AND deleted_at IS NULL', $person_id ) )
		);
	}

	/** Tutte le attività (per le liste di scelta), dalle più recenti. */
	public function all_for_select(): array {
		return $this->db()->get_results( 'SELECT id, name, social_year, kind FROM ' . Db::t( 'activities' ) . ' WHERE deleted_at IS NULL ORDER BY social_year DESC, name', ARRAY_A ) ?: array();
	}

	/** Date non annullate di un giorno, con i dati dell'attività. */
	public function sessions_on( string $date ): array {
		return $this->db()->get_results(
			$this->db()->prepare(
				'SELECT s.*, a.name AS activity_name, a.kind, a.fee_cents, a.guest_fee_cents FROM ' . Db::t( 'sessions' ) . ' s JOIN ' . Db::t( 'activities' ) . ' a ON a.id = s.activity_id AND a.deleted_at IS NULL '
				. 'WHERE s.cancelled_at IS NULL AND s.session_date = %s ORDER BY s.start_time, s.id',
				$date
			),
			ARRAY_A
		) ?: array();
	}

	/** Date non annullate che toccano l'intervallo tra due giorni (inclusi: una data su più giorni compare se anche solo un giorno cade dentro), in ordine, con i dati dell'attività. */
	public function sessions_between( string $from, string $to, int $limit = 500 ): array {
		return $this->db()->get_results(
			$this->db()->prepare(
				'SELECT s.*, a.name AS activity_name, a.kind, a.fee_cents, a.guest_fee_cents FROM ' . Db::t( 'sessions' ) . ' s JOIN ' . Db::t( 'activities' ) . ' a ON a.id = s.activity_id AND a.deleted_at IS NULL '
				. 'WHERE s.cancelled_at IS NULL AND COALESCE(s.end_date, s.session_date) >= %s AND s.session_date <= %s ORDER BY s.session_date, s.start_time, s.id LIMIT %d',
				$from,
				$to,
				max( 1, $limit )
			),
			ARRAY_A
		) ?: array();
	}

	/** Prossime date non annullate (da oggi, comprese quelle già iniziate e non ancora finite), con i dati dell'attività e i posti occupati. */
	public function upcoming_sessions( int $limit = 10, ?int $activity_id = null ): array {
		$sql  = 'SELECT s.*, a.name AS activity_name, a.kind, a.fee_cents, a.guest_fee_cents, '
			. '(SELECT COUNT(*) FROM ' . Db::t( 'bookings' ) . " b WHERE b.session_id = s.id AND b.status = 'booked') AS booked_count "
			. 'FROM ' . Db::t( 'sessions' ) . ' s JOIN ' . Db::t( 'activities' ) . ' a ON a.id = s.activity_id AND a.deleted_at IS NULL '
			. 'WHERE s.cancelled_at IS NULL AND COALESCE(s.end_date, s.session_date) >= %s';
		$args = array( Db::today() );
		if ( $activity_id ) {
			$sql   .= ' AND a.id = %d';
			$args[] = $activity_id;
		}
		$sql   .= ' ORDER BY s.session_date, s.start_time, s.id LIMIT %d';
		$args[] = max( 1, $limit );
		return Employees::only_allowed_kinds( $this->db()->get_results( $this->db()->prepare( $sql, $args ), ARRAY_A ) ?: array() );
	}
}
