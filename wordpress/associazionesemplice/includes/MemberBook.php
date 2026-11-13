<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Libro soci: l'elenco progressivo dei soci con la data di ingresso e, se c'è stato, di cessazione (recesso, esclusione, decesso).
 * Gli ospiti non sono soci e non compaiono.
 *
 * Il libro segue l'anno fiscale (esercizio): ogni esercizio ha il suo libro. Per l'esercizio in corso il libro è dal vivo; alla chiusura
 * dell'esercizio se ne scatta la fotografia, che non cambia più: chi c'è resta anche se poi viene modificato, cancellato o reso anonimo.
 */
final class MemberBook {

	const IN_FORCE = 'in_force';
	const LEFT     = 'left';

	/**
	 * Campi del libro soci che entrano nella fotografia: i dati minimi (proposta dell'audit, da validare con il commercialista). Questo è l'UNICO
	 * elenco: si cambia qui oppure con il filtro `asem_member_book_snapshot_fields`. Non si copiano email, telefono, numero e stato della tessera,
	 * quote e pagamenti, note, consensi, certificati, dati dei genitori e foto. Il numero di riga e la matricola hanno una colonna propria.
	 */
	const SNAPSHOT_FIELDS = array( 'last_name', 'first_name', 'birth_date', 'birth_place', 'tax_code', 'residence', 'level', 'joined_on', 'approved_on', 'left_on', 'left_reason' );

	/** Tutti i campi che una riga del libro sa fornire: un filtro non può chiederne altri. */
	const ROW_FIELDS = array( 'last_name', 'first_name', 'tax_code', 'birth_date', 'birth_place', 'residence', 'email', 'phone', 'applied_on', 'approved_on', 'fee_cents', 'fee_year', 'guardian', 'card_number', 'level', 'joined_on', 'left_on', 'left_reason' );

	private static function db(): \wpdb {
		return Db::db();
	}

