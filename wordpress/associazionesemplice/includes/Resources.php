<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Risorse prenotabili: campi da gioco, sale, posti auto, armadietti, posti barca. Una risorsa è esclusiva: o è occupata o è libera.
 * Prenotano e ricevono risorse solo i soci con la tessera valida; un socio può indicare degli ospiti (solo il nome) dove la risorsa li ammette.
 * Le regole senza database sono in {@see ResourceRules}. Specifica: docs/RISORSE.md.
 */
final class Resources {

	private static function db(): \wpdb {
		return Db::db();
	}

	// ---------- Risorse ----------

	public static function get( int $id ): ?array {
		$row = self::db()->get_row( self::db()->prepare( 'SELECT * FROM ' . Db::t( 'resources' ) . ' WHERE id = %d AND deleted_at IS NULL', $id ), ARRAY_A );
		return $row ?: null;
	}

	/** @return array[] risorse non eliminate; per default solo quelle attive */
	public static function all( bool $with_inactive = false ): array {
		$where = 'deleted_at IS NULL' . ( $with_inactive ? '' : ' AND active = 1' );
		return self::db()->get_results( 'SELECT * FROM ' . Db::t( 'resources' ) . " WHERE $where ORDER BY category, name", ARRAY_A ) ?: array();
	}

	private static function clean_time( $v ): ?string {
		$v = trim( (string) $v );
		return null !== ResourceRules::minutes( $v ) ? $v : null;
	}

	/** Dati della risorsa dal modulo, controllati. @throws \InvalidArgumentException */
	private static function normalize( array $in ): array {
		$name = trim( (string) ( $in['name'] ?? '' ) );
		if ( '' === $name ) {
			throw new \InvalidArgumentException( 'Il nome della risorsa è obbligatorio.' );
		}
		$mode = (string) ( $in['mode'] ?? ResourceRules::SLOT );
		if ( ! isset( ResourceRules::modes()[ $mode ] ) ) {
			throw new \InvalidArgumentException( 'Modalità di prenotazione non valida.' );
		}
		$cat = (string) ( $in['category'] ?? 'other' );
		if ( ! isset( ResourceRules::categories()[ $cat ] ) ) {
			$cat = 'other';
		}
		$d = array(
			'name'            => mb_substr( $name, 0, 160 ),
			'category'        => $cat,
			'mode'            => $mode,
			'parent_id'       => ! empty( $in['parent_id'] ) ? (int) $in['parent_id'] : null,
			'description'     => trim( (string) ( $in['description'] ?? '' ) ),
			'rules'           => trim( (string) ( $in['rules'] ?? '' ) ),
			'fee_cents'       => max( 0, (int) ( $in['fee_cents'] ?? 0 ) ),
			'guests_max'      => max( 0, min( 50, (int) ( $in['guests_max'] ?? 0 ) ) ),
			'guest_fee_cents' => max( 0, (int) ( $in['guest_fee_cents'] ?? 0 ) ),
			'max_per_person'  => max( 0, (int) ( $in['max_per_person'] ?? 0 ) ),
			'cancellable'     => ! empty( $in['cancellable'] ) ? 1 : 0,
			'credit_cost'     => max( 0, min( CreditRules::MAX_CREDITS, (int) ( $in['credit_cost'] ?? 0 ) ) ),
			'pay_mode'        => isset( $in['pay_mode'] ) && isset( ResourceRules::pay_modes()[ (string) $in['pay_mode'] ] ) ? (string) $in['pay_mode'] : ResourceRules::PAY_DESK,
			'cancel_policy'   => isset( $in['cancel_policy'] ) && CancelPolicy::is_valid( (string) $in['cancel_policy'] ) ? (string) $in['cancel_policy'] : null,
			'active'          => array_key_exists( 'active', $in ) ? ( ! empty( $in['active'] ) ? 1 : 0 ) : 1,
			'open_time'       => null,
			'close_time'      => null,
			'slot_minutes'    => 60,
			'min_slots'       => 1,
			'max_slots'       => 1,
			'advance_days'    => 0,
			'rates'           => null,
		);
		if ( ResourceRules::SLOT === $mode ) {
			$open  = self::clean_time( $in['open_time'] ?? '' );
			$close = self::clean_time( $in['close_time'] ?? '' );
			$step  = (int) ( $in['slot_minutes'] ?? 60 );
			if ( null === $open || null === $close || ResourceRules::minutes( $close ) <= ResourceRules::minutes( $open ) ) {
				throw new \InvalidArgumentException( 'Indica l\'orario di apertura e di chiusura (la chiusura dopo l\'apertura).' );
			}
			if ( $step < 5 || $step > 720 ) {
				throw new \InvalidArgumentException( 'La durata della fascia va da 5 minuti a 12 ore.' );
			}
			$min               = max( 1, (int) ( $in['min_slots'] ?? 1 ) );
			$d['open_time']    = $open;
			$d['close_time']   = $close;
			$d['slot_minutes'] = $step;
			$d['min_slots']    = $min;
			$d['max_slots']    = max( $min, (int) ( $in['max_slots'] ?? $min ) );
			$d['advance_days'] = max( 0, min( 730, (int) ( $in['advance_days'] ?? 0 ) ) );
			$bands             = ResourceRules::parse_rates( (string) ( $in['rates_text'] ?? '' ) );
			$d['rates']        = $bands ? wp_json_encode( $bands ) : null;
		}
		if ( array_key_exists( 'setup_before', $in ) || array_key_exists( 'setup_after', $in ) ) { // l'allestimento della risorsa: tempo di preparazione prima e di riordino dopo ogni attività
			$d['setup_before_min'] = (int) ResourceRules::parse_duration( (string) ( $in['setup_before'] ?? '' ) );
			$d['setup_after_min']  = (int) ResourceRules::parse_duration( (string) ( $in['setup_after'] ?? '' ) );
		}
		if ( array_key_exists( 'fund_mode', $in ) ) { // la quota per un fondo (solo con la funzione dei fondi: senza il campo non si tocca ciò che c'è)
			$mode  = in_array( (string) $in['fund_mode'], array( FundShare::FIXED, FundShare::PERCENT ), true ) ? (string) $in['fund_mode'] : FundShare::NONE;
			$value = max( 0, (int) ( $in['fund_value'] ?? 0 ) );
			$fund  = 'new' === (string) ( $in['fund_id'] ?? '' ) ? 'new' : max( 0, (int) ( $in['fund_id'] ?? 0 ) );
			if ( FundShare::NONE === $mode ) {
				$value = 0;
				$fund  = 0;
			} else {
				if ( $value <= 0 ) {
					throw new \InvalidArgumentException( 'Indica la quota per il fondo (importo o percentuale maggiori di zero).' );
				}
				if ( FundShare::PERCENT === $mode && $value > 10000 ) {
					throw new \InvalidArgumentException( 'La percentuale per il fondo non può superare il 100%.' );
				}
				if ( 0 === $fund ) {
					throw new \InvalidArgumentException( 'Scegli il fondo in cui accantonare la quota, oppure creane uno nuovo.' );
				}
				if ( 'new' !== $fund && ! Plugin::funds()->get( $fund ) ) {
					throw new \InvalidArgumentException( 'Fondo non trovato.' );
				}
			}
			$d['fund_mode']  = $mode;
			$d['fund_value'] = $value;
			$d['fund_id']    = $fund ?: null;
		}
		return $d;
	}

