<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Anni solari della contabilità: si creano, si chiudono e si riaprono a mano.
 * Si può incassare, spendere e girare denaro solo in un anno solare aperto. Un anno non si chiude finché ha fondi accantonati non rimborsati.
 */
final class FiscalYears {

	private static function db(): \wpdb {
		return Db::db();
	}

	/** @return array[] anno, status (open|closed), created_at, closed_at — dal più recente */
	public static function all(): array {
		return self::db()->get_results( 'SELECT * FROM ' . Db::t( 'fiscal_years' ) . ' ORDER BY year DESC', ARRAY_A ) ?: array();
	}

	public static function get( int $year ): ?array {
		$row = self::db()->get_row( self::db()->prepare( 'SELECT * FROM ' . Db::t( 'fiscal_years' ) . ' WHERE year = %d', $year ), ARRAY_A );
		return $row ?: null;
	}

	/**
	 * Mese (1-12) in cui inizia l'anno fiscale (esercizio). Di partenza è gennaio, cioè l'anno solare; un'estensione lo cambia con il filtro
	 * `asem_fiscal_start_month`. L'anno si indica con l'anno in cui inizia: con inizio a luglio il 2025 va dal 1 luglio 2025 al 30 giugno 2026.
	 */
	public static function start_month(): int {
		$m = function_exists( 'apply_filters' ) ? (int) apply_filters( 'asem_fiscal_start_month', 1 ) : 1;
		return max( 1, min( 12, $m ) );
	}

	/** Primo e ultimo giorno dell'anno fiscale (regola pura). @return array{0:string,1:string} */
	public static function range_for( int $year, int $start_month ): array {
		$start_month = max( 1, min( 12, $start_month ) );
		if ( 1 === $start_month ) {
			return array( sprintf( '%04d-01-01', $year ), sprintf( '%04d-12-31', $year ) );
		}
		$from = sprintf( '%04d-%02d-01', $year, $start_month );
		$to   = ( new \DateTimeImmutable( sprintf( '%04d-%02d-01', $year + 1, $start_month ) ) )->modify( '-1 day' )->format( 'Y-m-d' );
		return array( $from, $to );
	}

	/** L'anno fiscale in cui cade una data (regola pura). */
	public static function year_for( string $date, int $start_month ): int {
		$y = (int) substr( $date, 0, 4 );
		$m = (int) substr( $date, 5, 2 );
		return $m >= max( 1, min( 12, $start_month ) ) ? $y : $y - 1;
	}

	/** Primo e ultimo giorno di un anno con l'inizio scelto nelle impostazioni. @return array{0:string,1:string} */
	public static function range( int $year ): array {
		return self::range_for( $year, self::start_month() );
	}

	/** L'anno in cui cade una data. */
	public static function year_of( string $date ): int {
		return self::year_for( $date, self::start_month() );
	}

	/** Nome dell'anno per chi legge: «2025» oppure, se non inizia a gennaio, «2025/2026». */
	public static function label( int $year ): string {
		return 1 === self::start_month() ? (string) $year : $year . '/' . ( $year + 1 );
	}

	/** L'anno in corso. */
	public static function current(): int {
		return self::year_of( Db::today() );
	}

	/** L'anno in corso si apre da solo (il primo giorno dell'anno, o al primo movimento) e non può restare chiuso. */
	public static function ensure_current_open(): void {
		$cur = self::current();
		$row = self::get( $cur );
		if ( ! $row ) {
			self::create( $cur );
			Audit::log( 'year.opened_auto', 'year', $cur );
		} elseif ( 'open' !== $row['status'] ) {
			self::reopen( $cur );
			Audit::log( 'year.opened_auto', 'year', $cur );
		}
	}

	/** Controllo leggero a ogni avvio: quando cambia l'anno, il nuovo anno si apre in automatico. */
	public static function maybe_open_current(): void {
		$cur = (string) self::current();
		if ( (string) get_option( 'asem_fy_auto', '' ) !== $cur ) {
			self::ensure_current_open();
			update_option( 'asem_fy_auto', $cur, false );
		}
	}

	/** L'anno solare più recente tra quelli creati (aperto o chiuso). */
	public static function latest(): ?int {
		$y = self::db()->get_var( 'SELECT MAX(year) FROM ' . Db::t( 'fiscal_years' ) );
		return null === $y ? null : (int) $y;
	}

	public static function is_open( int $year ): bool {
		$row = self::get( $year );
		return $row && 'open' === $row['status'];
	}

