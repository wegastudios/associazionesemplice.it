<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Libro dei verbali: assemblee dei soci e riunioni del consiglio direttivo, con numerazione progressiva per tipo e anno.
 *
 * Il numero (1/2026, 2/2026…) si assegna una volta sola, alla creazione, sotto blocco, e si salva: non si ricalcola, non cambia se altri verbali
 * vengono cancellati o spostati e non si riassegna mai (nemmeno dopo una cancellazione). L'anno del numero è l'anno fiscale dell'ente alla data
 * della riunione (con inizio a gennaio è l'anno solare). Un verbale con la data in un esercizio chiuso non si crea, non si modifica e non si cancella.
 */
final class Minutes {

	const ASSEMBLY = 'assembly';
	const BOARD    = 'board';

	const MAX_BODY = 60000;

	public static function kinds(): array {
		return array( self::ASSEMBLY => 'Assemblea dei soci', self::BOARD => 'Consiglio direttivo' );
	}

	private static function db(): \wpdb {
		return Db::db();
	}

	private static function date( $v ): ?string {
		$v  = trim( (string) $v );
		$dt = \DateTime::createFromFormat( 'Y-m-d', $v );
		return $dt && $dt->format( 'Y-m-d' ) === $v ? $v : null;
	}

	private static function clean( array $in ): array {
		$kind = (string) ( $in['kind'] ?? '' );
		if ( ! isset( self::kinds()[ $kind ] ) ) {
			throw new \InvalidArgumentException( 'Scegli il tipo di riunione.' );
		}
		$date = self::date( $in['meeting_date'] ?? '' );
		if ( ! $date ) {
			throw new \InvalidArgumentException( 'Indica la data della riunione.' );
		}
		$title = trim( sanitize_text_field( (string) ( $in['title'] ?? '' ) ) );
		if ( '' === $title ) {
			throw new \InvalidArgumentException( 'Indica un titolo (ad esempio «Approvazione del bilancio»).' );
		}
		$body = trim( sanitize_textarea_field( (string) ( $in['body'] ?? '' ) ) );
		if ( '' === $body ) {
			throw new \InvalidArgumentException( 'Il verbale non ha contenuto.' );
		}
		if ( mb_strlen( $body ) > self::MAX_BODY ) {
			throw new \InvalidArgumentException( 'Il testo è troppo lungo.' );
		}
		$approved = trim( (string) ( $in['approved_on'] ?? '' ) );
		if ( '' !== $approved && ! self::date( $approved ) ) {
			throw new \InvalidArgumentException( 'Data di approvazione non valida.' );
		}
		return array(
			'kind'         => $kind,
			'meeting_date' => $date,
			'place'        => mb_substr( trim( sanitize_text_field( (string) ( $in['place'] ?? '' ) ) ), 0, 190 ),
			'title'        => mb_substr( $title, 0, 190 ),
			'attendees'    => mb_substr( trim( sanitize_textarea_field( (string) ( $in['attendees'] ?? '' ) ) ), 0, 5000 ),
			'agenda'       => mb_substr( trim( sanitize_textarea_field( (string) ( $in['agenda'] ?? '' ) ) ), 0, 5000 ),
			'body'         => $body,
			'approved_on'  => '' === $approved ? null : $approved,
		);
	}

	/** @throws \InvalidArgumentException */
	public static function save( int $id, array $in, ?int $user_id = null ): int {
		$d = self::clean( $in );
		if ( $id ) {
			$old = self::get( $id );
			if ( ! $old ) {
				throw new \InvalidArgumentException( 'Verbale non trovato.' );
			}
			YearLock::assert_date_open( (string) $old['meeting_date'] ); // un verbale di un esercizio chiuso è immutabile: testo, data e firmatari
			YearLock::assert_date_open( $d['meeting_date'] ); // e non si sposta dentro un esercizio chiuso
			self::db()->update( Db::t( 'minutes' ), $d + array( 'updated_at' => Db::now() ), array( 'id' => $id ) ); // il numero non cambia mai, nemmeno se la data passa a un altro anno
			Audit::log( 'minutes.updated', 'minutes', $id, array( 'kind' => $d['kind'] ) );
			return $id;
		}
		YearLock::assert_date_open( $d['meeting_date'] ); // nemmeno si crea un verbale dentro un esercizio chiuso
		$ok = Db::with_lock(
			'asem_minutes_number',
			function () use ( $d, $user_id ) {
				$year = FiscalYears::year_of( $d['meeting_date'] );
				$seq  = self::next_seq( $d['kind'], $year ); // conta anche i verbali cancellati: un numero usato non torna libero
				return self::db()->insert( Db::t( 'minutes' ), $d + array( 'number_year' => $year, 'number_seq' => $seq, 'created_by' => $user_id, 'created_at' => Db::now(), 'updated_at' => Db::now() ) );
			}
		);
		if ( ! $ok ) {
			throw new \InvalidArgumentException( 'Impossibile salvare il verbale (errore del database).' );
		}
		$id = (int) self::db()->insert_id;
		Audit::log( 'minutes.created', 'minutes', $id, array( 'kind' => $d['kind'] ) );
		return $id;
	}

	/** Il prossimo numero libero di un tipo e anno: il più alto mai assegnato (anche a verbali poi cancellati) più uno. Da chiamare sotto il blocco `asem_minutes_number`. */
	private static function next_seq( string $kind, int $year ): int {
		return 1 + (int) self::db()->get_var( self::db()->prepare( 'SELECT MAX(number_seq) FROM ' . Db::t( 'minutes' ) . ' WHERE kind = %s AND number_year = %d', $kind, $year ) );
	}

	public static function delete( int $id ): void {
		$old = self::get( $id );
		if ( ! $old ) {
			throw new \InvalidArgumentException( 'Verbale non trovato.' );
		}
		YearLock::assert_date_open( (string) $old['meeting_date'] );
		self::db()->update( Db::t( 'minutes' ), array( 'deleted_at' => Db::now() ), array( 'id' => $id ) );
		Audit::log( 'minutes.deleted', 'minutes', $id, array() );
	}

	public static function get( int $id ): ?array {
		$r = self::db()->get_row( self::db()->prepare( 'SELECT * FROM ' . Db::t( 'minutes' ) . ' WHERE id = %d AND deleted_at IS NULL', $id ), ARRAY_A );
		return $r ? self::with_number( $r ) : null;
	}

	/** Numero progressivo del verbale nel suo tipo e anno (1/2026, 2/2026…): quello salvato alla creazione. */
	private static function with_number( array $r ): array {
		if ( null === ( $r['number_seq'] ?? null ) || null === ( $r['number_year'] ?? null ) ) { // verbale senza numero (non è passata la migrazione): lo prende ora, una volta per sempre
			$r = self::assign_number( $r );
		}
		$r['number'] = (int) $r['number_seq'] . '/' . (int) $r['number_year'];
		return $r;
	}

	/** Assegna il numero a un verbale che non ce l'ha ancora (di solito lo fa già la migrazione, {@see Install::migrate_minute_numbers()}). */
	private static function assign_number( array $r ): array {
		return (array) Db::with_lock(
			'asem_minutes_number',
			function () use ( $r ) {
				$cur = self::db()->get_row( self::db()->prepare( 'SELECT number_year, number_seq FROM ' . Db::t( 'minutes' ) . ' WHERE id = %d', (int) $r['id'] ), ARRAY_A );
				if ( $cur && null !== $cur['number_seq'] && null !== $cur['number_year'] ) {
					return array_merge( $r, $cur );
				}
				$year = (int) substr( (string) $r['meeting_date'], 0, 4 );
				$seq  = self::next_seq( (string) $r['kind'], $year );
				self::db()->update( Db::t( 'minutes' ), array( 'number_year' => $year, 'number_seq' => $seq ), array( 'id' => (int) $r['id'] ) );
				return array_merge( $r, array( 'number_year' => $year, 'number_seq' => $seq ) );
			}
		);
	}

	/** Verbali, dal più recente. @return array[] */
	public static function all( ?string $kind = null, ?int $year = null ): array {
		$where = array( 'deleted_at IS NULL' );
		$args  = array();
		if ( $kind && isset( self::kinds()[ $kind ] ) ) {
			$where[] = 'kind = %s';
			$args[]  = $kind;
		}
		if ( $year ) {
			$where[] = 'YEAR(meeting_date) = %d';
			$args[]  = $year;
		}
		$sql  = 'SELECT * FROM ' . Db::t( 'minutes' ) . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY meeting_date DESC, id DESC';
		$rows = $args ? self::db()->get_results( self::db()->prepare( $sql, $args ), ARRAY_A ) : self::db()->get_results( $sql, ARRAY_A );
		return array_map( array( __CLASS__, 'with_number' ), $rows ?: array() );
	}
}
