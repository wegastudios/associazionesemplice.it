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
		$m = function_exists( 'get_option' ) ? (int) Settings::get( 'fiscal_start_month' ) : 1; // un'unica fonte di verità: l'impostazione «L'anno fiscale inizia a» (tutte le edizioni)
		$m = function_exists( 'apply_filters' ) ? (int) apply_filters( 'asem_fiscal_start_month', $m ) : $m;
		return max( 1, min( 12, $m ) );
	}

	/** Dopo la chiusura di un esercizio il mese di inizio non si cambia più: gli anni si ridisegnerebbero e quelli chiusi cambierebbero confini. */
	public static function start_month_locked(): bool {
		return null !== self::last_closed();
	}

	/** Messaggio mostrato quando si prova a cambiare il mese con esercizi chiusi. */
	public static function start_month_locked_message(): string {
		return 'Esistono esercizi chiusi: il mese di inizio non si può più cambiare.';
	}

	/** Messaggio unico per ogni azione bloccata perché tocca un anno chiuso. */
	public static function closed_message( int $year ): string {
		return 'L\'anno ' . self::label( $year ) . ' è chiuso: per modificarlo va riaperto.';
	}

	/** L'esercizio chiuso più recente (l'unico che si può riaprire), oppure null se nessuno è chiuso. */
	public static function last_closed(): ?int {
		$y = self::db()->get_var( 'SELECT MAX(year) FROM ' . Db::t( 'fiscal_years' ) . " WHERE status = 'closed'" );
		return null === $y ? null : (int) $y;
	}

	// ---------- Traccia permanente di chiusure e riaperture ----------

	/** La chiusura in vigore di un anno (l'ultima versione), se l'anno è stato chiuso. */
	public static function current_closure( int $year ): ?array {
		$row = self::db()->get_row( self::db()->prepare( 'SELECT * FROM ' . Db::t( 'fiscal_year_closures' ) . ' WHERE year = %d ORDER BY version DESC LIMIT 1', $year ), ARRAY_A );
		return $row && 'current' === $row['status'] ? $row : null;
	}

	/**
	 * Tutte le chiusure (e le riaperture, che sono scritte sulla stessa riga) di un anno o di tutti, dalla più recente. La tabella non si
	 * cancella mai: nemmeno con la pulizia del registro azioni.
	 *
	 * @return array[]
	 */
	public static function closures( ?int $year = null ): array {
		$sql = 'SELECT c.*, u.display_name AS closed_by_name, r.display_name AS reopened_by_name FROM ' . Db::t( 'fiscal_year_closures' ) . ' c LEFT JOIN ' . self::db()->users . ' u ON u.ID = c.closed_by LEFT JOIN ' . self::db()->users . ' r ON r.ID = c.reopened_by';
		if ( null !== $year ) {
			$sql .= self::db()->prepare( ' WHERE c.year = %d', $year );
		}
		return self::db()->get_results( $sql . ' ORDER BY c.year DESC, c.version DESC', ARRAY_A ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- nomi di tabella interni, il valore è già passato da prepare()
	}

	/** Scrive la riga di una nuova chiusura (versione successiva dell'anno). Con $user = 0 l'utente non si conosce (anni chiusi prima della traccia). */
	public static function record_closure( int $year, string $at, int $user ): int {
		$v    = 1 + (int) self::db()->get_var( self::db()->prepare( 'SELECT MAX(version) FROM ' . Db::t( 'fiscal_year_closures' ) . ' WHERE year = %d', $year ) );
		$m    = self::start_month();
		$span = self::range_for( $year, $m );
		self::db()->insert(
			Db::t( 'fiscal_year_closures' ),
			array(
				'year'             => $year,
				'version'          => $v,
				'closed_at'        => $at,
				'closed_by'        => $user > 0 ? $user : null,
				'start_month_used' => $m,
				'range_from'       => $span[0],
				'range_to'         => $span[1],
				'plugin_version'   => defined( 'ASEM_VERSION' ) ? ASEM_VERSION : null,
				'status'           => 'current',
				'snapshot_state'   => 'none', // la fotografia si scrive subito dopo, nella stessa operazione ({@see FiscalYears::close()}); resta «none» per gli anni chiusi prima della traccia
			)
		);
		return (int) self::db()->insert_id;
	}

	/** Una chiusura precisa di un anno (anche una versione precedente, poi riaperta), oppure null. */
	public static function closure_version( int $year, int $version ): ?array {
		$row = self::db()->get_row( self::db()->prepare( 'SELECT * FROM ' . Db::t( 'fiscal_year_closures' ) . ' WHERE year = %d AND version = %d', $year, $version ), ARRAY_A );
		return $row ?: null;
	}

	/** Il libro soci di quella chiusura è stato fotografato? Per le chiusure fatte prima della fotografia («none») no, finché non si fotografano. */
	public static function snapshot_text( array $closure ): string {
		if ( 'complete' !== $closure['snapshot_state'] ) {
			return 'non fotografato';
		}
		$d = substr( (string) ( $closure['snapshotted_at'] ?: $closure['closed_at'] ), 0, 10 );
		$d = preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m ) ? $m[3] . '/' . $m[2] . '/' . $m[1] : '';
		return ( ! empty( $closure['retroactive'] ) ? 'ricostruito il ' : 'fotografato il ' ) . $d;
	}

	/** Le chiusure in vigore di esercizi chiusi senza fotografia (chiusi prima che esistesse), dalla più vecchia. @return array[] */
	public static function unphotographed(): array {
		return self::db()->get_results(
			'SELECT c.* FROM ' . Db::t( 'fiscal_year_closures' ) . ' c JOIN ' . Db::t( 'fiscal_years' ) . " y ON y.year = c.year AND y.status = 'closed' WHERE c.status = 'current' AND c.snapshot_state <> 'complete' ORDER BY c.year", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- nomi di tabella interni
			ARRAY_A
		) ?: array();
	}

	/**
	 * Fotografia retroattiva: gli esercizi già chiusi e non fotografati si fotografano ora, con i dati di oggi. Ogni fotografia è segnata
	 * «ricostruita il …» (non è ripresa al giorno della chiusura) e l'evento resta nella traccia delle chiusure. Un'estensione scrive le sue
	 * fotografie con l'azione `asem_year_closing` (terzo parametro true). Tutto o niente per ogni esercizio.
	 *
	 * @return int esercizi fotografati
	 * @throws \InvalidArgumentException
	 */
	public static function snapshot_past_years(): int {
		MemberBook::assign_missing();
		$n = 0;
		foreach ( self::unphotographed() as $c ) {
			$year = (int) $c['year'];
			self::in_transaction(
				function () use ( $c, $year ) {
					$from = $c['range_from'] ? (string) $c['range_from'] : self::range( $year )[0];
					$to   = $c['range_to'] ? (string) $c['range_to'] : self::range( $year )[1];
					MemberBook::write_snapshot( (int) $c['id'], $from, $to );
					do_action( 'asem_year_closing', $year, (int) $c['id'], true );
					self::db()->update(
						Db::t( 'fiscal_year_closures' ),
						array( 'snapshot_state' => 'complete', 'retroactive' => 1, 'snapshotted_at' => Db::now(), 'snapshotted_by' => get_current_user_id() ?: null ),
						array( 'id' => (int) $c['id'] )
					);
					Audit::log( 'year.snapshot_retro', 'year', $year, array( 'version' => (int) $c['version'] ) );
				}
			);
			$n++;
		}
		return $n;
	}

	/** Esegue $fn in una transazione: se lancia qualcosa, non resta scritto niente. */
	public static function in_transaction( callable $fn ): void {
		self::db()->query( 'START TRANSACTION' );
		try {
			$fn();
			self::db()->query( 'COMMIT' );
		} catch ( \Throwable $e ) {
			self::db()->query( 'ROLLBACK' );
			throw $e;
		}
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

	/**
	 * L'anno in corso si apre da solo (il primo giorno dell'anno, o al primo movimento) se non esiste ancora. Un anno già chiuso non si riapre
	 * mai in silenzio: «il chiuso è chiuso», si riapre solo a mano con {@see FiscalYears::reopen()} e il motivo.
	 */
	public static function ensure_current_open(): void {
		$cur = self::current();
		if ( ! self::get( $cur ) ) {
			self::create( $cur );
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
			throw new \InvalidArgumentException( 'L\'esercizio ' . self::label( $year ) . ' non è stato creato: crealo dalla scheda «Esercizi» prima di registrare movimenti in quella data.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		if ( 'open' !== $row['status'] ) {
			throw new \InvalidArgumentException( 'L\'esercizio ' . self::label( $year ) . ' è chiuso: non accetta più incassi né spese. Riaprilo dalla scheda «Esercizi» se devi correggerlo.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
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
			throw new \InvalidArgumentException( 'L\'anno ' . self::label( $year ) . ' è già chiuso.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		$b = self::blockers( $year );
		if ( $b ) {
			throw new \InvalidArgumentException( 'L\'anno ' . self::label( $year ) . ' non si può chiudere: ' . implode( '; ', $b ) . '.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		$prev = self::db()->get_var( self::db()->prepare( 'SELECT MAX(year) FROM ' . Db::t( 'fiscal_years' ) . " WHERE year < %d AND status = 'open'", $year ) );
		if ( null !== $prev ) { // gli esercizi chiusi formano sempre una serie continua: si chiude in ordine
			throw new \InvalidArgumentException( 'Prima di chiudere il ' . self::label( $year ) . ' chiudi il ' . self::label( (int) $prev ) . '.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		$gaps = function_exists( 'apply_filters' ) ? (array) apply_filters( 'asem_year_close_confirm', array(), $year ) : array(); // cose da confermare per chiudere lo stesso
		if ( $gaps && ! $confirmed ) {
			throw new \InvalidArgumentException( 'L\'anno ' . self::label( $year ) . ' si chiude solo con la tua conferma: ' . implode( '; ', $gaps ) . '. Spunta la conferma accanto a «Chiudi l\'anno» se vuoi chiuderlo comunque.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		MemberBook::assign_missing(); // le matricole si danno prima, fuori dalla transazione
		$now = Db::now();
		// Chiusura e fotografie sono un'unica operazione: se una fotografia non riesce (anche quella di un'estensione, con l'azione sotto) la chiusura non avviene.
		self::in_transaction(
			function () use ( $year, $now, $gaps ) {
				self::db()->update( Db::t( 'fiscal_years' ), array( 'status' => 'closed', 'closed_at' => $now ), array( 'year' => $year ) );
				$id   = self::record_closure( $year, $now, (int) get_current_user_id() );
				$span = self::range( $year );
				MemberBook::write_snapshot( $id, $span[0], $span[1] );
				do_action( 'asem_year_closing', $year, $id ); // le estensioni (Pro) scrivono le loro fotografie legate a questa chiusura; se lanciano, la chiusura non avviene
				self::db()->update( Db::t( 'fiscal_year_closures' ), array( 'snapshot_state' => 'complete', 'snapshotted_at' => $now, 'snapshotted_by' => get_current_user_id() ?: null ), array( 'id' => $id ) );
				Audit::log( 'year.closed', 'year', $year, $gaps ? array( 'confirmed_gaps' => $gaps ) : array() );
			}
		);
	}

	/**
	 * Riapre un esercizio chiuso. Si riapre solo l'ultimo esercizio chiuso (il più recente) e serve sempre il motivo, che resta scritto per
	 * sempre insieme a chi e quando. La chiusura riaperta non si cancella: diventa una versione precedente e alla chiusura successiva ne nasce una nuova.
	 *
	 * @throws \InvalidArgumentException
	 */
	public static function reopen( int $year, string $reason ): void {
		$row = self::get( $year );
		if ( ! $row || 'closed' !== $row['status'] ) {
			throw new \InvalidArgumentException( 'L\'anno solare non è chiuso.' );
		}
		$last = self::last_closed();
		if ( $last !== $year ) {
			throw new \InvalidArgumentException( 'Si può riaprire solo l\'ultimo esercizio chiuso (' . self::label( (int) $last ) . '): riaprilo prima di tornare al ' . self::label( $year ) . '.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		$reason = mb_substr( trim( sanitize_textarea_field( $reason ) ), 0, 500 );
		if ( '' === $reason ) {
			throw new \InvalidArgumentException( 'Scrivi il motivo della riapertura: resta registrato per sempre.' );
		}
		if ( null === self::current_closure( $year ) ) {
			self::record_closure( $year, $row['closed_at'] ? (string) $row['closed_at'] : Db::now(), 0 ); // anno chiuso senza traccia (dati di prima della v58)
		}
		$c = self::current_closure( $year );
		self::db()->update( Db::t( 'fiscal_years' ), array( 'status' => 'open', 'closed_at' => null ), array( 'year' => $year ) );
		self::db()->update(
			Db::t( 'fiscal_year_closures' ),
			array( 'status' => 'superseded', 'reopened_at' => Db::now(), 'reopened_by' => get_current_user_id() ?: null, 'reopen_reason' => $reason ),
			array( 'id' => (int) $c['id'] )
		);
		Audit::log( 'year.reopened', 'year', $year, array( 'reason' => $reason, 'version' => (int) $c['version'] ) );
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