	public static function save( int $id, array $in ): int {
		$d = self::normalize( $in );
		if ( $d['parent_id'] && ( $d['parent_id'] === $id || ! self::get( (int) $d['parent_id'] ) ) ) {
			$d['parent_id'] = null;
		}
		if ( isset( $d['fund_id'] ) && 'new' === $d['fund_id'] ) { // dopo i controlli: nessun fondo orfano se qualcosa non va
			$d['fund_id'] = Plugin::funds()->create( 'Quota «' . $d['name'] . '»' );
		}
		if ( $id > 0 ) {
			if ( ! self::get( $id ) ) {
				throw new \InvalidArgumentException( 'Risorsa non trovata.' );
			}
			self::db()->update( Db::t( 'resources' ), $d, array( 'id' => $id ) );
			Audit::log( 'resource.updated', 'resource', $id );
			return $id;
		}
		$d['created_at'] = Db::now();
		if ( ! self::db()->insert( Db::t( 'resources' ), $d ) ) {
			throw new \InvalidArgumentException( 'Salvataggio non riuscito: riprova.' );
		}
		$id = (int) self::db()->insert_id;
		Audit::log( 'resource.created', 'resource', $id, array( 'name' => $d['name'] ) );
		return $id;
	}

	/** Elimina la risorsa solo se non ha prenotazioni future; altrimenti si disattiva. @return bool true se eliminata */
	public static function delete( int $id ): bool {
		$res = self::get( $id );
		if ( ! $res ) {
			throw new \InvalidArgumentException( 'Risorsa non trovata.' );
		}
		$future = (int) self::db()->get_var( self::db()->prepare( 'SELECT COUNT(*) FROM ' . Db::t( 'reservations' ) . " WHERE resource_id = %d AND status = 'booked' AND (ends_at IS NULL OR ends_at > %s)", $id, Db::now() ) );
		if ( $future > 0 ) {
			self::db()->update( Db::t( 'resources' ), array( 'active' => 0 ), array( 'id' => $id ) );
			Audit::log( 'resource.deactivated', 'resource', $id );
			return false;
		}
		self::db()->update( Db::t( 'resources' ), array( 'deleted_at' => Db::now() ), array( 'id' => $id ) );
		self::db()->update( Db::t( 'activities' ), array( 'resource_id' => null ), array( 'resource_id' => $id ) ); // i corsi collegati non occupano più niente
		Audit::log( 'resource.deleted', 'resource', $id, array( 'name' => $res['name'] ) );
		return true;
	}

	// ---------- Prenotazioni ----------

	public static function reservation( int $id ): ?array {
		$row = self::db()->get_row( self::db()->prepare( 'SELECT * FROM ' . Db::t( 'reservations' ) . ' WHERE id = %d', $id ), ARRAY_A );
		return $row ?: null;
	}

	/** Incassato per ogni prenotazione. @param int[] $ids @return array<int,int> */
	private static function paid_map( array $ids ): array {
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( ! $ids ) {
			return array();
		}
		$in  = implode( ',', $ids );
		$out = array();
		foreach ( self::db()->get_results( 'SELECT reservation_id, SUM(amount_cents + discount_cents) AS s FROM ' . Db::t( 'transactions' ) . " WHERE type = 'income' AND voided_at IS NULL AND reservation_id IN ($in) GROUP BY reservation_id", ARRAY_A ) ?: array() as $r ) {
			$out[ (int) $r['reservation_id'] ] = (int) $r['s'];
		}
		return $out;
	}

	/** Aggiunge pagato, residuo e ospiti alle righe di prenotazione. */
	private static function decorate( array $rows ): array {
		$paid   = self::paid_map( array_column( $rows, 'id' ) );
		$guests = array();
		if ( $rows ) {
			$in = implode( ',', array_map( 'intval', array_column( $rows, 'id' ) ) );
			foreach ( self::db()->get_results( 'SELECT reservation_id, name FROM ' . Db::t( 'reservation_guests' ) . " WHERE reservation_id IN ($in) ORDER BY id", ARRAY_A ) ?: array() as $g ) {
				$guests[ (int) $g['reservation_id'] ][] = $g['name'];
			}
		}
		foreach ( $rows as &$r ) {
			$id             = (int) $r['id'];
			$r['paid']      = $paid[ $id ] ?? 0;
			$r['remaining'] = Pricing::remaining( (int) $r['fee_due_cents'], $r['paid'] );
			$r['state']     = Pricing::booking_state( (int) $r['fee_due_cents'], $r['paid'] );
			if ( (int) $r['credits_paid'] > 0 ) { // pagata in crediti: non c'è nulla da incassare
				$r['remaining'] = 0;
				$r['state']     = Pricing::PAID;
			}
			$r['active']    = 'booked' === $r['status'];
			$r['guests']    = $guests[ $id ] ?? array();
		}
		return $rows;
	}

	/** Prenotazioni attive di una risorsa nell'intervallo (la fine vuota = senza scadenza). */
	public static function between( int $resource_id, string $from, string $to, bool $all_status = false ): array {
		$sql  = 'SELECT r.*, p.first_name, p.last_name FROM ' . Db::t( 'reservations' ) . ' r LEFT JOIN ' . Db::t( 'people' ) . ' p ON p.id = r.person_id '
			. 'WHERE r.resource_id = %d AND r.starts_at < %s AND (r.ends_at IS NULL OR r.ends_at > %s)' . ( $all_status ? '' : " AND r.status = 'booked'" ) . ' ORDER BY r.starts_at';
		$rows = self::db()->get_results( self::db()->prepare( $sql, $resource_id, $to, $from ), ARRAY_A ) ?: array();
		return self::decorate( $rows );
	}