	public static function create( int $year ): void {
		if ( $year < 2000 || $year > 2100 ) {
			throw new \InvalidArgumentException( 'Anno non valido.' );
		}
		if ( self::get( $year ) ) {
			throw new \InvalidArgumentException( 'L\'anno solare ' . $year . ' esiste già.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		self::db()->insert( Db::t( 'fiscal_years' ), array( 'year' => $year, 'status' => 'open', 'created_at' => Db::now() ) );
		Audit::log( 'year.created', 'year', $year );
	}

	/** Crea l'anno se manca (aperto). Un anno chiuso resta chiuso. */
	public static function ensure( int $year ): void {
		if ( ! self::get( $year ) ) {
			self::create( $year );
		}
	}

	/** Si può registrare un movimento a questa data? Solo se l'anno solare esiste ed è aperto. */
	public static function assert_open_for_date( string $date ): void {
		$year = self::year_of( $date );
		if ( self::current() === $year ) {
			self::ensure_current_open(); // l'anno in corso c'è sempre ed è aperto
		}
		$row = self::get( $year );
		if ( ! $row ) {
			throw new \InvalidArgumentException( 'L\'anno solare ' . $year . ' non è stato creato: crealo da Contabilità › Anni solari prima di registrare movimenti in quella data.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		if ( 'open' !== $row['status'] ) {
			throw new \InvalidArgumentException( 'L\'anno solare ' . $year . ' è chiuso: non accetta più incassi né spese. Riaprilo da Contabilità › Anni solari se devi correggerlo.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
	}

	/** Motivi per cui l'anno non si può chiudere (vuoto = si può). @return string[] */
	public static function blockers( int $year ): array {
		$out = array();
		if ( $year >= self::current() ) {
			$out[] = 'l\'anno ' . ( $year === self::current() ? 'in corso' : 'non è ancora iniziato' ) . ' non si chiude: si chiude dopo la fine dell\'anno';
		}
		$funds = Plugin::funds()->unsettled_until_year( $year );
		if ( $funds > 0 ) {
			$out[] = 'ci sono fondi accantonati non ancora rimborsati (' . Money::format( $funds ) . '): rimborsali o libera le somme prima di chiudere l\'anno';
		}
		return function_exists( 'apply_filters' ) ? (array) apply_filters( 'asem_year_close_blockers', $out, $year ) : $out; // un'estensione aggiunge i suoi motivi
	}

	public static function close( int $year, bool $confirmed = false ): void {
		$row = self::get( $year );
		if ( ! $row ) {
			throw new \InvalidArgumentException( 'Anno solare non trovato.' );
		}
		if ( 'open' !== $row['status'] ) {
			throw new \InvalidArgumentException( 'L\'anno ' . $year . ' è già chiuso.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		$b = self::blockers( $year );
		if ( $b ) {
			throw new \InvalidArgumentException( 'L\'anno ' . $year . ' non si può chiudere: ' . implode( '; ', $b ) . '.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		$gaps = function_exists( 'apply_filters' ) ? (array) apply_filters( 'asem_year_close_confirm', array(), $year ) : array(); // cose da confermare per chiudere lo stesso
		if ( $gaps && ! $confirmed ) {
			throw new \InvalidArgumentException( 'L\'anno ' . $year . ' si chiude solo con la tua conferma: ' . implode( '; ', $gaps ) . '. Spunta la conferma accanto a «Chiudi l\'anno» se vuoi chiuderlo comunque.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		self::db()->update( Db::t( 'fiscal_years' ), array( 'status' => 'closed', 'closed_at' => Db::now() ), array( 'year' => $year ) );
		Audit::log( 'year.closed', 'year', $year, $gaps ? array( 'confirmed_gaps' => $gaps ) : array() );
	}

	public static function reopen( int $year ): void {
		$row = self::get( $year );
		if ( ! $row || 'closed' !== $row['status'] ) {
			throw new \InvalidArgumentException( 'L\'anno solare non è chiuso.' );
		}
		self::db()->update( Db::t( 'fiscal_years' ), array( 'status' => 'open', 'closed_at' => null ), array( 'year' => $year ) );
		Audit::log( 'year.reopened', 'year', $year );
	}

	/** Numero di movimenti registrati in un anno (non annullati). */
	public static function movements( int $year ): int {
		return (int) self::db()->get_var( self::db()->prepare( 'SELECT COUNT(*) FROM ' . Db::t( 'transactions' ) . ' WHERE voided_at IS NULL AND tx_date BETWEEN %s AND %s', self::range( $year )[0], self::range( $year )[1] ) );
	}

	/** Crea gli anni che servono: quello in corso e tutti quelli in cui ci sono già movimenti (per chi aggiorna da una versione senza anni). */
	public static function seed(): void {
		self::ensure( self::current() );
		foreach ( self::db()->get_col( self::db()->prepare( 'SELECT DISTINCT YEAR(DATE_SUB(tx_date, INTERVAL %d MONTH)) FROM ' . Db::t( 'transactions' ), self::start_month() - 1 ) ) ?: array() as $y ) {
			if ( (int) $y >= 2000 && (int) $y <= 2100 ) {
				self::ensure( (int) $y );
			}
		}
	}
}
