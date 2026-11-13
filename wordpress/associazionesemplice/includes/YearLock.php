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
