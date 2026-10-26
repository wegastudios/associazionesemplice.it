<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Votazioni e sondaggi dei soci. Un voto per persona (garantito dal database), voto segreto o palese: nel segreto si registra chi ha votato
 * ma non cosa. Le regole pure stanno in {@see PollRules}.
 */
final class Polls {

	const MAX_QUESTION = 3000;

	private static function db(): \wpdb {
		return Db::db();
	}

	// ---------- Registro ----------

	/**
	 * Crea o modifica. Dopo il primo voto restano modificabili solo titolo, testo, chiusura, risultati ai soci e avvisi:
	 * opzioni, tipo di scelta, segretezza e chi vota non cambiano più.
	 *
	 * @throws \InvalidArgumentException
	 */
	public static function save( int $id, array $in, ?int $user_id = null ): int {
		if ( ! $id ) {
			return self::save_locked( $id, $in, $user_id );
		}
		return (int) Db::with_lock( 'asem_poll_' . $id, function () use ( $id, $in, $user_id ) { // lo stesso blocco del voto: il primo voto non arriva mentre si riscrivono le risposte
			return self::save_locked( $id, $in, $user_id );
		} );
	}

	private static function save_locked( int $id, array $in, ?int $user_id ): int {
		$title = mb_substr( trim( sanitize_text_field( (string) ( $in['title'] ?? '' ) ) ), 0, 190 );
		if ( '' === $title ) {
			throw new \InvalidArgumentException( 'Scrivi il titolo.' );
		}
		$question = mb_substr( trim( sanitize_textarea_field( (string) ( $in['question'] ?? '' ) ) ), 0, self::MAX_QUESTION );
		$opens    = PollRules::parse_local( (string) ( $in['opens_at'] ?? '' ) );
		$closes   = PollRules::parse_local( (string) ( $in['closes_at'] ?? '' ) );
		if ( ! $opens || ! $closes ) {
			throw new \InvalidArgumentException( 'Indica quando si apre e quando si chiude.' );
		}
		$err = PollRules::check_window( $opens, $closes );
		if ( $err ) {
			throw new \InvalidArgumentException( $err ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		$kind     = isset( PollRules::kinds()[ (string) ( $in['kind'] ?? '' ) ] ) ? (string) $in['kind'] : 'vote';
		$locked   = $id && self::vote_count( $id ) > 0;
		$old      = $id ? self::get( $id ) : null;
		if ( $id && ! $old ) {
			throw new \InvalidArgumentException( 'Votazione non trovata.' );
		}
		$d = array(
			'kind'         => $kind,
			'title'        => $title,
			'question'     => $question,
			'opens_at'     => $opens,
			'closes_at'    => $closes,
			'show_results' => empty( $in['show_results'] ) ? 0 : 1,
			'notify'       => empty( $in['notify'] ) ? 0 : 1,
		);
		if ( ! $locked ) {
			$audience = (string) ( $in['audience'] ?? PollRules::AUDIENCE_MEMBERS );
			$aid      = (int) ( $in['activity_id'] ?? 0 );
			if ( PollRules::AUDIENCE_ACTIVITY === $audience ) {
				if ( ! $aid || ! Plugin::activities()->get( $aid ) ) {
					throw new \InvalidArgumentException( 'Scegli l\'attività i cui iscritti possono votare.' );
				}
			} else {
				$audience = PollRules::AUDIENCE_MEMBERS;
				$aid      = 0;
			}
			$multiple = ! empty( $in['multiple'] ) ? 1 : 0;
			$raw      = $in['options'] ?? array();
			$labels   = PollRules::normalize_options( is_array( $raw ) ? $raw : preg_split( "/\r\n|\n|\r/", (string) $raw ) );
			$d       += array(
				'multiple'    => $multiple,
				'max_choices' => $multiple ? max( 0, min( PollRules::MAX_OPTIONS, (int) ( $in['max_choices'] ?? 0 ) ) ) : 0,
				'secret'      => empty( $in['secret'] ) ? 0 : 1,
				'audience'    => $audience,
				'activity_id' => $aid ?: null,
			);
		}
		$db = self::db();
		if ( $id ) {
			$db->update( Db::t( 'polls' ), $d + array( 'updated_at' => Db::now() ), array( 'id' => $id ) );
		} else {
			if ( ! $db->insert( Db::t( 'polls' ), $d + array( 'created_by' => $user_id, 'created_at' => Db::now(), 'updated_at' => Db::now() ) ) ) {
				throw new \InvalidArgumentException( 'Impossibile salvare (errore del database).' );
			}
			$id = (int) $db->insert_id;
		}
		if ( ! $locked && isset( $labels ) ) {
			$db->delete( Db::t( 'poll_options' ), array( 'poll_id' => $id ) );
			foreach ( $labels as $i => $label ) {
				$db->insert( Db::t( 'poll_options' ), array( 'poll_id' => $id, 'label' => $label, 'sort_order' => $i ) );
			}
		}
		Audit::log( $old ? 'poll.updated' : 'poll.created', 'poll', $id, array( 'kind' => $kind ) );
		return $id;
	}

	public static function delete( int $id ): void {
		if ( ! self::get( $id ) ) {
			throw new \InvalidArgumentException( 'Votazione non trovata.' );
		}
		self::db()->update( Db::t( 'polls' ), array( 'deleted_at' => Db::now() ), array( 'id' => $id ) );
		Audit::log( 'poll.deleted', 'poll', $id );
	}

	public static function get( int $id ): ?array {
		$r = self::db()->get_row( self::db()->prepare( 'SELECT * FROM ' . Db::t( 'polls' ) . ' WHERE id = %d AND deleted_at IS NULL', $id ), ARRAY_A );
		return $r ? self::with_status( $r ) : null;
	}

	private static function with_status( array $r ): array {
		$r['status'] = PollRules::status( (string) $r['opens_at'], (string) $r['closes_at'], Db::now() );
		return $r;
	}

	/** @return array[] dalla più recente */
	public static function all(): array {
		$rows = self::db()->get_results( 'SELECT * FROM ' . Db::t( 'polls' ) . ' WHERE deleted_at IS NULL ORDER BY opens_at DESC, id DESC', ARRAY_A ) ?: array();
		return array_map( array( __CLASS__, 'with_status' ), $rows );
	}

	/** @return array[] id, label */
	public static function options( int $poll_id ): array {
		return self::db()->get_results( self::db()->prepare( 'SELECT id, label, votes FROM ' . Db::t( 'poll_options' ) . ' WHERE poll_id = %d ORDER BY sort_order, id', $poll_id ), ARRAY_A ) ?: array();
	}

	public static function vote_count( int $poll_id ): int {
		return (int) self::db()->get_var( self::db()->prepare( 'SELECT COUNT(*) FROM ' . Db::t( 'poll_voters' ) . ' WHERE poll_id = %d', $poll_id ) );
	}

	// ---------- Chi può votare ----------

	/** La persona ha diritto al voto in questa votazione? */
	public static function is_eligible( array $poll, array $person ): bool {
		if ( ! empty( $person['anonymized_at'] ) || ! empty( $person['suspended_at'] ) || ! empty( $person['deleted_at'] ) || ! empty( $person['application_status'] ) ) {
			return false;
		}
		if ( ! empty( $person['left_on'] ) && (string) $person['left_on'] <= Db::today() ) {
			return false; // socio uscito
		}
		$in_audience = true;
		if ( PollRules::AUDIENCE_ACTIVITY === $poll['audience'] ) {
			$in_audience = (bool) self::db()->get_var(
				self::db()->prepare( 'SELECT COUNT(*) FROM ' . Db::t( 'enrollments' ) . ' WHERE activity_id = %d AND person_id = %d AND end_month IS NULL', (int) $poll['activity_id'], (int) $person['id'] )
			);
		}
		$active = MemberType::is_member( (string) $person['type'] ) && Plugin::people()->is_active_member( (int) $person['id'] );
		return PollRules::is_eligible( (string) $person['type'], $active, $in_audience );
	}

	/** Quanti hanno diritto al voto: una sola interrogazione (stesse regole di is_eligible). */
	public static function eligible_count( array $poll ): int {
		$db    = self::db();
		$today = Db::today();
		$types = "'" . implode( "','", array_map( 'esc_sql', MemberType::member_types() ) ) . "'";
		$sql   = 'SELECT COUNT(*) FROM ' . Db::t( 'people' ) . " p WHERE p.deleted_at IS NULL AND p.anonymized_at IS NULL AND p.application_status IS NULL AND p.suspended_at IS NULL AND p.type IN ($types) AND (p.left_on IS NULL OR p.left_on > %s) "
			. 'AND EXISTS (SELECT 1 FROM ' . Db::t( 'memberships' ) . ' m WHERE m.person_id = p.id AND m.deleted_at IS NULL AND m.valid_from <= %s AND m.valid_to >= %s)';
		$args  = array( $today, $today, $today );
		if ( PollRules::AUDIENCE_ACTIVITY === $poll['audience'] ) {
			$sql   .= ' AND EXISTS (SELECT 1 FROM ' . Db::t( 'enrollments' ) . ' e WHERE e.person_id = p.id AND e.activity_id = %d AND e.end_month IS NULL)';
			$args[] = (int) $poll['activity_id'];
		}
		return (int) $db->get_var( $db->prepare( $sql, $args ) );
	}

	/** Tutte le persone che hanno diritto al voto. @return array[] */
	public static function eligible_people( array $poll ): array {
		$out = array();
		foreach ( Plugin::people()->search() as $p ) {
			if ( MemberType::is_member( $p['type'] ) && self::is_eligible( $poll, $p ) ) {
				$out[] = $p;
			}
		}
		return $out;
	}

	public static function has_voted( int $poll_id, int $person_id ): bool {
		return (bool) self::db()->get_var( self::db()->prepare( 'SELECT COUNT(*) FROM ' . Db::t( 'poll_voters' ) . ' WHERE poll_id = %d AND person_id = %d', $poll_id, $person_id ) );
	}

	/** Le votazioni che la persona può vedere: aperte, già chiuse e non ancora aperte non compaiono; le chiuse solo con i risultati ai soci. @return array[] */
	public static function for_person( array $person ): array {
		$out = array();
		foreach ( self::all() as $poll ) {
			if ( PollRules::UPCOMING === $poll['status'] || ! self::is_eligible( $poll, $person ) ) {
				continue;
			}
			if ( PollRules::CLOSED === $poll['status'] && ! $poll['show_results'] ) {
				continue;
			}
			$poll['voted'] = self::has_voted( (int) $poll['id'], (int) $person['id'] );
			$out[]         = $poll;
		}
		return $out;
	}

	// ---------- Voto ----------

	/**
	 * Registra il voto di una persona. Il database non accetta due voti della stessa persona: se due richieste arrivano insieme, passa una sola.
	 *
	 * @param int[] $choices
	 * @throws \InvalidArgumentException
	 */
	public static function vote( int $poll_id, int $person_id, array $choices ): void {
		Db::with_lock( 'asem_poll_' . $poll_id, function () use ( $poll_id, $person_id, $choices ) {
			self::vote_locked( $poll_id, $person_id, $choices );
		} );
	}

	private static function vote_locked( int $poll_id, int $person_id, array $choices ): void {
		$poll   = self::get( $poll_id );
		$person = Plugin::people()->get( $person_id );
		if ( ! $poll || ! $person ) {
			throw new \InvalidArgumentException( 'Votazione non trovata.' );
		}
		$why = PollRules::cannot_vote_reason( (string) $poll['status'], self::is_eligible( $poll, $person ), self::has_voted( $poll_id, $person_id ) );
		if ( '' !== $why ) {
			throw new \InvalidArgumentException( $why ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		$ids  = array_map( 'intval', array_column( self::options( $poll_id ), 'id' ) );
		$mine = PollRules::check_choices( (bool) $poll['multiple'], (int) $poll['max_choices'], $choices, $ids );
		$db = self::db();
		$db->query( 'START TRANSACTION' );
		try {
			$db->query( $db->prepare( 'INSERT IGNORE INTO ' . Db::t( 'poll_voters' ) . ' (poll_id, person_id, voted_at) VALUES (%d, %d, %s)', $poll_id, $person_id, Db::now() ) );
			if ( 1 !== (int) $db->rows_affected ) {
				throw new \InvalidArgumentException( 'Hai già votato.' );
			}
			foreach ( $mine as $oid ) { // contatore dell'opzione: nel voto segreto è l'unica traccia della scelta
				if ( false === $db->query( $db->prepare( 'UPDATE ' . Db::t( 'poll_options' ) . ' SET votes = votes + 1 WHERE id = %d AND poll_id = %d', $oid, $poll_id ) ) || 1 !== (int) $db->rows_affected ) {
					throw new \InvalidArgumentException( 'Il voto non è stato registrato: riprova.' );
				}
			}
			foreach ( PollRules::ballot_rows( $poll_id, (bool) $poll['secret'], $person_id, $mine ) as $row ) { // solo nel voto palese
				if ( ! $db->insert( Db::t( 'poll_votes' ), $row ) ) {
					throw new \InvalidArgumentException( 'Il voto non è stato registrato: riprova.' );
				}
			}
			$db->query( 'COMMIT' );
		} catch ( \Throwable $e ) {
			$db->query( 'ROLLBACK' );
			throw $e;
		}
		// Nessuna riga nel registro azioni: conterrebbe utente e ora del voto, e nel voto segreto romperebbe il segreto.
	}

	// ---------- Risultati ----------

	/**
	 * @return array{options:array[],voters:int,eligible:int,total:int,winners:int[]} options: id, label, count, percent, names (solo voto palese)
	 */
	public static function results( int $poll_id, bool $with_names = false ): array {
		$poll = self::get( $poll_id );
		if ( ! $poll ) {
			throw new \InvalidArgumentException( 'Votazione non trovata.' );
		}
		$opts  = self::options( $poll_id );
		$votes = array();
		foreach ( $opts as $o ) { // i contatori delle opzioni: valgono sia per il voto segreto sia per il palese
			$votes = array_merge( $votes, array_fill( 0, (int) $o['votes'], (int) $o['id'] ) );
		}
		$tally = PollRules::tally( array_column( $opts, 'id' ), $votes );
		$voters = self::vote_count( $poll_id );
		$names  = array();
		if ( $with_names && ! $poll['secret'] ) {
			foreach ( self::db()->get_results( self::db()->prepare( 'SELECT v.option_id, p.first_name, p.last_name, p.anonymized_at FROM ' . Db::t( 'poll_votes' ) . ' v LEFT JOIN ' . Db::t( 'people' ) . ' p ON p.id = v.person_id WHERE v.poll_id = %d ORDER BY p.last_name, p.first_name', $poll_id ), ARRAY_A ) ?: array() as $r ) {
				$names[ (int) $r['option_id'] ][] = ! empty( $r['anonymized_at'] ) ? 'Persona anonimizzata' : trim( (string) $r['first_name'] . ' ' . (string) $r['last_name'] );
			}
		}
		foreach ( $opts as &$o ) {
			$o['id']      = (int) $o['id'];
			$o['count']   = $tally[ $o['id'] ];
			$o['percent'] = PollRules::percent( $o['count'], $voters );
			$o['names']   = $names[ $o['id'] ] ?? array();
		}
		unset( $o );
		return array( 'options' => $opts, 'voters' => $voters, 'eligible' => self::eligible_count( $poll ), 'total' => array_sum( $tally ), 'winners' => PollRules::winners( $tally ) );
	}

	/** Chi ha votato (non cosa), per la segreteria. @return string[] */
	public static function voter_names( int $poll_id ): array {
		$out = array();
		foreach ( self::db()->get_results( self::db()->prepare( 'SELECT p.first_name, p.last_name, p.anonymized_at FROM ' . Db::t( 'poll_voters' ) . ' v LEFT JOIN ' . Db::t( 'people' ) . ' p ON p.id = v.person_id WHERE v.poll_id = %d ORDER BY p.last_name, p.first_name', $poll_id ), ARRAY_A ) ?: array() as $r ) {
			$out[] = ! empty( $r['anonymized_at'] ) ? 'Persona anonimizzata' : trim( (string) $r['first_name'] . ' ' . (string) $r['last_name'] );
		}
		return $out;
	}

	// ---------- Avvisi ----------

	/** Testo dell'avviso di apertura. */
	private static function open_text( array $poll ): array {
		$area = Gatekeeper::area_url();
		return array(
			( 'poll' === $poll['kind'] ? 'Nuovo sondaggio: ' : 'Votazione aperta: ' ) . $poll['title'],
			'è aperta ' . ( 'poll' === $poll['kind'] ? 'un sondaggio' : 'una votazione' ) . ' per i soci: «' . $poll['title'] . '». Puoi rispondere fino al ' . mysql2date( 'd/m/Y \a\l\l\e H:i', (string) $poll['closes_at'] ) . ' dall\'area riservata: ' . $area . ( $poll['secret'] ? "\nIl voto è segreto." : '' ),
		);
	}

	/**
	 * Manda l'avviso ai soci che hanno diritto al voto e non hanno ancora votato, una sola volta per persona.
	 *
	 * @param string $kind   poll_open | poll_closing
	 * @param bool   $manual invio dal pulsante «Avvisa i soci ora»: vale una volta al giorno per persona, anche se l'avviso automatico è già partito
	 * @return int avvisi partiti
	 */
	public static function announce( int $poll_id, string $kind = 'poll_open', bool $send = true, bool $manual = false ): int {
		$poll = self::get( $poll_id );
		if ( ! $poll || PollRules::OPEN !== $poll['status'] ) {
			throw new \InvalidArgumentException( 'L\'avviso si manda quando la votazione è aperta.' );
		}
		list( $subject, $body ) = self::open_text( $poll );
		if ( 'poll_closing' === $kind ) {
			$subject = 'Ultimo giorno per votare: ' . $poll['title'];
			$body    = 'la votazione «' . $poll['title'] . '» si chiude il ' . mysql2date( 'd/m/Y \a\l\l\e H:i', (string) $poll['closes_at'] ) . ' e non risulta il tuo voto. Puoi votare dall\'area riservata: ' . Gatekeeper::area_url();
		}
		$ref = $manual ? 'm' . $poll_id . ':' . gmdate( 'Ymd', strtotime( Db::today() ) ) : (string) $poll_id;
		$n   = 0;
		foreach ( self::eligible_people( $poll ) as $p ) {
			if ( self::has_voted( $poll_id, (int) $p['id'] ) ) {
				continue;
			}
			if ( $send ? Reminders::notify( $p, $kind, $ref, $subject, $body ) : Reminders::pending( $p, $kind, $ref ) ) {
				$n++;
			}
		}
		if ( $send ) {
			Audit::log( 'poll.announced', 'poll', $poll_id, array( 'kind' => $kind, 'sent' => $n ) );
		}
		return $n;
	}

	/** Promemoria del giorno: avviso di apertura e, nelle ultime 48 ore, quello di chiusura. @return int avvisi (o da mandare, senza invio) */
	public static function remind( bool $send = true ): int {
		$n = 0;
		foreach ( self::all() as $poll ) {
			if ( PollRules::OPEN !== $poll['status'] || ! $poll['notify'] ) {
				continue;
			}
			$n += self::announce( (int) $poll['id'], 'poll_open', $send );
			if ( strtotime( (string) $poll['closes_at'] ) - strtotime( Db::now() ) <= 2 * DAY_IN_SECONDS ) {
				$n += self::announce( (int) $poll['id'], 'poll_closing', $send );
			}
		}
		return $n;
	}
}