	/**
	 * Quando i corsi e gli eventi collegati alla risorsa la occupano tra due date (incluse). L'attività senza orario occupa tutto il giorno.
	 * L'indisponibilità comprende l'allestimento prima e dopo: l'esigenza della risorsa e quella dell'attività si sommano
	 * (la risorsa vuole 20 minuti prima e dopo, lo yoga 30 dopo → 20 minuti prima e 50 dopo). Gli eventi hanno 3 ore di allestimento
	 * per tipo, se non si indica altro; i corsi nessuno.
	 *
	 * @return array[] starts, ends (con gli allestimenti), core_starts, core_ends (solo l'attività), title, activity_id (Y-m-d H:i:s)
	 */
	public static function activity_busy( int $resource_id, string $from, string $to ): array {
		$res  = self::get( $resource_id );
		$acts = array();
		foreach ( self::db()->get_results( self::db()->prepare( 'SELECT id, kind, setup_before_min, setup_after_min FROM ' . Db::t( 'activities' ) . ' WHERE resource_id = %d AND deleted_at IS NULL', $resource_id ), ARRAY_A ) ?: array() as $a ) {
			$acts[ (int) $a['id'] ] = $a;
		}
		$out = array();
		if ( ! $acts || ! $res ) {
			return $out;
		}
		// Un'attività a ridosso della mezzanotte occupa anche il giorno prima o dopo con l'allestimento: si guarda un giorno in più per parte.
		foreach ( Calendar::occurrences( gmdate( 'Y-m-d', strtotime( $from . ' -1 day' ) ), gmdate( 'Y-m-d', strtotime( $to . ' +1 day' ) ) ) as $o ) {
			$a = $acts[ (int) $o['activity_id'] ] ?? null;
			if ( ! $a ) {
				continue;
			}
			$before = ResourceRules::setup_total( (int) $res['setup_before_min'], null === $a['setup_before_min'] ? null : (int) $a['setup_before_min'], (string) $a['kind'] );
			$after  = ResourceRules::setup_total( (int) $res['setup_after_min'], null === $a['setup_after_min'] ? null : (int) $a['setup_after_min'], (string) $a['kind'] );
			$w      = ResourceRules::busy_window( (string) $o['date'], $o['start'] ?: null, $o['end'] ?: null, $before, $after );
			$out[]  = $w + array( 'title' => (string) $o['title'], 'activity_id' => (int) $o['activity_id'] );
		}
		return $out;
	}


	/** La griglia di un giorno: per ogni fascia, libera oppure la prenotazione (o l'attività) che la occupa. */
	public static function day_grid( array $res, string $date ): array {
		$booked = self::between( (int) $res['id'], $date . ' 00:00:00', $date . ' 23:59:59' );
		$busy   = self::activity_busy( (int) $res['id'], $date, $date );
		$out    = array();
		foreach ( ResourceRules::day_slots( $res ) as $s ) {
			$a    = $date . ' ' . $s['start'] . ':00';
			$b    = $date . ' ' . $s['end'] . ':00';
			$used = null;
			foreach ( $booked as $r ) {
				if ( ResourceRules::overlaps( $a, $b, (string) $r['starts_at'], $r['ends_at'] ? (string) $r['ends_at'] : null ) ) {
					$used = $r;
					break;
				}
			}
			if ( ! $used ) {
				foreach ( $busy as $x ) {
					if ( ResourceRules::overlaps( $a, $b, $x['starts'], $x['ends'] ) ) {
						$used = array( 'id' => 0, 'kind' => 'activity', 'status' => 'booked', 'starts_at' => $x['starts'], 'ends_at' => $x['ends'], 'first_name' => $x['title'], 'last_name' => '', 'note' => $x['title'], 'setup' => ! ResourceRules::overlaps( $a, $b, $x['core_starts'], $x['core_ends'] ), 'guests' => array(), 'fee_due_cents' => 0, 'paid' => 0, 'remaining' => 0, 'state' => 'free', 'active' => true );
						break;
					}
				}
			}
			$out[] = array_merge( $s, array( 'reservation' => $used ) );
		}
		return $out;
	}

	/** Chi può avere una risorsa: un socio con la tessera valida. @throws \InvalidArgumentException */
	private static function assert_member( int $person_id ): array {
		$person = Plugin::people()->get( $person_id );
		if ( ! $person ) {
			throw new \InvalidArgumentException( 'Persona non trovata.' );
		}
		if ( ! MemberType::is_member( $person['type'] ) ) {
			throw new \InvalidArgumentException( 'Le risorse sono riservate ai soci: ' . Plugin::people()->full_name( $person ) . ' non è socio.' );
		}
		if ( ! Plugin::people()->is_active_member( $person_id ) ) {
			throw new \InvalidArgumentException( 'La tessera di ' . Plugin::people()->full_name( $person ) . ' non è valida: serve il rinnovo.' );
		}
		return $person;
	}

	/** Una prenotazione sola, sotto blocco: vedi {@see insert_many}. */
	private static function insert( array $res, string $kind, ?int $person_id, string $starts, ?string $ends, int $fee, array $guests, string $note, ?string $hold_until = null ): int {
		return self::insert_many( $res, $kind, $person_id, array( array( $starts, $ends, $fee ) ), $guests, $note, $hold_until )[0];
	}

	/**
	 * Scrive una o più prenotazioni della stessa risorsa sotto blocco, tutte o nessuna: nessuna sovrapposizione possibile nemmeno con due
	 * richieste insieme, né con un'altra prenotazione né con un corso o un evento collegato alla risorsa. Più prenotazioni = una serie
	 * (la prima dà il numero alla serie).
	 *
	 * @param array[] $ranges [starts_at, ends_at|null, dovuto]
	 * @return int[] numeri delle prenotazioni
	 * @throws \InvalidArgumentException
	 */
	private static function insert_many( array $res, string $kind, ?int $person_id, array $ranges, array $guests, string $note, ?string $hold_until = null ): array {
		$rid = (int) $res['id'];
		return Db::with_lock(
			'asem_res_' . $rid,
			function () use ( $res, $rid, $kind, $person_id, $ranges, $guests, $note, $hold_until ) {
				self::release_unpaid( $rid ); // prima di dire «occupata» si liberano le fasce riservate per un pagamento online mai arrivato
				$tbl   = Db::t( 'reservations' );
				$until = null;
				foreach ( $ranges as $r ) {
					$until = null === $r[1] ? '9999-12-31' : max( (string) $until, substr( (string) $r[1], 0, 10 ) );
				}
				$busy = ResourceRules::SLOT === $res['mode'] ? self::activity_busy( $rid, substr( (string) $ranges[0][0], 0, 10 ), (string) $until ) : array();
				foreach ( $ranges as $r ) {
					list( $starts, $ends ) = $r;
					$when = ( new \DateTimeImmutable( $starts ) )->format( 'd/m/Y \a\l\l\e H:i' );
					$sql  = "SELECT id FROM $tbl WHERE resource_id = %d AND status = 'booked' AND (ends_at IS NULL OR ends_at > %s)";
					$args = array( $rid, $starts );
					if ( null !== $ends ) {
						$sql   .= ' AND starts_at < %s';
						$args[] = $ends;
					}
					if ( self::db()->get_var( self::db()->prepare( $sql . ' LIMIT 1', $args ) ) ) {
						throw new \InvalidArgumentException( 'La risorsa è già occupata il ' . $when . '.' );
					}
					foreach ( $busy as $x ) {
						if ( ResourceRules::overlaps( $starts, $ends, $x['starts'], $x['ends'] ) ) {
							$core = ResourceRules::overlaps( $starts, $ends, $x['core_starts'], $x['core_ends'] );
							throw new \InvalidArgumentException( 'Il ' . $when . ' la risorsa è ' . ( $core ? 'occupata da «' : 'in allestimento per «' ) . $x['title'] . '».' );
						}
					}
				}
				$ids = array();
				foreach ( $ranges as $r ) {
					$ok = self::db()->insert(
						$tbl,
						array(
							'resource_id'   => $rid,
							'person_id'     => $person_id,
							'kind'          => $kind,
							'starts_at'     => $r[0],
							'ends_at'       => $r[1],
							'status'        => 'booked',
							'fee_due_cents' => (int) $r[2],
							'note'          => '' === $note ? null : mb_substr( $note, 0, 255 ),
							'series_id'     => count( $ranges ) > 1 && $ids ? $ids[0] : null,
							'hold_until'    => $hold_until,
							'created_by'    => get_current_user_id() ?: null,
							'created_at'    => Db::now(),
						)
					);
					if ( ! $ok ) {
						throw new \InvalidArgumentException( 'Prenotazione non riuscita: riprova.' );
					}
					$id    = (int) self::db()->insert_id;
					$ids[] = $id;
					if ( count( $ranges ) > 1 && 1 === count( $ids ) ) {
						self::db()->update( $tbl, array( 'series_id' => $id ), array( 'id' => $id ) );
					}
					foreach ( $guests as $g ) {
						self::db()->insert( Db::t( 'reservation_guests' ), array( 'reservation_id' => $id, 'name' => $g ) );
					}
				}
				return $ids;
			}
		);
	}

