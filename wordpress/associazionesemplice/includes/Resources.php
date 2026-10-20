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
		return $d;
	}

	public static function save( int $id, array $in ): int {
		$d = self::normalize( $in );
		if ( $d['parent_id'] && ( $d['parent_id'] === $id || ! self::get( (int) $d['parent_id'] ) ) ) {
			$d['parent_id'] = null;
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
	 * Quando i corsi e gli eventi collegati alla risorsa la occupano tra due date (incluse). Un'attività senza orario occupa tutto il giorno.
	 *
	 * @return array[] starts, ends (Y-m-d H:i:s), title, activity_id
	 */
	public static function activity_busy( int $resource_id, string $from, string $to ): array {
		$ids = array_map( 'intval', self::db()->get_col( self::db()->prepare( 'SELECT id FROM ' . Db::t( 'activities' ) . ' WHERE resource_id = %d AND deleted_at IS NULL', $resource_id ) ) ?: array() );
		$out = array();
		if ( ! $ids ) {
			return $out;
		}
		foreach ( Calendar::occurrences( $from, $to ) as $o ) {
			if ( ! in_array( (int) $o['activity_id'], $ids, true ) ) {
				continue;
			}
			$s = $o['start'] ?: '00:00';
			$e = $o['end'] ?: '23:59';
			if ( $e <= $s ) {
				$e = '23:59';
			}
			$out[] = array( 'starts' => $o['date'] . ' ' . $s . ':00', 'ends' => $o['date'] . ' ' . $e . ':00', 'title' => (string) $o['title'], 'activity_id' => (int) $o['activity_id'] );
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
						$used = array( 'id' => 0, 'kind' => 'activity', 'status' => 'booked', 'starts_at' => $x['starts'], 'ends_at' => $x['ends'], 'first_name' => $x['title'], 'last_name' => '', 'note' => $x['title'], 'guests' => array(), 'fee_due_cents' => 0, 'paid' => 0, 'remaining' => 0, 'state' => 'free', 'active' => true );
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
	private static function insert( array $res, string $kind, ?int $person_id, string $starts, ?string $ends, int $fee, array $guests, string $note ): int {
		return self::insert_many( $res, $kind, $person_id, array( array( $starts, $ends, $fee ) ), $guests, $note )[0];
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
	private static function insert_many( array $res, string $kind, ?int $person_id, array $ranges, array $guests, string $note ): array {
		$rid = (int) $res['id'];
		return Db::with_lock(
			'asem_res_' . $rid,
			function () use ( $res, $rid, $kind, $person_id, $ranges, $guests, $note ) {
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
							throw new \InvalidArgumentException( 'Il ' . $when . ' la risorsa è occupata da «' . $x['title'] . '».' );
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
	public static function book_slot( int $resource_id, int $person_id, string $date, string $start, int $slots, array $guest_names = array(), bool $by_staff = false, string $note = '' ): int {
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
		$id = self::insert( $res, ResourceRules::KIND_SLOT, $person_id, $starts, $ends, $fee, $guests, $note );
		Audit::log( 'reservation.created', 'resource', $resource_id, array( 'reservation' => $id, 'person_id' => (int) $person['id'], 'starts' => $starts, 'fee' => $fee ) );
		return $id;
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
		$n   = (int) self::db()->query( self::db()->prepare( "UPDATE $tbl SET status = 'cancelled', cancelled_at = %s WHERE series_id = %d AND status = 'booked' AND starts_at >= %s", Db::now(), (int) $r['series_id'], $r['starts_at'] ) );
		Audit::log( 'reservation.series_cancelled', 'resource', (int) $r['resource_id'], array( 'series' => (int) $r['series_id'], 'from' => $r['starts_at'], 'count' => $n ) );
		return $n;
	}

	// ---------- Corsi ed eventi che occupano la risorsa ----------

	/** La risorsa a cui è collegata un'attività (null = nessuna). */
	public static function activity_resource( int $activity_id ): ?int {
		$v = self::db()->get_var( self::db()->prepare( 'SELECT resource_id FROM ' . Db::t( 'activities' ) . ' WHERE id = %d', $activity_id ) );
		return $v ? (int) $v : null;
	}

	/**
	 * Collega un corso o un evento a una risorsa a fasce (o lo scollega con null): le sue date la occupano e nessuno la prenota negli stessi orari.
	 *
	 * @return int prenotazioni dei soci già presenti negli orari dell'attività nei prossimi 120 giorni (da annullare o spostare)
	 * @throws \InvalidArgumentException
	 */
	public static function link_activity( int $activity_id, ?int $resource_id ): int {
		if ( null !== $resource_id ) {
			$res = self::get( $resource_id );
			if ( ! $res || ResourceRules::SLOT !== $res['mode'] ) {
				throw new \InvalidArgumentException( 'Un corso o un evento può occupare solo una risorsa a fasce orarie.' );
			}
		}
		self::db()->update( Db::t( 'activities' ), array( 'resource_id' => $resource_id ), array( 'id' => $activity_id ) );
		Audit::log( 'activity.resource', 'activity', $activity_id, array( 'resource' => $resource_id ) );
		return null === $resource_id ? 0 : self::activity_conflicts( $activity_id, $resource_id );
	}

	/** Quante prenotazioni dei soci cadono negli orari dell'attività sulla risorsa, nei prossimi 120 giorni. */
	public static function activity_conflicts( int $activity_id, int $resource_id ): int {
		$today = Db::today();
		$n     = 0;
		foreach ( Calendar::occurrences( $today, gmdate( 'Y-m-d', strtotime( $today . ' +120 days' ) ) ) as $o ) {
			if ( (int) $o['activity_id'] !== $activity_id ) {
				continue;
			}
			$s  = $o['start'] ?: '00:00';
			$e  = $o['end'] ?: '23:59';
			$n += count( self::between( $resource_id, $o['date'] . ' ' . $s . ':00', $o['date'] . ' ' . ( $e > $s ? $e : '23:59' ) . ':00' ) );
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
