<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Guardia degli esercizi chiusi: «il chiuso è chiuso». Chi sta per modificare un dato che cade in un esercizio chiuso (un allegato, un saldo
 * iniziale, una cessazione, un'assemblea…) chiama una di queste funzioni: se l'esercizio è chiuso lancia un'eccezione con il messaggio unico
 * «L'anno X è chiuso: per modificarlo va riaperto.». Un anno non ancora creato o aperto non blocca nulla.
 */
final class YearLock {

	/** L'anno fiscale chiuso in cui cade la data, oppure null se la data cade in un anno aperto (o in un anno che non esiste ancora). */
	public static function closed_year_of( string $date ): ?int {
		$date = substr( trim( $date ), 0, 10 );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return null;
		}
		$year = FiscalYears::year_of( $date );
		$row  = FiscalYears::get( $year );
		return $row && 'closed' === $row['status'] ? $year : null;
	}

	/** La data cade in un esercizio chiuso? */
	public static function is_closed_date( string $date ): bool {
		return null !== self::closed_year_of( $date );
	}

	/**
	 * Blocca se la data cade in un esercizio chiuso. Una data vuota non blocca (non c'è nulla da modificare).
	 *
	 * @throws \InvalidArgumentException
	 */
	public static function assert_date_open( string $date ): void {
		$year = self::closed_year_of( $date );
		if ( null !== $year ) {
			throw new \InvalidArgumentException( FiscalYears::closed_message( $year ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
	}

	/**
	 * Blocca un periodo (per esempio la validità di una tessera) che cade interamente in esercizi chiusi. Un periodo che arriva fino a un esercizio
	 * aperto o futuro non blocca: la tessera che prosegue nell'anno in corso si può ancora toccare.
	 *
	 * @throws \InvalidArgumentException
	 */
	public static function assert_range_open( string $from, string $to ): void {
		$last = self::closed_year_of( $to );
		if ( null === $last ) {
			return;
		}
		$first = self::closed_year_of( $from );
		if ( null === $first ) { // l'inizio non cade in un esercizio chiuso: o è aperto, o è precedente al primo esercizio registrato (allora tutto ciò che sta prima è chiuso)
			$all = FiscalYears::all();
			$min = $all ? (int) end( $all )['year'] : null;
			if ( null === $min || FiscalYears::year_of( substr( $from, 0, 10 ) ) >= $min ) {
				return;
			}
		}
		throw new \InvalidArgumentException( FiscalYears::closed_message( $last ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
	}

	/**
	 * Blocca se un movimento è datato in un esercizio chiuso.
	 *
	 * @throws \InvalidArgumentException
	 */
	public static function assert_tx_open( int $tx_id ): void {
		$date = Db::db()->get_var( Db::db()->prepare( 'SELECT tx_date FROM ' . Db::t( 'transactions' ) . ' WHERE id = %d', $tx_id ) );
		if ( null !== $date ) {
			self::assert_date_open( (string) $date );
		}
	}

	/** Gli esercizi chiusi con il loro primo e ultimo giorno. @return array<int,array{0:string,1:string}> anno => [da, a] */
	public static function closed_spans(): array {
		$out = array();
		foreach ( FiscalYears::all() as $r ) {
			if ( 'closed' === $r['status'] ) {
				$out[ (int) $r['year'] ] = FiscalYears::range( (int) $r['year'] );
			}
		}
		return $out;
	}

	/**
	 * Condizione SQL (già pronta) «la data nella colonna $col cade in un esercizio chiuso»; «0 = 1» se non ce ne sono. Serve a escludere dalle
	 * modifiche in massa le righe di esercizi chiusi.
	 */
	public static function in_closed_sql( string $col ): string {
		$parts = array();
		foreach ( self::closed_spans() as $span ) {
			$parts[] = Db::db()->prepare( $col . ' BETWEEN %s AND %s', $span[0], $span[1] ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $col è un nome di colonna scritto nel codice, i valori passano da prepare()
		}
		return $parts ? '(' . implode( ' OR ', $parts ) . ')' : '0 = 1';
	}

	/**
	 * Il primo esercizio chiuso che contiene righe di una tabella del plugin che soddisfano una condizione, oppure null.
	 *
	 * @param string $table nome senza prefisso
	 * @param string $col   colonna con la data (la data del movimento, o della voce del fondo)
	 * @param string $where condizione SQL già preparata (può essere vuota)
	 */
	public static function closed_year_with( string $table, string $col, string $where = '' ): ?int {
		$db = Db::db();
		foreach ( self::closed_spans() as $year => $span ) {
			$sql = 'SELECT COUNT(*) FROM ' . Db::t( $table ) . ' WHERE ' . ( '' !== $where ? '(' . $where . ') AND ' : '' ) . $col . ' BETWEEN ' . $db->prepare( '%s AND %s', $span[0], $span[1] ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- tabella e colonna scritte nel codice, valori già passati da prepare()
			if ( (int) $db->get_var( $sql ) > 0 ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- vedi sopra
				return $year;
			}
		}
		return null;
	}

	/**
	 * Blocca se qualche riga di una tabella (che soddisfa la condizione) cade in un esercizio chiuso.
	 *
	 * @throws \InvalidArgumentException
	 */
	public static function assert_rows_open( string $table, string $col, string $where = '' ): void {
		$year = self::closed_year_with( $table, $col, $where );
		if ( null !== $year ) {
			throw new \InvalidArgumentException( FiscalYears::closed_message( $year ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
	}

	/**
	 * Blocca ciò che cambia i saldi di tutti gli esercizi (per esempio il saldo iniziale di un conto) quando esiste almeno un esercizio chiuso:
	 * il saldo del primo anno si ripercuote su quelli chiusi.
	 *
	 * @throws \InvalidArgumentException
	 */
	public static function assert_none_closed(): void {
		$last = FiscalYears::last_closed();
		if ( null !== $last ) {
			throw new \InvalidArgumentException( FiscalYears::closed_message( $last ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
	}
}