	/**
	 * Prenota una o più fasce di una risorsa per un socio.
	 *
	 * @param bool $by_staff true per la segreteria: ammesso anche nel passato, oltre l'anticipo e oltre il massimo per persona
	 * @param string[] $guest_names nomi degli ospiti (solo se la risorsa li ammette)
	 * @throws \InvalidArgumentException
	 */
	public static function book_slot( int $resource_id, int $person_id, string $date, string $start, int $slots, array $guest_names = array(), bool $by_staff = false, string $note = '', bool $hold = false ): int {
		$res = self::get( $resource_id );
		if ( ! $res || ( ! (int) $res['active'] && ! $by_staff ) ) {
			throw new \InvalidArgumentException( 'Risorsa non disponibile.' );
		}
		if ( ResourceRules::SLOT !== $res['mode'] ) {
			throw new \InvalidArgumentException( 'Questa risorsa non si prenota a fasce: viene assegnata dalla segreteria.' );
		}
		$person = self::assert_member( $person_id );
		list( $starts, $ends ) = ResourceRules::slot_range( $res, $date, $start, $slots, Db::now(), $by_staff );
		$guests = ResourceRules::guests( (int) $res['guests_max'], $guest_names );
		$fee    = ResourceRules::fee( $res, $slots, count( $guests ), $starts );
		$max    = (int) $res['max_per_person'];
		if ( ! $by_staff && $max > 0 ) {
			$mine = (int) self::db()->get_var( self::db()->prepare( 'SELECT COUNT(*) FROM ' . Db::t( 'reservations' ) . " WHERE resource_id = %d AND person_id = %d AND status = 'booked' AND kind = 'slot' AND starts_at >= %s", $resource_id, $person_id, Db::now() ) );
			if ( $mine >= $max ) {
				throw new \InvalidArgumentException( 'Hai già ' . $mine . ' ' . ( 1 === $mine ? 'prenotazione' : 'prenotazioni' ) . ' su questa risorsa (massimo ' . $max . '): annullane una per prenotarne un\'altra.' );
			}
		}
		$hold_until = $hold && $fee > 0 && ! $by_staff ? gmdate( 'Y-m-d H:i:s', strtotime( Db::now() . ' +' . ResourceRules::HOLD_MINUTES . ' minutes' ) ) : null; // il pagamento online deve arrivare entro pochi minuti
		$id         = self::insert( $res, ResourceRules::KIND_SLOT, $person_id, $starts, $ends, $fee, $guests, $note, $hold_until );
		Audit::log( 'reservation.created', 'resource', $resource_id, array( 'reservation' => $id, 'person_id' => (int) $person['id'], 'starts' => $starts, 'fee' => $fee, 'hold' => null !== $hold_until ) );
		return $id;
	}

	// ---------- Pagamento ----------

	/**
	 * Quanto costerebbe una prenotazione del socio, con gli stessi controlli di {@see book_slot} sulla fascia e sugli ospiti.
	 *
	 * @return array{0:int,1:array} [dovuto in centesimi, risorsa]
	 * @throws \InvalidArgumentException
	 */
	public static function quote( int $resource_id, string $date, string $start, int $slots, array $guest_names ): array {
		$res = self::get( $resource_id );
		if ( ! $res || ! (int) $res['active'] || ResourceRules::SLOT !== $res['mode'] ) {
			throw new \InvalidArgumentException( 'Risorsa non disponibile.' );
		}
		list( $starts ) = ResourceRules::slot_range( $res, $date, $start, $slots, Db::now(), false );
		$guests         = ResourceRules::guests( (int) $res['guests_max'], $guest_names );
		return array( ResourceRules::fee( $res, $slots, count( $guests ), $starts ), $res );
	}

	/** Crediti per una prenotazione di $slots fasce (0 = la risorsa non si paga in crediti, o la funzione non c'è). */
	public static function credit_price( array $res, int $slots ): int {
		$unit = (int) ( $res['credit_cost'] ?? 0 );
		return Edition::has( 'credits' ) && $unit > 0 ? CreditRules::cost( $unit, $slots ) : 0;
	}

	/**
	 * Paga una prenotazione con i crediti del socio (subito, senza gateway). Se i crediti non bastano non cambia niente.
	 *
	 * @return int crediti spesi
	 * @throws \InvalidArgumentException
	 */
	public static function pay_with_credits( int $reservation_id, int $person_id ): int {
		$r   = self::reservation( $reservation_id );
		$res = $r ? self::get( (int) $r['resource_id'] ) : null;
		if ( ! $r || ! $res || 'booked' !== $r['status'] || (int) $r['person_id'] !== $person_id ) {
			throw new \InvalidArgumentException( 'Prenotazione non trovata.' );
		}
		if ( (int) $r['credits_paid'] > 0 ) {
			throw new \InvalidArgumentException( 'Questa prenotazione è già stata pagata in crediti.' );
		}
		$step  = max( 1, (int) $res['slot_minutes'] );
		$slots = ResourceRules::SLOT === $res['mode'] && $r['ends_at'] ? max( 1, (int) round( ( strtotime( (string) $r['ends_at'] . ' UTC' ) - strtotime( (string) $r['starts_at'] . ' UTC' ) ) / 60 / $step ) ) : 1;
		$cost  = self::credit_price( $res, $slots );
		if ( $cost < 1 ) {
			throw new \InvalidArgumentException( 'Questa risorsa non si paga in crediti.' );
		}
		Credits::spend( $person_id, $cost, $res['name'] . ' — ' . substr( (string) $r['starts_at'], 0, 16 ), 'reservation', $reservation_id );
		self::db()->update( Db::t( 'reservations' ), array( 'credits_paid' => $cost, 'hold_until' => null ), array( 'id' => $reservation_id ) );
		$sh = self::fund_share( $res, (int) $r['fee_due_cents'] ); // la quota del fondo si calcola sul prezzo in euro della prenotazione: i crediti non hanno incasso in prima nota
		if ( $sh > 0 ) {
			Plugin::funds()->share_to_fund( (int) $res['fund_id'], $sh, Db::today(), null, 'Quota di «' . $res['name'] . '» pagata in crediti' );
		}
		Audit::log( 'reservation.paid_credits', 'resource', (int) $r['resource_id'], array( 'reservation' => $reservation_id, 'credits' => $cost ) );
		return $cost;
	}

