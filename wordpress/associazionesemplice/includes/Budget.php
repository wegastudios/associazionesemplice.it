<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Bilancio preventivo (funzione di Associazione Semplice Pro): per ogni anno le voci di entrata e di uscita per categoria, messe a confronto
 * con il consuntivo che risulta dalla prima nota (movimenti non annullati; i giroconti non contano). Le regole pure stanno in {@see BudgetRules}.
 */
final class Budget {

	private static function db(): \wpdb {
		return Db::db();
	}

	// ---------- Registro ----------

	/** @throws \InvalidArgumentException */
	public static function save( int $id, array $in, ?int $user_id = null ): int {
		$year = (int) ( $in['year'] ?? 0 );
		if ( ! BudgetRules::valid_year( $year ) ) {
			throw new \InvalidArgumentException( 'Indica l\'anno del preventivo.' );
		}
		return (int) Db::with_lock( 'asem_budget_year_' . $year, function () use ( $id, $in, $user_id, $year ) { // due richieste insieme non creano due preventivi per lo stesso anno
			return self::save_locked( $id, $in, $user_id, $year );
		} );
	}

	private static function save_locked( int $id, array $in, ?int $user_id, int $year ): int {
		$old = $id ? self::get( $id ) : null;
		if ( $id && ! $old ) {
			throw new \InvalidArgumentException( 'Preventivo non trovato.' );
		}
		$other = self::by_year( $year );
		if ( $other && (int) $other['id'] !== $id ) {
			throw new \InvalidArgumentException( 'Esiste già un preventivo per il ' . $year . '.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
		}
		$d = array(
			'year'  => $year,
			'title' => mb_substr( trim( sanitize_text_field( (string) ( $in['title'] ?? '' ) ) ), 0, 190 ),
			'notes' => mb_substr( trim( sanitize_textarea_field( (string) ( $in['notes'] ?? '' ) ) ), 0, 5000 ),
		);
		if ( '' === $d['title'] ) {
			$d['title'] = 'Bilancio preventivo ' . $year;
		}
		$db = self::db();
		if ( $old ) {
			if ( BudgetRules::APPROVED === $old['status'] && (int) $old['year'] !== $year ) {
				throw new \InvalidArgumentException( 'Un preventivo approvato non cambia anno: riaprilo prima.' );
			}
			$db->update( Db::t( 'budgets' ), $d + array( 'updated_at' => Db::now() ), array( 'id' => $id ) );
		} else {
			if ( ! $db->insert( Db::t( 'budgets' ), $d + array( 'status' => BudgetRules::DRAFT, 'created_by' => $user_id, 'created_at' => Db::now(), 'updated_at' => Db::now() ) ) ) {
				throw new \InvalidArgumentException( 'Impossibile salvare (errore del database).' );
			}
			$id = (int) $db->insert_id;
		}
		Audit::log( $old ? 'budget.updated' : 'budget.created', 'budget', $id, array( 'year' => $year ) );
		return $id;
	}

	public static function get( int $id ): ?array {
		$r = self::db()->get_row( self::db()->prepare( 'SELECT * FROM ' . Db::t( 'budgets' ) . ' WHERE id = %d AND deleted_at IS NULL', $id ), ARRAY_A );
		return $r ?: null;
	}

	public static function by_year( int $year ): ?array {
		$r = self::db()->get_row( self::db()->prepare( 'SELECT * FROM ' . Db::t( 'budgets' ) . ' WHERE year = %d AND deleted_at IS NULL ORDER BY id LIMIT 1', $year ), ARRAY_A );
		return $r ?: null;
	}

	/** @return array[] dal più recente */
	public static function all(): array {
		return self::db()->get_results( 'SELECT * FROM ' . Db::t( 'budgets' ) . ' WHERE deleted_at IS NULL ORDER BY year DESC', ARRAY_A ) ?: array();
	}

	public static function delete( int $id ): void {
		$b = self::get( $id );
		if ( ! $b ) {
			throw new \InvalidArgumentException( 'Preventivo non trovato.' );
		}
		if ( BudgetRules::APPROVED === $b['status'] ) {
			throw new \InvalidArgumentException( 'Un preventivo approvato non si elimina: riaprilo prima.' );
		}
		self::db()->update( Db::t( 'budgets' ), array( 'deleted_at' => Db::now() ), array( 'id' => $id ) );
		Audit::log( 'budget.deleted', 'budget', $id );
	}

	// ---------- Voci ----------

	/** Categorie utilizzabili in un preventivo: id => [nome, entrata|uscita]. */
	public static function categories(): array {
		$out = array();
		foreach ( Plugin::ledger()->categories() as $c ) {
			$type = BudgetRules::type_of_kind( (string) $c['kind'] );
			if ( $type ) {
				$out[ (int) $c['id'] ] = array( (string) $c['name'], $type );
			}
		}
		return $out;
	}

	/** Totali previsti di tutti i preventivi in una sola interrogazione. @return array budget_id => [income => cents, expense => cents] */
	public static function planned_totals(): array {
		$out = array();
		foreach ( self::db()->get_results( 'SELECT l.budget_id, l.type, SUM(l.planned_cents) AS cents FROM ' . Db::t( 'budget_lines' ) . ' l JOIN ' . Db::t( 'budgets' ) . ' b ON b.id = l.budget_id WHERE b.deleted_at IS NULL GROUP BY l.budget_id, l.type', ARRAY_A ) ?: array() as $r ) {
			$out[ (int) $r['budget_id'] ][ (string) $r['type'] ] = (int) $r['cents'];
		}
		foreach ( $out as &$t ) {
			$t += array( BudgetRules::INCOME => 0, BudgetRules::EXPENSE => 0 );
		}
		unset( $t );
		return $out;
	}

	/** @return array[] category_id, type, planned_cents, notes */
	public static function lines( int $id ): array {
		return self::db()->get_results( self::db()->prepare( 'SELECT category_id, type, planned_cents, notes FROM ' . Db::t( 'budget_lines' ) . ' WHERE budget_id = %d', $id ), ARRAY_A ) ?: array();
	}

	/**
	 * Sostituisce le voci. Gli importi sono quelli scritti nel modulo («1.200,50»); le voci vuote o a zero non si salvano.
	 *
	 * @param array $amounts category_id => importo scritto
	 * @param array $notes   category_id => nota
	 * @throws \InvalidArgumentException
	 */
	public static function set_lines( int $id, array $amounts, array $notes = array() ): int {
		$b = self::get( $id );
		if ( ! $b ) {
			throw new \InvalidArgumentException( 'Preventivo non trovato.' );
		}
		if ( BudgetRules::APPROVED === $b['status'] ) {
			throw new \InvalidArgumentException( 'Il preventivo è approvato: riaprilo per modificare le voci.' );
		}
		$cats = self::categories();
		$rows = array();
		foreach ( $amounts as $cid => $raw ) {
			$cid = (int) $cid;
			$raw = trim( (string) $raw );
			if ( '' === $raw || ! isset( $cats[ $cid ] ) ) {
				continue;
			}
			$cents = Money::parse( $raw );
			if ( null === $cents || $cents < 0 ) {
				throw new \InvalidArgumentException( 'Importo non valido per «' . $cats[ $cid ][0] . '».' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messaggio interno, mostrato solo dopo esc_html
			}
			if ( $cents > 0 ) {
				$rows[ $cid ] = $cents;
			}
		}
		$db = self::db();
		$db->delete( Db::t( 'budget_lines' ), array( 'budget_id' => $id ) );
		foreach ( $rows as $cid => $cents ) {
			$db->insert( Db::t( 'budget_lines' ), array( 'budget_id' => $id, 'category_id' => $cid, 'type' => $cats[ $cid ][1], 'planned_cents' => $cents, 'notes' => mb_substr( trim( sanitize_text_field( (string) ( $notes[ $cid ] ?? '' ) ) ), 0, 255 ) ) );
		}
		$db->update( Db::t( 'budgets' ), array( 'updated_at' => Db::now() ), array( 'id' => $id ) );
		Audit::log( 'budget.lines', 'budget', $id, array( 'lines' => count( $rows ) ) );
		return count( $rows );
	}

	// ---------- Consuntivo ----------

	/** Entrate e uscite dell'anno dalla prima nota, per categoria. @return array category_id => cents */
	public static function actuals( int $year ): array {
		list( $from, $to ) = BudgetRules::period( $year );
		$db  = self::db();
		$out = array();
		foreach ( $db->get_results( $db->prepare( 'SELECT category_id, SUM(amount_cents) AS cents FROM ' . Db::t( 'transactions' ) . " WHERE voided_at IS NULL AND type IN ('income','expense') AND tx_date BETWEEN %s AND %s GROUP BY category_id", $from, $to ), ARRAY_A ) ?: array() as $r ) {
			$out[ (int) $r['category_id'] ] = (int) $r['cents'];
		}
		return $out;
	}

	/**
	 * Il preventivo messo a confronto con il consuntivo, voce per voce (con nome della categoria), e i totali.
	 *
	 * @return array{rows:array[],totals:array}
	 */
	public static function compare( int $id ): array {
		$b = self::get( $id );
		if ( ! $b ) {
			throw new \InvalidArgumentException( 'Preventivo non trovato.' );
		}
		$cats  = self::categories();
		$types = array();
		foreach ( $cats as $cid => $c ) {
			$types[ $cid ] = $c[1];
		}
		$rows = BudgetRules::merge( self::lines( $id ), self::actuals( (int) $b['year'] ), $types );
		foreach ( $rows as &$r ) {
			$r['name'] = $cats[ $r['category_id'] ][0] ?? 'Categoria eliminata';
		}
		unset( $r );
		usort(
			$rows,
			function ( $a, $b ) {
				return array( 'income' === $a['type'] ? 0 : 1, $a['name'] ) <=> array( 'income' === $b['type'] ? 0 : 1, $b['name'] );
			}
		);
		return array( 'rows' => $rows, 'totals' => BudgetRules::totals( $rows ) );
	}

	// ---------- Copia, approvazione ----------

	/**
	 * Riempie le voci di un preventivo in preparazione partendo dall'anno prima: dal suo preventivo oppure dal suo consuntivo, con una variazione in percentuale.
	 * Sostituisce le voci presenti.
	 *
	 * @param string $source 'budget' | 'actual'
	 * @throws \InvalidArgumentException
	 */
	public static function copy_from_previous( int $id, string $source, int $percent ): int {
		$b = self::get( $id );
		if ( ! $b || BudgetRules::DRAFT !== $b['status'] ) {
			throw new \InvalidArgumentException( 'Si copia solo in un preventivo in preparazione.' );
		}
		$prev = (int) $b['year'] - 1;
		$amounts = array();
		if ( 'budget' === $source ) {
			$pb = self::by_year( $prev );
			if ( ! $pb ) {
				throw new \InvalidArgumentException( 'Non c\'è un preventivo per il ' . $prev . '.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}
			foreach ( self::lines( (int) $pb['id'] ) as $l ) {
				$amounts[ (int) $l['category_id'] ] = (int) $l['planned_cents'];
			}
		} else {
			$amounts = self::actuals( $prev );
		}
		$cats = self::categories();
		$in   = array();
		foreach ( $amounts as $cid => $cents ) {
			if ( isset( $cats[ $cid ] ) ) {
				$in[ $cid ] = Money::plain( BudgetRules::scale( (int) $cents, $percent ) );
			}
		}
		if ( ! $in ) {
			throw new \InvalidArgumentException( 'L\'anno ' . $prev . ' non ha voci da copiare.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		return self::set_lines( $id, $in );
	}

	/** Segna il preventivo come approvato (dall'assemblea indicata, se c'è). @throws \InvalidArgumentException */
	public static function approve( int $id, string $date, int $assembly_id = 0 ): void {
		$b = self::get( $id );
		if ( ! $b || BudgetRules::DRAFT !== $b['status'] ) {
			throw new \InvalidArgumentException( 'Il preventivo è già approvato.' );
		}
		$dt = \DateTimeImmutable::createFromFormat( '!Y-m-d', $date );
		if ( ! $dt || $dt->format( 'Y-m-d' ) !== $date ) {
			throw new \InvalidArgumentException( 'Indica la data di approvazione.' );
		}
		if ( $assembly_id && ! Assemblies::get( $assembly_id ) ) {
			throw new \InvalidArgumentException( 'Assemblea non trovata.' );
		}
		if ( ! self::lines( $id ) ) {
			throw new \InvalidArgumentException( 'Il preventivo non ha voci.' );
		}
		self::db()->update( Db::t( 'budgets' ), array( 'status' => BudgetRules::APPROVED, 'approved_on' => $date, 'assembly_id' => $assembly_id ?: null, 'updated_at' => Db::now() ), array( 'id' => $id ) );
		Audit::log( 'budget.approved', 'budget', $id, array( 'assembly' => $assembly_id ) );
	}

	public static function reopen( int $id ): void {
		$b = self::get( $id );
		if ( ! $b || BudgetRules::APPROVED !== $b['status'] ) {
			throw new \InvalidArgumentException( 'Il preventivo non è approvato.' );
		}
		self::db()->update( Db::t( 'budgets' ), array( 'status' => BudgetRules::DRAFT, 'approved_on' => null, 'assembly_id' => null, 'updated_at' => Db::now() ), array( 'id' => $id ) );
		Audit::log( 'budget.reopened', 'budget', $id );
	}

	// ---------- Esportazione ----------

	/** Il confronto in CSV. */
	public static function csv( int $id ): string {
		$b = self::get( $id );
		$r = self::compare( $id );
		$labels = BudgetRules::status_labels();
		$rows   = array();
		foreach ( $r['rows'] as $x ) {
			$rows[] = array( $x['name'], 'income' === $x['type'] ? 'Entrata' : 'Uscita', Money::plain( $x['planned'] ), Money::plain( $x['actual'] ), Money::plain( $x['diff'] ), null === $x['percent'] ? '' : $x['percent'] . '%', $labels[ $x['status'] ] );
		}
		$t      = $r['totals'];
		$rows[] = array( 'Totale entrate', '', Money::plain( $t['income_planned'] ), Money::plain( $t['income_actual'] ), '', '', '' );
		$rows[] = array( 'Totale uscite', '', Money::plain( $t['expense_planned'] ), Money::plain( $t['expense_actual'] ), '', '', '' );
		$rows[] = array( 'Risultato', '', Money::plain( $t['result_planned'] ), Money::plain( $t['result_actual'] ), '', '', '' );
		return Docs::csv(
			array( array( 'label' => 'Voce' ), array( 'label' => 'Tipo' ), array( 'label' => 'Preventivo ' . $b['year'] ), array( 'label' => 'Consuntivo' ), array( 'label' => 'Differenza a favore' ), array( 'label' => 'Consuntivo sul preventivo' ), array( 'label' => 'Stato' ) ),
			$rows
		);
	}

	/** Quante spese hanno superato il preventivo nell'anno in corso (per la Bacheca). Zero se non c'è un preventivo approvato. */
	public static function over_count(): int {
		$b = self::by_year( (int) substr( Db::today(), 0, 4 ) );
		if ( ! $b || BudgetRules::APPROVED !== $b['status'] ) {
			return 0;
		}
		$n = 0;
		foreach ( self::compare( (int) $b['id'] )['rows'] as $r ) {
			if ( in_array( $r['status'], array( BudgetRules::OVER, BudgetRules::UNPLANNED ), true ) && BudgetRules::EXPENSE === $r['type'] ) {
				$n++;
			}
		}
		return $n;
	}
}
