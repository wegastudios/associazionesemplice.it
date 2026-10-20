<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Riproporre un evento o un corso già fatto: si crea una nuova attività con gli stessi dati (nome, contributi, referente, regole di
 * annullamento, note, orari, luogo, staff…) in una nuova data o in un nuovo anno sociale, senza rifare tutto daccapo.
 * Non si copiano iscrizioni, prenotazioni, incassi, presenze né avvisi: la nuova attività parte vuota.
 */
final class ActivityCopy {

	/** Sposta una data (Y-m-d) di $days giorni. */
	public static function shift_date( string $ymd, int $days ): string {
		return ( new \DateTimeImmutable( $ymd ) )->modify( ( $days >= 0 ? '+' : '' ) . $days . ' days' )->format( 'Y-m-d' );
	}

	/** Giorni da spostare per portare $old_start a $new_start, arrotondati a settimane intere così i giorni della settimana restano gli stessi. */
	public static function shift_days( string $old_start, string $new_start ): int {
		$diff = ( new \DateTimeImmutable( $old_start ) )->diff( new \DateTimeImmutable( $new_start ) );
		$days = (int) $diff->format( '%r%a' );
		return 7 * (int) round( $days / 7 );
	}

	/**
	 * La data proposta per riproporre un evento: lo stesso giorno della settimana dell'anno dopo se è nel futuro, altrimenti il primo
	 * stesso giorno della settimana tra almeno una settimana.
	 */
	public static function suggest_date( string $old_date, string $today ): string {
		$cand = self::shift_date( $old_date, 364 );
		if ( $cand >= $today ) {
			return $cand;
		}
		$from  = self::shift_date( $today, 7 );
		$weeks = (int) ceil( ( new \DateTimeImmutable( $old_date ) )->diff( new \DateTimeImmutable( $from ) )->days / 7 );
		return self::shift_date( $old_date, 7 * $weeks );
	}

	/** Le lezioni di un corso con le date spostate di $days giorni (settimanali: da/fino a; singole: la data). @param array[] $lessons da {@see ActivityService::lessons} */
	public static function shift_lessons( array $lessons, int $days ): array {
		$out = array();
		foreach ( $lessons as $l ) {
			if ( 'single' === $l['type'] ) {
				$l['date'] = self::shift_date( (string) $l['date'], $days );
			} else {
				$l['from']  = ! empty( $l['from'] ) ? self::shift_date( (string) $l['from'], $days ) : null;
				$l['until'] = ! empty( $l['until'] ) ? self::shift_date( (string) $l['until'], $days ) : null;
			}
			$out[] = $l;
		}
		return $out;
	}

	private static function clean_date( $v ): string {
		$v = trim( (string) $v );
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ? $v : '';
	}