	// ---------- Quota per un fondo ----------

	/** La quota di un pagamento che spetta al fondo della risorsa (0 se la risorsa non ha un fondo o la funzione dei fondi non c'è). */
	private static function fund_share( array $res, int $cents ): int {
		return Edition::has( 'funds' ) && ! empty( $res['fund_id'] ) ? FundShare::cents( (string) $res['fund_mode'], (int) $res['fund_value'], $cents ) : 0;
	}

	/**
	 * Incasso di una prenotazione registrato in prima nota: la quota stabilita dalla risorsa va nel suo fondo (a una risorsa data in gestione a terzi,
	 * o i cui incassi servono per altro). Se l'incasso si annulla, sparisce anche la quota.
	 *
	 * @return int centesimi accantonati
	 */
	public static function share_to_fund( int $reservation_id, int $tx_id, int $cents, string $date ): int {
		$r   = self::reservation( $reservation_id );
		$res = $r ? self::get( (int) $r['resource_id'] ) : null;
		$sh  = $res ? self::fund_share( $res, $cents ) : 0;
		return $sh > 0 ? Plugin::funds()->share_to_fund( (int) $res['fund_id'], $sh, $date, $tx_id, 'Quota di «' . $res['name'] . '»' ) : 0;
	}

	/** I crediti spesi per una prenotazione tornano al socio (annullamento). */
	private static function refund_credits( array $r ): void {
		$credits = (int) ( $r['credits_paid'] ?? 0 );
		if ( $credits < 1 || ! $r['person_id'] ) {
			return;
		}
		Credits::refund( (int) $r['person_id'], $credits, 'Annullata la prenotazione del ' . substr( (string) $r['starts_at'], 0, 16 ), 'reservation', (int) $r['id'] );
		$res = self::get( (int) $r['resource_id'] );
		$sh  = $res ? self::fund_share( $res, (int) $r['fee_due_cents'] ) : 0; // la quota accantonata alla spesa dei crediti torna disponibile
		if ( $sh > 0 ) {
			Plugin::funds()->take_back( (int) $res['fund_id'], $sh, Db::today(), 'Prenotazione annullata: crediti riaccreditati («' . $res['name'] . '»)' );
		}
		self::db()->update( Db::t( 'reservations' ), array( 'credits_paid' => 0 ), array( 'id' => (int) $r['id'] ) );
	}

	/** La prenotazione è ancora valida (non annullata né liberata)? */
	public static function is_active( int $reservation_id ): bool {
		return (bool) self::db()->get_var( self::db()->prepare( 'SELECT COUNT(*) FROM ' . Db::t( 'reservations' ) . " WHERE id = %d AND status = 'booked'", $reservation_id ) );
	}

	/** Pagamento arrivato: la prenotazione non è più provvisoria. */
	public static function confirm( int $reservation_id ): void {
		self::db()->update( Db::t( 'reservations' ), array( 'hold_until' => null ), array( 'id' => $reservation_id ) );
	}