	/** Registra (o toglie, con data vuota) la cessazione di un socio. @throws \InvalidArgumentException */
	public static function set_left( int $person_id, string $date, string $reason = '' ): void {
		$p = Plugin::people()->get( $person_id );
		if ( ! $p || ! MemberType::is_member( $p['type'] ) ) {
			throw new \InvalidArgumentException( 'La cessazione si registra per un socio.' );
		}
		$date = trim( $date );
		YearLock::assert_date_open( (string) ( $p['left_on'] ?? '' ) ); // togliere o cambiare una cessazione già in un esercizio chiuso non si può
		YearLock::assert_date_open( $date ); // e nemmeno metterne una con data in un esercizio chiuso
		if ( '' === $date ) {
			self::db()->update( Db::t( 'people' ), array( 'left_on' => null, 'left_reason' => null, 'suspended_at' => null, 'updated_at' => Db::now() ), array( 'id' => $person_id ) );
			if ( MemberType::is_auto_renewed( $p['type'] ) ) {
				Plugin::people()->refresh_founder_membership( $person_id ); // il fondatore torna con la tessera sempre rinnovata
			}
			Audit::log( 'member.left_cleared', 'person', $person_id, array() );
			return;
		}
		$dt = \DateTime::createFromFormat( 'Y-m-d', $date );
		if ( ! $dt || $dt->format( 'Y-m-d' ) !== $date ) {
			throw new \InvalidArgumentException( 'Data di cessazione non valida.' );
		}
		if ( $date > Db::today() ) {
			throw new \InvalidArgumentException( 'La cessazione non può essere nel futuro.' );
		}
		if ( $date < (string) $p['joined_on'] ) {
			throw new \InvalidArgumentException( 'La cessazione non può essere prima dell\'ingresso (' . $p['joined_on'] . ').' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		// Chi ha lasciato l'associazione non è più un socio attivo: non prenota, non riceve promemoria né comunicazioni ai soci, non compare tra quelli da rinnovare.
		// Si usa lo stato «sospeso»; per il fondatore (sempre in regola) si ferma la tessera alla data di cessazione.
		self::db()->update( Db::t( 'people' ), array( 'left_on' => $date, 'left_reason' => mb_substr( trim( sanitize_text_field( $reason ) ), 0, 190 ), 'suspended_at' => ! empty( $p['suspended_at'] ) ? $p['suspended_at'] : Db::now(), 'updated_at' => Db::now() ), array( 'id' => $person_id ) );
		if ( MemberType::is_auto_renewed( $p['type'] ) ) {
			self::db()->query( self::db()->prepare( 'UPDATE ' . Db::t( 'memberships' ) . " SET valid_to = %s WHERE person_id = %d AND social_year = 'FOUNDER' AND deleted_at IS NULL", $date, $person_id ) );
		}
		Audit::log( 'member.left', 'person', $person_id, array( 'date' => $date ) );
	}

	// ---------- Matricola ----------

	/**
	 * La matricola è il numero stabile del socio: assegnata una volta sola (all'ammissione, cioè al primo inserimento nel libro), mai cambiata
	 * e mai riassegnata, nemmeno se la persona viene cancellata o anonimizzata. Il numero di riga di un libro invece riparte da 1 ogni esercizio.
	 */
	private static function next_matricola(): int {
		$max  = (int) self::db()->get_var( 'SELECT MAX(matricola) FROM ' . Db::t( 'people' ) );
		$last = (int) get_option( 'asem_matricola_last', 0 ); // l'ultimo numero dato, anche se poi la persona non c'è più
		$n    = max( $max, $last ) + 1;
		update_option( 'asem_matricola_last', $n, false );
		return $n;
	}

	/** Dà la matricola a un socio che non l'ha ancora (le domande non approvate, gli ospiti e i tutori non sono nel libro). Non cambia mai una matricola già data. */
	public static function ensure_matricola( int $person_id ): void {
		Db::with_lock(
			'asem_matricola',
			function () use ( $person_id ) {
				$p = self::db()->get_row( self::db()->prepare( 'SELECT id, type, matricola, application_status FROM ' . Db::t( 'people' ) . ' WHERE id = %d', $person_id ), ARRAY_A );
				if ( ! $p || null !== $p['matricola'] || ! empty( $p['application_status'] ) || ! MemberType::is_member( (string) $p['type'] ) ) {
					return;
				}
				self::db()->update( Db::t( 'people' ), array( 'matricola' => self::next_matricola() ), array( 'id' => $person_id ) );
			}
		);
	}

	/**
	 * Dà la matricola a tutti i soci che non l'hanno, in ordine di ingresso e di inserimento, senza buchi. Si può rilanciare senza effetti.
	 *
	 * @return int matricole assegnate
	 */
	public static function assign_missing(): int {
		return (int) Db::with_lock(
			'asem_matricola',
			function () {
				$ids = self::db()->get_col( 'SELECT id FROM ' . Db::t( 'people' ) . " WHERE matricola IS NULL AND deleted_at IS NULL AND application_status IS NULL AND type NOT IN ('guest','guardian') ORDER BY joined_on, id" ) ?: array();
				foreach ( $ids as $id ) {
					self::db()->update( Db::t( 'people' ), array( 'matricola' => self::next_matricola() ), array( 'id' => (int) $id ) );
				}
				return count( $ids );
			}
		);
	}

	// ---------- Criterio dell'esercizio ----------

	/**
	 * IL CRITERIO (l'unico punto del codice): chi entra nel libro soci di un esercizio che va da $from a $to (inclusi). Entra chi ha avuto una tessera
	 * valida per almeno un giorno dell'esercizio (valida dal <= fine e valida fino al >= inizio): la tessera a cavallo di due esercizi compare in
	 * entrambi i libri. Il socio fondatore (tessera sempre rinnovata) entra se è stato ammesso entro la fine dell'esercizio. Chi ha lasciato prima
	 * dell'inizio non entra; chi ha lasciato durante l'esercizio entra con la sua data di cessazione. Ospiti e tutori non sono soci.
	 *
	 * @param array   $p     la persona (type, joined_on, left_on)
	 * @param array[] $cards le sue tessere non cancellate (valid_from, valid_to)
	 */
	public static function qualifies( array $p, array $cards, string $from, string $to ): bool {
		$type = (string) ( $p['type'] ?? '' );
		if ( ! MemberType::is_member( $type ) ) {
			return false;
		}
		$joined = (string) ( $p['joined_on'] ?? '' );
		if ( '' !== $joined && $joined > $to ) {
			return false; // ammesso dopo la fine dell'esercizio
		}
		$left = (string) ( $p['left_on'] ?? '' );
		if ( '' !== $left && $left < $from ) {
			return false; // aveva già lasciato prima dell'inizio
		}
		if ( MemberType::is_auto_renewed( $type ) ) {
			return true;
		}
		foreach ( $cards as $c ) {
			if ( (string) $c['valid_from'] <= $to && (string) $c['valid_to'] >= $from ) {
				return true;
			}
		}
		return false;
	}

	/** Le tessere non cancellate di tutte le persone: person_id => [ [valid_from, valid_to], … ]. */
	private static function cards_by_person(): array {
		$out = array();
		foreach ( self::db()->get_results( 'SELECT person_id, valid_from, valid_to FROM ' . Db::t( 'memberships' ) . ' WHERE deleted_at IS NULL', ARRAY_A ) ?: array() as $r ) {
			$out[ (int) $r['person_id'] ][] = $r;
		}
		return $out;
	}

	/**
	 * I soci (righe grezze della tabella persone) che per il criterio {@see MemberBook::qualifies()} rientrano nel periodo da $from a $to. Con un periodo
	 * di un solo giorno ($from = $to) sono i soci di quella data: lo usano i dati per il RUNTS, così il criterio resta uno solo.
	 *
	 * @return array[]
	 */
	public static function qualifying_people( string $from, string $to ): array {
		$rows  = self::db()->get_results( 'SELECT * FROM ' . Db::t( 'people' ) . " WHERE deleted_at IS NULL AND application_status IS NULL AND type NOT IN ('guest','guardian') ORDER BY joined_on, id", ARRAY_A ) ?: array();
		$cards = self::cards_by_person();
		return array_values(
			array_filter(
				$rows,
				function ( $p ) use ( $cards, $from, $to ) {
					return self::qualifies( $p, $cards[ (int) $p['id'] ] ?? array(), $from, $to );
				}
			)
		);
	}

	// ---------- Libro dal vivo e fotografato ----------

	/** Residenza su una riga: via, CAP, comune (provincia). */
	private static function residence( array $p ): string {
		$town = trim( trim( (string) $p['zip'] . ' ' . (string) $p['city'] ) . ( '' !== (string) $p['province'] ? ' (' . $p['province'] . ')' : '' ) );
		return implode( ', ', array_filter( array( trim( (string) $p['address'] ), $town ) ) );
	}

	/** Per ogni persona la quota associativa dell'anno più recente per cui risulta incassata: person_id => [year, cents]. */
	private static function last_fees(): array {
		$rows = self::db()->get_results(
			'SELECT t.person_id, t.social_year, SUM(t.amount_cents + t.discount_cents) AS cents FROM ' . Db::t( 'transactions' ) . ' t JOIN ' . Db::t( 'categories' ) . " c ON c.id = t.category_id WHERE t.person_id IS NOT NULL AND t.type = 'income' AND t.voided_at IS NULL AND c.kind = 'membership' GROUP BY t.person_id, t.social_year ORDER BY t.social_year", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- nomi tabella interni
			ARRAY_A
		) ?: array();
		$out = array();
		foreach ( $rows as $r ) {
			if ( (int) $r['cents'] > 0 ) {
				$out[ (int) $r['person_id'] ] = array( 'year' => (string) $r['social_year'], 'cents' => (int) $r['cents'] ); // l'ultimo anno vince
			}
		}
		return $out;
	}

	/** Campi che entrano nella fotografia: l'elenco di SNAPSHOT_FIELDS, eventualmente cambiato da un'estensione con il filtro `asem_member_book_snapshot_fields`. @return string[] */
	public static function snapshot_fields(): array {
		$f = self::SNAPSHOT_FIELDS;
		if ( function_exists( 'apply_filters' ) ) {
			$f = (array) apply_filters( 'asem_member_book_snapshot_fields', $f );
		}
		return array_values( array_intersect( array_map( 'strval', $f ), self::ROW_FIELDS ) );
	}

	/**
	 * Righe del libro soci in ordine di ingresso, con numero di riga e matricola.
	 *
	 *  - senza $year: tutti i soci mai iscritti, dal vivo (uso interno, per esempio le tessere);
	 *  - con $year: il libro di quell'esercizio. Se l'esercizio è chiuso e fotografato si legge la fotografia (che non cambia più, qualunque cosa
	 *    succeda poi ai soci); altrimenti si compone dal vivo con il criterio {@see MemberBook::qualifies()}. Con $version si legge una chiusura precedente.
	 *
	 * @param string $filter '' tutti, in_force (in carica), left (cessati)
	 * @return array[]
	 */
	public static function rows( string $filter = '', ?int $year = null, ?int $version = null ): array {
		if ( null === $year ) {
			return self::live_rows( $filter, null );
		}
		$cl = null === $version ? FiscalYears::current_closure( $year ) : FiscalYears::closure_version( $year, $version );
		if ( $cl && 'complete' === $cl['snapshot_state'] ) {
			return self::snapshot_rows( (int) $cl['id'], $filter );
		}
		return self::live_rows( $filter, $cl && $cl['range_from'] && $cl['range_to'] ? array( (string) $cl['range_from'], (string) $cl['range_to'] ) : FiscalYears::range( $year ) );
	}

	/** @param array{0:string,1:string}|null $span primo e ultimo giorno dell'esercizio; null = tutti i soci */
	private static function live_rows( string $filter, ?array $span ): array {
		$rows = self::db()->get_results(
			'SELECT * FROM ' . Db::t( 'people' ) . " WHERE deleted_at IS NULL AND application_status IS NULL AND type NOT IN ('guest','guardian') ORDER BY joined_on, id",
			ARRAY_A
		) ?: array();
		$tutori = Guardians::names_by_person();
		$fees   = self::last_fees();
		$by_id  = array();
		foreach ( $rows as $r ) {
			$by_id[ (int) $r['id'] ] = $r;
		}
		if ( null !== $span ) {
			$cards = self::cards_by_person();
			$rows  = array_values(
				array_filter(
					$rows,
					function ( $p ) use ( $cards, $span ) {
						return self::qualifies( $p, $cards[ (int) $p['id'] ] ?? array(), $span[0], $span[1] );
					}
				)
			);
		}
		$ops = Guardians::operative_ids();
		$out = array();
		$n   = 0;
		foreach ( $rows as $p ) {
			$n++;
			$gone = ! empty( $p['left_on'] );
			if ( self::IN_FORCE === $filter && $gone ) {
				continue;
			}
			if ( self::LEFT === $filter && ! $gone ) {
				continue;
			}
			$anon = ! empty( $p['anonymized_at'] );
			$src  = isset( $ops[ (int) $p['id'] ], $by_id[ $ops[ (int) $p['id'] ] ] ) ? $by_id[ $ops[ (int) $p['id'] ] ] : $p; // un minore eredita le date (domanda, delibera, ingresso) del tutore che opera, se è socio
			$out[] = array(
				'n'           => $n,
				'id'          => (int) $p['id'],
				'matricola'   => (int) $p['matricola'],
				'name'        => $anon ? '(dati anonimizzati)' : trim( $p['last_name'] . ' ' . $p['first_name'] ),
				'last_name'   => $anon ? '(dati anonimizzati)' : (string) $p['last_name'],
				'first_name'  => $anon ? '' : (string) $p['first_name'],
				'tax_code'    => $anon ? '' : (string) $p['tax_code'],
				'birth_date'  => $anon ? '' : (string) $p['birth_date'],
				'birth_place' => $anon ? '' : (string) $p['birth_place'],
				'residence'   => $anon ? '' : self::residence( $p ),
				'email'       => $anon ? '' : (string) $p['email'],
				'phone'       => $anon ? '' : (string) $p['phone'],
				'applied_on'  => substr( (string) ( $src['application_at'] ?: $src['joined_on'] ), 0, 10 ),
				'approved_on' => (string) ( $src['approved_on'] ?: $src['joined_on'] ),
				'fee_cents'   => (int) ( $fees[ (int) $p['id'] ]['cents'] ?? 0 ),
				'fee_year'    => (string) ( $fees[ (int) $p['id'] ]['year'] ?? '' ),
				'guardian'    => $anon ? '' : (string) ( $tutori[ (int) $p['id'] ] ?? '' ),
				'card_number' => (string) $p['card_number'],
				'level'       => Levels::label( $p ),
				'joined_on'   => (string) $src['joined_on'],
				'left_on'     => $gone ? (string) $p['left_on'] : '',
				'left_reason' => (string) $p['left_reason'],
				'status'      => $gone ? self::LEFT : self::IN_FORCE,
			);
		}
		return $out;
	}

	/**
	 * Scrive la fotografia del libro soci di un esercizio (i soci che entrano per il criterio, con i soli campi minimi) legata a una chiusura.
	 * Gli inserimenti sono a gruppi, per associazioni con migliaia di soci. Si chiama dentro la stessa operazione della chiusura: se lancia,
	 * la chiusura non avviene.
	 *
	 * @return int righe scritte
	 * @throws \InvalidArgumentException
	 */
	public static function write_snapshot( int $closure_id, string $from, string $to ): int {
		self::assign_missing(); // chi non ha ancora la matricola la riceve ora, così entra con il numero che avrà per sempre
		$db     = self::db();
		$tbl    = Db::t( 'member_book_snapshot' );
		$fields = self::snapshot_fields();
		$rows   = self::live_rows( '', array( $from, $to ) );
		$db->query( $db->prepare( 'DELETE FROM ' . $tbl . ' WHERE closure_id = %d', $closure_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- nome tabella interno
		foreach ( array_chunk( $rows, 200 ) as $chunk ) {
			$values = array();
			foreach ( $chunk as $r ) {
				$data = array();
				foreach ( $fields as $f ) {
					$data[ $f ] = (string) ( $r[ $f ] ?? '' );
				}
				$values[] = '(' . $db->prepare( '%d,%d,%d', $closure_id, (int) $r['n'], (int) $r['id'] ) . ',' . ( (int) $r['matricola'] > 0 ? (string) (int) $r['matricola'] : 'NULL' ) . ',' . $db->prepare( '%s', (string) wp_json_encode( $data, JSON_UNESCAPED_UNICODE ) ) . ')';
			}
			if ( false === $db->query( 'INSERT INTO ' . $tbl . ' (closure_id, n, person_id, matricola, data) VALUES ' . implode( ',', $values ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- nome tabella interno, valori già passati da prepare()
				throw new \InvalidArgumentException( 'La fotografia del libro soci non è riuscita (errore del database).' );
			}
		}
		return count( $rows );
	}

	/** Una riga della fotografia, nella stessa forma di una riga dal vivo: i campi non copiati (recapiti, quote, tutore) restano vuoti. */
	private static function snapshot_row( array $r ): array {
		$d    = json_decode( (string) $r['data'], true );
		$d    = is_array( $d ) ? $d : array();
		$s    = function ( string $k ) use ( $d ): string {
			return (string) ( $d[ $k ] ?? '' );
		};
		$left = $s( 'left_on' );
		return array(
			'n'           => (int) $r['n'],
			'id'          => (int) $r['person_id'],
			'matricola'   => (int) $r['matricola'],
			'name'        => trim( $s( 'last_name' ) . ' ' . $s( 'first_name' ) ),
			'last_name'   => $s( 'last_name' ),
			'first_name'  => $s( 'first_name' ),
			'tax_code'    => $s( 'tax_code' ),
			'birth_date'  => $s( 'birth_date' ),
			'birth_place' => $s( 'birth_place' ),
			'residence'   => $s( 'residence' ),
			'email'       => $s( 'email' ),
			'phone'       => $s( 'phone' ),
			'applied_on'  => $s( 'applied_on' ),
			'approved_on' => $s( 'approved_on' ),
			'fee_cents'   => (int) ( $d['fee_cents'] ?? 0 ),
			'fee_year'    => $s( 'fee_year' ),
			'guardian'    => $s( 'guardian' ),
			'card_number' => $s( 'card_number' ),
			'level'       => $s( 'level' ),
			'joined_on'   => $s( 'joined_on' ),
			'left_on'     => $left,
			'left_reason' => $s( 'left_reason' ),
			'status'      => '' !== $left ? self::LEFT : self::IN_FORCE,
		);
	}

	/** @return array[] le righe fotografate di una chiusura, nell'ordine del libro */
	public static function snapshot_rows( int $closure_id, string $filter = '' ): array {
		$out = array();
		foreach ( self::db()->get_results( self::db()->prepare( 'SELECT n, person_id, matricola, data FROM ' . Db::t( 'member_book_snapshot' ) . ' WHERE closure_id = %d ORDER BY n', $closure_id ), ARRAY_A ) ?: array() as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- nome tabella interno
			$row = self::snapshot_row( $r );
			if ( ( self::IN_FORCE === $filter && self::LEFT === $row['status'] ) || ( self::LEFT === $filter && self::LEFT !== $row['status'] ) ) {
				continue;
			}
			$out[] = $row;
		}
		return $out;
	}

	/** Quante righe di libri fotografati riguardano una persona (restano anche dopo la cancellazione o l'anonimizzazione). */
	public static function snapshot_count_for( int $person_id ): int {
		return (int) self::db()->get_var( self::db()->prepare( 'SELECT COUNT(*) FROM ' . Db::t( 'member_book_snapshot' ) . ' WHERE person_id = %d', $person_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- nome tabella interno
	}

	/**
	 * Le righe fotografate di una persona, per l'esportazione dei suoi dati personali (diritto di accesso): una per ogni libro (esercizio e versione) in cui compare.
	 *
	 * @return array[]
	 */
	public static function snapshot_export( int $person_id ): array {
		$sql = 'SELECT s.n, s.matricola, s.data, c.year, c.version, c.retroactive, c.snapshotted_at, c.closed_at FROM ' . Db::t( 'member_book_snapshot' ) . ' s JOIN ' . Db::t( 'fiscal_year_closures' ) . ' c ON c.id = s.closure_id WHERE s.person_id = %d ORDER BY c.year, c.version';
		$out = array();
		foreach ( self::db()->get_results( self::db()->prepare( $sql, $person_id ), ARRAY_A ) ?: array() as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- nomi tabella interni
			$d     = json_decode( (string) $r['data'], true );
			$out[] = array(
				'esercizio'      => FiscalYears::label( (int) $r['year'] ),
				'versione'       => (int) $r['version'],
				'fotografato_il' => (string) ( $r['snapshotted_at'] ?: $r['closed_at'] ),
				'ricostruito'    => ! empty( $r['retroactive'] ) ? 'sì' : 'no',
				'numero_di_riga' => (int) $r['n'],
				'matricola'      => (int) $r['matricola'] > 0 ? (int) $r['matricola'] : null,
				'dati'           => is_array( $d ) ? $d : array(),
			);
		}
		return $out;
	}

	/**
	 * Intestazione del libro di un esercizio: «Libro soci — esercizio X (fotografato il … / ricostruito il …)» oppure, dal vivo, «aggiornato al …».
	 *
	 * @return array{title:string,state:string,closure:?array,note:string} state: snapshot | unphotographed | live
	 */
	public static function caption( int $year, ?int $version = null ): array {
		$cl    = null === $version ? FiscalYears::current_closure( $year ) : FiscalYears::closure_version( $year, $version );
		$label = FiscalYears::label( $year );
		$ver   = $cl && ( (int) $cl['version'] > 1 || 'superseded' === $cl['status'] ) ? ', chiusura n. ' . (int) $cl['version'] . ( 'superseded' === $cl['status'] ? ', poi riaperta' : '' ) : '';
		if ( $cl && 'complete' === $cl['snapshot_state'] ) {
			$when = self::dmy( (string) ( $cl['snapshotted_at'] ?: $cl['closed_at'] ) );
			return array(
				'title'   => 'Libro soci — esercizio ' . $label . ' (' . ( ! empty( $cl['retroactive'] ) ? 'ricostruito il ' : 'fotografato il ' ) . $when . $ver . ')',
				'state'   => 'snapshot',
				'closure' => $cl,
				'note'    => ! empty( $cl['retroactive'] ) ? 'Ricostruito il ' . $when . ' dai dati di allora ancora presenti: non è stato ripreso al giorno della chiusura. Chi era già stato cancellato non compare e i dati anagrafici sono quelli di quel giorno.' : 'Fotografia presa alla chiusura dell\'esercizio: non cambia più, anche se poi un socio viene modificato, cancellato o reso anonimo.',
			);
		}
		if ( $cl ) {
			return array(
				'title'   => 'Libro soci — esercizio ' . $label . ' (chiuso il ' . self::dmy( (string) $cl['closed_at'] ) . ', non fotografato)',
				'state'   => 'unphotographed',
				'closure' => $cl,
				'note'    => 'Esercizio chiuso prima che esistesse la fotografia del libro: i dati qui sotto sono quelli di oggi e possono essere cambiati. Dalla pagina «Esercizi» puoi fotografarlo ora.',
			);
		}
		return array(
			'title'   => 'Libro soci — esercizio ' . $label . ' (aggiornato al ' . self::dmy( Db::today() ) . ')',
			'state'   => 'live',
			'closure' => null,
			'note'    => 'Libro dal vivo: cambia finché l\'esercizio non viene chiuso. Alla chiusura se ne scatta la fotografia.',
		);
	}

	/** Una data «Y-m-d» o «Y-m-d H:i:s» come gg/mm/aaaa. */
	private static function dmy( string $mysql ): string {
		$d = substr( $mysql, 0, 10 );
		return preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m ) ? $m[3] . '/' . $m[2] . '/' . $m[1] : '';
	}
}