	/**
	 * Crea la nuova attività a partire da una esistente.
	 *
	 * Evento una tantum: `date` (obbligatoria), e se si vuole `start_time`, `end_time`, `location`, `capacity` (altrimenti quelli dell'ultima data).
	 * Evento ricorrente: `date` = la nuova prima data; le altre si spostano dello stesso numero di giorni.
	 * Corso: `social_year` (obbligatorio), `starts_on`, `ends_on` (altrimenti quelli vecchi spostati); le lezioni si spostano di settimane intere.
	 * Per tutti: `name` per cambiare il nome.
	 *
	 * @return int numero della nuova attività
	 * @throws \InvalidArgumentException
	 */
	public static function duplicate( int $id, array $in ): int {
		$svc = Plugin::activities();
		$a   = $svc->get( $id );
		if ( ! $a ) {
			throw new \InvalidArgumentException( 'Attività non trovata.' );
		}
		$name = trim( (string) ( $in['name'] ?? '' ) );
		$data = array(
			'name'                 => '' !== $name ? $name : $a['name'],
			'kind'                 => $a['kind'],
			'instructor_person_id' => $a['instructor_person_id'],
			'fee_cents'            => (int) $a['fee_cents'],
			'guest_fee_cents'      => $a['guest_fee_cents'],
			'vat_rate'             => $a['vat_rate'],
			'hold_hours'           => (int) $a['hold_hours'],
			'cancellable'          => (int) $a['cancellable'],
			'cancel_policy'        => $a['cancel_policy'],
			'booking_qr'           => (int) $a['booking_qr'],
			'billing'              => $a['billing'],
			'fund_mode'            => $a['fund_mode'],
			'fund_value'           => (int) $a['fund_value'],
			'notes'                => $a['notes'],
		);
		$sessions = array();
		if ( ActivityKind::COURSE === $a['kind'] ) {
			$label = trim( (string) ( $in['social_year'] ?? '' ) );
			if ( '' === $label ) {
				throw new \InvalidArgumentException( 'Scegli l\'anno sociale del nuovo corso.' );
			}
			$old_year   = SocialYear::from_label( (string) $a['social_year'], Settings::start_month() );
			$new_year   = SocialYear::from_label( $label, Settings::start_month() );
			$old_y_from = $old_year->start()->format( 'Y-m-d' );
			$new_y_from = $new_year->start()->format( 'Y-m-d' );
			$new_y_to   = $new_year->end()->format( 'Y-m-d' );
			$given_from = self::clean_date( $in['starts_on'] ?? '' );
			if ( '' !== $given_from ) {
				$new_from = $given_from;
			} elseif ( $a['starts_on'] ) { // lo stesso giorno della settimana, nel nuovo anno
				$new_from = self::shift_date( (string) $a['starts_on'], self::shift_days( $old_y_from, $new_y_from ) );
				while ( $new_from < $new_y_from ) {
					$new_from = self::shift_date( $new_from, 7 );
				}
			} else {
				$new_from = $new_y_from;
			}
			$days = self::shift_days( (string) ( $a['starts_on'] ?: $old_y_from ), $new_from );
			$ends = self::clean_date( $in['ends_on'] ?? '' );
			if ( '' === $ends && $a['ends_on'] ) {
				$ends = min( self::shift_date( (string) $a['ends_on'], $days ), $new_y_to );
				$ends = $ends < $new_from ? '' : $ends;
			}
			$data    += array(
				'social_year'  => $label,
				'starts_on'    => $a['starts_on'] || '' !== $given_from ? $new_from : '',
				'ends_on'      => $ends,
				'location'     => $a['location'],
				'lesson_slots' => wp_json_encode( self::shift_lessons( ActivityService::lessons( $a ), $days ) ),
			);
		} else {
			$date = self::clean_date( $in['date'] ?? '' );
			if ( '' === $date ) {
				throw new \InvalidArgumentException( 'Scegli la data della nuova edizione.' );
			}
			$data['social_year'] = Settings::social_year( $date )->label();
			$old                 = array_values( array_filter( $svc->sessions( $id ), function ( $s ) {
				return empty( $s['cancelled_at'] );
			} ) );
			if ( ActivityKind::EVENT === $a['kind'] ) {
				$last                = $old ? $old[ count( $old ) - 1 ] : array();
				$pick                = function ( string $k ) use ( $in, $last ) {
					return array_key_exists( $k, $in ) ? $in[ $k ] : ( $last[ $k ] ?? null );
				};
				$data['session']     = array( 'session_date' => $date, 'start_time' => $pick( 'start_time' ), 'end_time' => $pick( 'end_time' ), 'location' => $pick( 'location' ), 'capacity' => $pick( 'capacity' ) );
			} else {
				if ( ! $old ) {
					throw new \InvalidArgumentException( 'L\'evento ricorrente non ha date da riproporre.' );
				}
				$days = (int) ( new \DateTimeImmutable( $old[0]['session_date'] ) )->diff( new \DateTimeImmutable( $date ) )->format( '%r%a' );
				foreach ( $old as $s ) {
					$when = self::shift_date( (string) $s['session_date'], $days );
					ActivityService::assert_in_social_year( $data['social_year'], $when, 'La data del ' . ( new \DateTimeImmutable( $when ) )->format( 'd/m/Y' ) ); // tutte nello stesso anno sociale, prima di scrivere
					$sessions[] = array( 'session_date' => $when, 'start_time' => $s['start_time'], 'end_time' => $s['end_time'], 'location' => $s['location'], 'capacity' => $s['capacity'], 'notes' => $s['notes'] );
				}
			}
		}
		$new = $svc->create( $data );
		foreach ( $sessions as $s ) {
			$svc->add_session( $new, $s );
		}
		foreach ( $svc->staff( $id ) as $st ) { // lo staff resta quello dell'edizione precedente
			try {
				$svc->add_staff( $new, (int) $st['person_id'], ! empty( $st['can_cash'] ) );
			} catch ( \InvalidArgumentException $e ) {
				continue;
			}
		}
		$db = Db::db();
		if ( ! empty( $a['resource_id'] ) || ! empty( $a['credit_price'] ) ) { // risorsa occupata e crediti previsti (funzioni avanzate)
			$db->update( Db::t( 'activities' ), array( 'resource_id' => $a['resource_id'] ?? null, 'credit_price' => $a['credit_price'] ?? null ), array( 'id' => $new ) );
		}
		Audit::log( 'activity.duplicated', 'activity', $new, array( 'from' => $id ) );
		return $new;
	}
}