	/** Un pagamento online di questa prenotazione è in corso (avviato da poco, non ancora concluso)? */
	private static function online_in_progress( int $reservation_id ): bool {
		$since = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		foreach ( self::db()->get_results( self::db()->prepare( 'SELECT items FROM ' . Db::t( 'payments' ) . " WHERE status IN ('created','pending') AND updated_at >= %s", $since ), ARRAY_A ) ?: array() as $p ) {
			foreach ( (array) json_decode( (string) $p['items'], true ) as $it ) {
				if ( (int) ( $it['reservation_id'] ?? 0 ) === $reservation_id ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Libera le fasce riservate per un pagamento online che non è arrivato entro {@see ResourceRules::HOLD_MINUTES} minuti. Non è una tolleranza:
	 * serve solo a non tenere bloccata una fascia da un pagamento abbandonato. Non libera mai una prenotazione con un incasso registrato
	 * né con un pagamento online ancora in corso.
	 *
	 * @param ?int $resource_id solo questa risorsa (null = tutte)
	 * @return int prenotazioni liberate
	 */
	public static function release_unpaid( ?int $resource_id = null ): int {
		$tbl  = Db::t( 'reservations' );
		$sql  = "SELECT id, resource_id FROM $tbl WHERE status = 'booked' AND hold_until IS NOT NULL AND hold_until < %s";
		$args = array( Db::now() );
		if ( $resource_id ) {
			$sql   .= ' AND resource_id = %d';
			$args[] = $resource_id;
		}
		$rows = self::db()->get_results( self::db()->prepare( $sql, $args ), ARRAY_A ) ?: array();
		$paid = self::paid_map( array_column( $rows, 'id' ) );
		$n    = 0;
		foreach ( $rows as $r ) {
			$id = (int) $r['id'];
			if ( ( $paid[ $id ] ?? 0 ) > 0 || self::online_in_progress( $id ) ) {
				continue;
			}
			self::db()->update( $tbl, array( 'status' => 'cancelled', 'cancelled_at' => Db::now() ), array( 'id' => $id ) );
			Audit::log( 'reservation.released', 'resource', (int) $r['resource_id'], array( 'reservation' => $id ) );
			$n++;
		}
		return $n;
	}

	/** Lavoro periodico di WP-Cron (pianificato da Plugin::schedule_jobs()). */
	public static function release_unpaid_job(): void {
		self::release_unpaid();
	}

	/**
	 * Prenotazioni del socio che si possono pagare online: quelle con un dovuto ancora da versare su risorse che ammettono il pagamento online.
	 *
	 * @return array[] chiave => voce per {@see PaymentItems}
	 */
	public static function online_dues( array $actor ): array {
		$out = array();
		foreach ( self::for_person( (int) $actor['id'] ) as $r ) {
			$res = self::get( (int) $r['resource_id'] );
			if ( ! $res || ResourceRules::PAY_DESK === $res['pay_mode'] || ResourceRules::KIND_BLOCK === $r['kind'] || $r['remaining'] <= 0 ) {
				continue;
			}
			$i        = array(
				'type' => PaymentItems::RESERVATION, 'person_id' => (int) $actor['id'], 'person_name' => trim( $actor['first_name'] . ' ' . $actor['last_name'] ), 'reservation_id' => (int) $r['id'],
				'amount_cents' => (int) $r['remaining'], 'label' => $r['resource_name'] . ' — ' . ( new \DateTimeImmutable( substr( (string) $r['starts_at'], 0, 10 ) ) )->format( 'd/m/Y' ) . ' ' . substr( (string) $r['starts_at'], 11, 5 ),
			);
			$i['key'] = PaymentItems::key( $i );
			$out[ $i['key'] ] = $i;
		}
		return $out;
	}

	/**
	 * Assegna una risorsa a un socio per un periodo (posto auto, armadietto, posto barca). Senza data di fine resta assegnata finché non si rilascia.
	 *
	 * @throws \InvalidArgumentException
	 */
	public static function assign( int $resource_id, int $person_id, string $from, ?string $to, string $note = '' ): int {
		$res = self::get( $resource_id );
		if ( ! $res ) {
			throw new \InvalidArgumentException( 'Risorsa non trovata.' );
		}
		if ( ResourceRules::ASSIGN !== $res['mode'] ) {
			throw new \InvalidArgumentException( 'Questa risorsa si prenota a fasce orarie.' );
		}
		$person = self::assert_member( $person_id );
		$from   = trim( $from );
		$to     = null !== $to && '' !== trim( $to ) ? trim( $to ) : null;
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) || ( null !== $to && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) ) ) {
			throw new \InvalidArgumentException( 'Date non valide.' );
		}
		if ( null !== $to && $to < $from ) {
			throw new \InvalidArgumentException( 'La fine dell\'assegnazione non può precedere l\'inizio.' );
		}
		$ends = null !== $to ? gmdate( 'Y-m-d', strtotime( $to . ' +1 day' ) ) . ' 00:00:00' : null; // l'ultimo giorno è compreso
		$id   = self::insert( $res, ResourceRules::KIND_ASSIGN, $person_id, $from . ' 00:00:00', $ends, ResourceRules::fee( $res, 1, 0 ), array(), $note );
		self::db()->update( Db::t( 'resource_waitlist' ), array( 'status' => 'done', 'resolved_at' => Db::now() ), array( 'resource_id' => $resource_id, 'person_id' => $person_id, 'status' => 'waiting' ) ); // chi era in coda ora ha la risorsa
		Audit::log( 'reservation.assigned', 'resource', $resource_id, array( 'reservation' => $id, 'person_id' => (int) $person['id'], 'from' => $from, 'to' => $to ) );
		return $id;
	}

	/** Blocca la risorsa (manutenzione, chiusura, torneo). Se ci sono prenotazioni nell'intervallo non lo fa e le elenca. @throws \InvalidArgumentException */
	public static function block( int $resource_id, string $starts, string $ends, string $note = '' ): int {
		$res = self::get( $resource_id );
		if ( ! $res ) {
			throw new \InvalidArgumentException( 'Risorsa non trovata.' );
		}
		foreach ( array( $starts, $ends ) as $v ) {
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $v ) ) {
				throw new \InvalidArgumentException( 'Date non valide.' );
			}
		}
		if ( $ends <= $starts ) {
			throw new \InvalidArgumentException( 'La fine del blocco deve essere dopo l\'inizio.' );
		}
		$id = self::insert( $res, ResourceRules::KIND_BLOCK, null, $starts, $ends, 0, array(), $note );
		Audit::log( 'reservation.blocked', 'resource', $resource_id, array( 'reservation' => $id, 'starts' => $starts, 'ends' => $ends ) );
		return $id;
	}

	/** Regole di annullamento per il socio. @return array{allowed:bool,message:string} */
	public static function cancellation( array $r ): array {
		$res = self::get( (int) $r['resource_id'] );
		if ( ! $res || 'booked' !== $r['status'] ) {
			return array( 'allowed' => false, 'message' => 'Prenotazione non trovata.' );
		}
		if ( ResourceRules::KIND_ASSIGN === $r['kind'] ) {
			return array( 'allowed' => false, 'message' => 'Un\'assegnazione la rilascia la segreteria.' );
		}
		$now  = current_datetime();
		$date = substr( (string) $r['starts_at'], 0, 10 );
		$time = substr( (string) $r['starts_at'], 11, 5 );
		$ev   = CancelPolicy::evaluate( (int) $r['fee_due_cents'], ! empty( $res['cancellable'] ), $res['cancel_policy'] ?: null, (string) Settings::get( 'cancel_policy_default' ), $date, $time, $now );
		switch ( $ev['reason'] ) {
			case CancelPolicy::REASON_STARTED:
				$msg = 'La prenotazione è già iniziata.';
				break;
			case CancelPolicy::REASON_NOT_CANCELLABLE:
				$msg = 'Questa prenotazione è a pagamento e non si può annullare: scrivi alla segreteria.';
				break;
			case CancelPolicy::REASON_DEADLINE:
				$msg = 'Il termine per annullare è scaduto' . ( $ev['deadline'] ? ' (' . $ev['deadline']->format( 'd/m/Y H:i' ) . ')' : '' ) . '.';
				break;
			case CancelPolicy::REASON_OK:
				$msg = 'Puoi annullare fino al ' . ( $ev['deadline'] ? $ev['deadline']->format( 'd/m/Y H:i' ) : '' ) . '.';
				break;
			default:
				$msg = 'Puoi annullare quando vuoi.';
		}
		return array( 'allowed' => (bool) $ev['allowed'], 'message' => $msg );
	}

	/**
	 * Annulla una prenotazione, un blocco o (per la segreteria) rilascia un'assegnazione. Gli incassi restano registrati (rimborso a mano).
	 *
	 * @param bool $by_staff true per la segreteria: nessun limite di tempo
	 * @throws \InvalidArgumentException
	 */
	public static function cancel( int $reservation_id, bool $by_staff, ?int $acting_person_id = null ): void {
		$r = self::reservation( $reservation_id );
		if ( ! $r || 'booked' !== $r['status'] ) {
			throw new \InvalidArgumentException( 'Prenotazione non trovata.' );
		}
		if ( ! $by_staff ) {
			if ( null === $acting_person_id || (int) $r['person_id'] !== $acting_person_id ) {
				throw new \InvalidArgumentException( 'Non puoi annullare la prenotazione di un altro socio.' );
			}
			$c = self::cancellation( $r );
			if ( ! $c['allowed'] ) {
				throw new \InvalidArgumentException( $c['message'] );
			}
		}
		self::db()->update( Db::t( 'reservations' ), array( 'status' => 'cancelled', 'cancelled_at' => Db::now() ), array( 'id' => $reservation_id ) );
		Audit::log( 'reservation.cancelled', 'resource', (int) $r['resource_id'], array( 'reservation' => $reservation_id, 'kind' => $r['kind'] ) );
		self::refund_credits( $r ); // pagata in crediti: i crediti tornano al socio
		if ( ResourceRules::KIND_ASSIGN === $r['kind'] ) {
			self::notify_waitlist( (int) $r['resource_id'] ); // si è liberato un posto: avviso il primo della lista d'attesa
		}
	}

	// ---------- Ripetizioni ----------

	/**
	 * Prenota la stessa fascia per più settimane di seguito («ogni martedì alle 21»), per un socio. Solo la segreteria; tutte o nessuna:
	 * se una settimana è occupata non si prenota niente e si dice quale.
	 *
	 * @param string[] $guest_names ospiti, uguali a ogni data
	 * @return int[] numeri delle prenotazioni create
	 * @throws \InvalidArgumentException
	 */
	public static function book_series( int $resource_id, int $person_id, string $first_date, string $start, int $slots, int $weeks, array $guest_names = array(), string $note = '' ): array {
		$res = self::get( $resource_id );
		if ( ! $res ) {
			throw new \InvalidArgumentException( 'Risorsa non trovata.' );
		}
		if ( ResourceRules::SLOT !== $res['mode'] ) {
			throw new \InvalidArgumentException( 'Questa risorsa non si prenota a fasce: viene assegnata dalla segreteria.' );
		}
		$weeks = max( 1, min( 52, $weeks ) );
		$person = self::assert_member( $person_id );
		$guests = ResourceRules::guests( (int) $res['guests_max'], $guest_names );
		$ranges = array();
		foreach ( ResourceRules::series_dates( $first_date, $weeks ) as $day ) {
			list( $starts, $ends ) = ResourceRules::slot_range( $res, $day, $start, $slots, Db::now(), true );
			$ranges[]              = array( $starts, $ends, ResourceRules::fee( $res, $slots, count( $guests ), $starts ) );
		}
		$ids = self::insert_many( $res, ResourceRules::KIND_SLOT, $person_id, $ranges, $guests, $note );
		Audit::log( 'reservation.series', 'resource', $resource_id, array( 'series' => $ids[0], 'person_id' => (int) $person['id'], 'count' => count( $ids ), 'first' => $ranges[0][0] ) );
		return $ids;
	}

	/** Annulla questa prenotazione e le successive della stessa serie (la segreteria). @return int prenotazioni annullate */
	public static function cancel_series( int $reservation_id ): int {
		$r = self::reservation( $reservation_id );
		if ( ! $r || 'booked' !== $r['status'] ) {
			throw new \InvalidArgumentException( 'Prenotazione non trovata.' );
		}
		if ( empty( $r['series_id'] ) ) {
			self::cancel( $reservation_id, true );
			return 1;
		}
		$tbl = Db::t( 'reservations' );
		foreach ( self::db()->get_results( self::db()->prepare( "SELECT * FROM $tbl WHERE series_id = %d AND status = 'booked' AND starts_at >= %s", (int) $r['series_id'], $r['starts_at'] ), ARRAY_A ) ?: array() as $paid_row ) {
			self::refund_credits( $paid_row ); // chi aveva pagato in crediti li riavrà
		}
		$n   = (int) self::db()->query( self::db()->prepare( "UPDATE $tbl SET status = 'cancelled', cancelled_at = %s WHERE series_id = %d AND status = 'booked' AND starts_at >= %s", Db::now(), (int) $r['series_id'], $r['starts_at'] ) );
		Audit::log( 'reservation.series_cancelled', 'resource', (int) $r['resource_id'], array( 'series' => (int) $r['series_id'], 'from' => $r['starts_at'], 'count' => $n ) );
		return $n;
	}

	// ---------- Corsi ed eventi che occupano la risorsa ----------

	/** La risorsa a cui è collegata un'attività (null = nessuna, anche se è stato scelto «evento fuori sede»). */
	public static function activity_resource( int $activity_id ): ?int {
		$v = self::activity_link( $activity_id );
		return $v ? $v : null;
	}

	/** Il collegamento com'è memorizzato: null = non indicato, 0 = «nessuna risorsa» scelto apposta (evento fuori sede), altrimenti la risorsa. */
	public static function activity_link( int $activity_id ): ?int {
		$v = self::db()->get_var( self::db()->prepare( 'SELECT resource_id FROM ' . Db::t( 'activities' ) . ' WHERE id = %d', $activity_id ) );
		return null === $v ? null : (int) $v;
	}

	/**
	 * Collega un corso o un evento a una risorsa a fasce (o lo scollega con null; con 0 si dice che non occupa nessuna risorsa): le sue date la
	 * occupano, con l'allestimento prima e dopo, e nessuno la prenota negli stessi orari.
	 *
	 * @return int prenotazioni dei soci già presenti negli orari dell'attività nei prossimi 120 giorni (da annullare o spostare)
	 * @throws \InvalidArgumentException
	 */
	public static function link_activity( int $activity_id, ?int $resource_id ): int {
		if ( $resource_id ) {
			$res = self::get( $resource_id );
			if ( ! $res || ResourceRules::SLOT !== $res['mode'] ) {
				throw new \InvalidArgumentException( 'Un corso o un evento può occupare solo una risorsa a fasce orarie.' );
			}
		}
		self::db()->update( Db::t( 'activities' ), array( 'resource_id' => $resource_id ), array( 'id' => $activity_id ) );
		Audit::log( 'activity.resource', 'activity', $activity_id, array( 'resource' => $resource_id ) );
		return $resource_id ? self::activity_conflicts( $activity_id, $resource_id ) : 0;
	}

	/**
	 * L'allestimento proprio dell'attività, in minuti prima e dopo (null = quello previsto per il suo tipo: 3 ore per gli eventi, niente per i corsi).
	 * Si somma a quello della risorsa.
	 */
	public static function set_activity_setup( int $activity_id, ?int $before_min, ?int $after_min ): void {
		$clean = function ( ?int $m ): ?int {
			return null === $m ? null : max( 0, min( ResourceRules::SETUP_MAX_MINUTES, $m ) );
		};
		self::db()->update( Db::t( 'activities' ), array( 'setup_before_min' => $clean( $before_min ), 'setup_after_min' => $clean( $after_min ) ), array( 'id' => $activity_id ) );
		Audit::log( 'activity.setup', 'activity', $activity_id, array( 'before' => $before_min, 'after' => $after_min ) );
	}

	/** Quante prenotazioni dei soci cadono nel periodo in cui l'attività occupa la risorsa (allestimenti compresi), nei prossimi 120 giorni. */
	public static function activity_conflicts( int $activity_id, int $resource_id ): int {
		$today = Db::today();
		$n     = 0;
		foreach ( self::activity_busy( $resource_id, $today, gmdate( 'Y-m-d', strtotime( $today . ' +120 days' ) ) ) as $w ) {
			if ( (int) $w['activity_id'] === $activity_id ) {
				$n += count( self::between( $resource_id, $w['starts'], $w['ends'] ) );
			}
		}
		return $n;
	}

	// ---------- Lista d'attesa delle assegnazioni ----------

	/** Mette un socio in coda per una risorsa assegnata a periodo (posto auto, armadietto, posto barca). @throws \InvalidArgumentException */
	public static function waitlist_join( int $resource_id, int $person_id ): void {
		$res = self::get( $resource_id );
		if ( ! $res || ! (int) $res['active'] || ResourceRules::ASSIGN !== $res['mode'] ) {
			throw new \InvalidArgumentException( 'La lista d\'attesa c\'è solo per le risorse assegnate a periodo.' );
		}
		self::assert_member( $person_id );
		foreach ( self::for_person( $person_id ) as $r ) {
			if ( (int) $r['resource_id'] === $resource_id && ResourceRules::KIND_ASSIGN === $r['kind'] ) {
				throw new \InvalidArgumentException( 'Hai già questa risorsa.' );
			}
		}
		if ( null !== self::waitlist_position( $resource_id, $person_id ) ) {
			throw new \InvalidArgumentException( 'Sei già in lista d\'attesa.' );
		}
		self::db()->insert( Db::t( 'resource_waitlist' ), array( 'resource_id' => $resource_id, 'person_id' => $person_id, 'status' => 'waiting', 'created_at' => Db::now() ) );
		Audit::log( 'resource.waitlist_joined', 'resource', $resource_id, array( 'person_id' => $person_id ) );
	}

	public static function waitlist_leave( int $resource_id, int $person_id ): void {
		self::db()->update( Db::t( 'resource_waitlist' ), array( 'status' => 'left', 'resolved_at' => Db::now() ), array( 'resource_id' => $resource_id, 'person_id' => $person_id, 'status' => 'waiting' ) );
		Audit::log( 'resource.waitlist_left', 'resource', $resource_id, array( 'person_id' => $person_id ) );
	}

	/** Chi aspetta una risorsa, nell'ordine di arrivo. */
	public static function waitlist( int $resource_id ): array {
		return self::db()->get_results(
			self::db()->prepare(
				'SELECT w.*, p.first_name, p.last_name, p.email FROM ' . Db::t( 'resource_waitlist' ) . ' w JOIN ' . Db::t( 'people' ) . " p ON p.id = w.person_id WHERE w.resource_id = %d AND w.status = 'waiting' ORDER BY w.id",
				$resource_id
			),
			ARRAY_A
		) ?: array();
	}

	/** Posizione in coda (1 = il primo), oppure null se non è in lista. */
	public static function waitlist_position( int $resource_id, int $person_id ): ?int {
		foreach ( self::waitlist( $resource_id ) as $i => $w ) {
			if ( (int) $w['person_id'] === $person_id ) {
				return $i + 1;
			}
		}
		return null;
	}

	/** Avvisa per email il primo della lista d'attesa che la risorsa si è liberata; l'assegnazione resta alla segreteria. */
	private static function notify_waitlist( int $resource_id ): void {
		$res   = self::get( $resource_id );
		$queue = self::waitlist( $resource_id );
		if ( ! $res || ! $queue || ! is_email( (string) $queue[0]['email'] ) ) {
			return;
		}
		Texts::mail(
			$queue[0]['email'],
			'Si è liberato: ' . $res['name'],
			'Ciao ' . $queue[0]['first_name'] . ",\n\nsei il primo in lista d'attesa per «" . $res['name'] . '» e la risorsa si è liberata.'
			. "\n\nLa segreteria ti contatterà per l'assegnazione."
		);
		Audit::log( 'resource.waitlist_notified', 'resource', $resource_id, array( 'person_id' => (int) $queue[0]['person_id'] ) );
	}

	/** Prenotazioni e assegnazioni di un socio: quelle in corso o future per prime. */
	public static function for_person( int $person_id, bool $future_only = true ): array {
		$sql  = 'SELECT r.*, x.name AS resource_name, x.category FROM ' . Db::t( 'reservations' ) . ' r JOIN ' . Db::t( 'resources' ) . ' x ON x.id = r.resource_id AND x.deleted_at IS NULL '
			. "WHERE r.person_id = %d AND r.status = 'booked'" . ( $future_only ? ' AND (r.ends_at IS NULL OR r.ends_at > %s)' : '' ) . ' ORDER BY r.starts_at';
		$args = $future_only ? array( $person_id, Db::now() ) : array( $person_id );
		return self::decorate( self::db()->get_results( self::db()->prepare( $sql, $args ), ARRAY_A ) ?: array() );
	}

	/** Prossime prenotazioni e assegnazioni di una risorsa. */
	public static function upcoming( int $resource_id, int $limit = 100 ): array {
		$rows = self::db()->get_results(
			self::db()->prepare(
				'SELECT r.*, p.first_name, p.last_name FROM ' . Db::t( 'reservations' ) . ' r LEFT JOIN ' . Db::t( 'people' ) . " p ON p.id = r.person_id WHERE r.resource_id = %d AND r.status = 'booked' AND (r.ends_at IS NULL OR r.ends_at > %s) ORDER BY r.starts_at LIMIT %d",
				$resource_id,
				Db::now(),
				$limit
			),
			ARRAY_A
		) ?: array();
		return self::decorate( $rows );
	}

	/** Assegnazioni in corso il cui titolare non ha più la tessera valida: da rinnovare o rilasciare. */
	public static function to_review(): array {
		$rows = self::db()->get_results(
			self::db()->prepare(
				'SELECT r.*, x.name AS resource_name, p.first_name, p.last_name FROM ' . Db::t( 'reservations' ) . ' r JOIN ' . Db::t( 'resources' ) . ' x ON x.id = r.resource_id AND x.deleted_at IS NULL JOIN ' . Db::t( 'people' )
				. " p ON p.id = r.person_id WHERE r.kind = 'assign' AND r.status = 'booked' AND r.starts_at <= %s AND (r.ends_at IS NULL OR r.ends_at > %s) ORDER BY x.name",
				Db::now(),
				Db::now()
			),
			ARRAY_A
		) ?: array();
		return array_values( array_filter( $rows, function ( $r ) {
			return ! Plugin::people()->is_active_member( (int) $r['person_id'] );
		} ) );
	}

	/** Incasso del dovuto di una prenotazione, registrato nel libro cassa (voce «Altra entrata»). @throws \InvalidArgumentException */
	public static function record_payment( int $reservation_id, int $account_id, string $method, int $cents, string $date ): void {
		$r = self::reservation( $reservation_id );
		if ( ! $r || ! $r['person_id'] ) {
			throw new \InvalidArgumentException( 'Prenotazione non trovata.' );
		}
		if ( $cents <= 0 ) {
			throw new \InvalidArgumentException( 'Importo non valido.' );
		}
		$res = self::get( (int) $r['resource_id'] );
		$cat = Plugin::ledger()->category_id_of_kind( 'other_income' );
		if ( ! $cat ) {
			throw new \InvalidArgumentException( 'Manca la voce «Altra entrata» in prima nota.' );
		}
		Plugin::ledger()->record_receipt(
			array(
				'date'       => $date,
				'account_id' => $account_id,
				'method'     => $method,
				'person_id'  => (int) $r['person_id'],
				'lines'      => array(
					array(
						'category_id'    => $cat,
						'amount_cents'   => $cents,
						'reservation_id' => $reservation_id,
						'description'    => mb_substr( ( $res ? $res['name'] : 'Risorsa' ) . ' · ' . substr( (string) $r['starts_at'], 0, 16 ), 0, 120 ),
					),
				),
			)
		);
		Audit::log( 'reservation.paid', 'resource', (int) $r['resource_id'], array( 'reservation' => $reservation_id, 'cents' => $cents ) );
	}
}
