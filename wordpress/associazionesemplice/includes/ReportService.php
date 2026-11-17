<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Due viste sugli stessi movimenti:
 *  - rendiconto per cassa di un periodo (anno solare, per il commercialista);
 *  - valutazione dell'anno sociale (cosa rende ogni attività e cosa resta all'associazione).
 */
class ReportService {

	private function db(): \wpdb {
		return Db::db();
	}

	/** Rendiconto per cassa: saldi per conto, entrate e uscite per voce di rendiconto, avanzo. */
	public function period( string $from, string $to ): array {
		$ledger   = Plugin::ledger();
		$before   = $ledger->balances( ( new \DateTimeImmutable( $from ) )->modify( '-1 day' )->format( 'Y-m-d' ), true );
		$sums     = $this->account_sums( $from, $to );
		$accounts = array();
		foreach ( $before as $a ) {
			$own = $sums[ (int) $a['id'] ] ?? array();
			if ( null !== $a['closed_at'] && 0 === (int) $a['balance'] && ! $own ) {
				continue; // conto chiuso e senza movimenti nel periodo
			}
			$income    = 0;
			$expense   = 0;
			$transfers = 0;
			foreach ( $own as $type => $cents ) {
				if ( 'income' === $type ) {
					$income += $cents;
				} elseif ( 'expense' === $type ) {
					$expense += $cents;
				} else {
					$transfers += Labels::sign( (string) $type ) * $cents;
				}
			}
			$accounts[] = array(
				'account' => $a, 'opening' => $a['balance'], 'income' => $income, 'expense' => $expense, 'transfers' => $transfers,
				'closing' => $a['balance'] + $income - $expense + $transfers,
			);
		}

		// Rimborsi dei fondi: la spesa è già contata quando si accantona (nell'anno dell'incasso), quindi il pagamento del rimborso non si conta una seconda volta
		$payouts = array_keys( Plugin::funds()->payout_transactions() );
		$fiscal = $this->fiscal_groups();
		$decorate = function ( array $list ) use ( $fiscal ) {
			foreach ( $list as &$l ) {
				$l['fiscal_group'] = $fiscal[ $l['name'] ] ?? null;
			}
			return $list;
		};
		$income   = $decorate( $this->category_totals( $from, $to, 'income' ) );
		$expenses = $decorate( $this->category_totals( $from, $to, 'expense', false, $payouts ) );
		// Fondi accantonati: sono uscite dell'anno in cui si accantonano, anche se il rimborso non è ancora stato pagato
		$fund_accrued  = Plugin::funds()->accrued_in( $from, $to );
		$fund_released = Plugin::funds()->released_in( $from, $to );
		if ( 0 !== $fund_accrued - $fund_released ) {
			$expenses[] = array( 'name' => 'Accantonamenti per rimborsi (fondi)', 'cents' => $fund_accrued - $fund_released, 'fiscal_group' => null );
		}
		$ti = array_sum( array_column( $income, 'cents' ) );
		$te = array_sum( array_column( $expenses, 'cents' ) );

		$funds = Plugin::funds()->available( $to );

		return array(
			'from' => $from, 'to' => $to, 'accounts' => $accounts, 'income' => $income, 'expenses' => $expenses,
			'total_income' => $ti, 'total_expense' => $te, 'result' => $ti - $te,
			'opening_total' => array_sum( array_column( $accounts, 'opening' ) ),
			'closing_total' => array_sum( array_column( $accounts, 'closing' ) ),
			'fund_accrued'  => $fund_accrued,
			'fund_released' => $fund_released,
			'funds_total'   => $funds['funds'],
			'available'     => array_sum( array_column( $accounts, 'closing' ) ) - $funds['funds'],
		);
	}

	/** Somme del periodo per conto e tipo di movimento (stessi movimenti dell'elenco di prima nota). @return array<int,array<string,int>> conto => tipo => centesimi */
	private function account_sums( string $from, string $to ): array {
		$sql = 'SELECT t.account_id, t.type, SUM(t.amount_cents) AS s FROM ' . Db::t( 'transactions' ) . ' t '
			. 'JOIN ' . Db::t( 'accounts' ) . ' a ON a.id = t.account_id JOIN ' . Db::t( 'categories' ) . ' c ON c.id = t.category_id '
			. 'WHERE t.voided_at IS NULL AND t.tx_date BETWEEN %s AND %s GROUP BY t.account_id, t.type';
		$out = array();
		foreach ( $this->db()->get_results( $this->db()->prepare( $sql, $from, $to ), ARRAY_A ) ?: array() as $r ) {
			$out[ (int) $r['account_id'] ][ (string) $r['type'] ] = (int) $r['s'];
		}
		return $out;
	}

	/**
	 * Totali del periodo per voce di rendiconto, per un tipo di movimento, nell'ordine in cui le voci compaiono in prima nota.
	 *
	 * @param int[] $exclude_ids movimenti da non contare
	 * @return array[] elenco di array( 'name' => , 'cents' => )
	 */
	private function category_totals( string $from, string $to, string $type, bool $general_only = false, array $exclude_ids = array() ): array {
		$sql = 'SELECT t.category_id, c.name, SUM(t.amount_cents) AS s, MIN(CONCAT(t.tx_date, \' \', LPAD(t.id, 20, \'0\'))) AS first_row FROM ' . Db::t( 'transactions' ) . ' t '
			. 'JOIN ' . Db::t( 'accounts' ) . ' a ON a.id = t.account_id JOIN ' . Db::t( 'categories' ) . ' c ON c.id = t.category_id '
			. 'WHERE t.voided_at IS NULL AND t.tx_date BETWEEN %s AND %s AND t.type = %s';
		if ( $general_only ) {
			$sql .= ' AND (t.activity_id IS NULL OR t.activity_id = 0)';
		}
		if ( $exclude_ids ) {
			$sql .= ' AND t.id NOT IN (' . implode( ',', array_map( 'intval', $exclude_ids ) ) . ')';
		}
		$sql .= ' GROUP BY t.category_id, c.name ORDER BY first_row';
		$out  = array();
		foreach ( $this->db()->get_results( $this->db()->prepare( $sql, $from, $to, $type ), ARRAY_A ) ?: array() as $r ) {
			$out[] = array( 'name' => $r['name'], 'cents' => (int) $r['s'] );
		}
		return $out;
	}

	/** Solo il risultato del periodo (entrate meno uscite, fondi compresi), con la stessa regola del rendiconto ma senza costruirlo. */
	public function period_result( string $from, string $to ): int {
		$payouts = array_keys( Plugin::funds()->payout_transactions() );
		$sql     = 'SELECT COALESCE(SUM(t.amount_cents), 0) FROM ' . Db::t( 'transactions' ) . ' t '
			. 'JOIN ' . Db::t( 'accounts' ) . ' a ON a.id = t.account_id JOIN ' . Db::t( 'categories' ) . ' c ON c.id = t.category_id '
			. 'WHERE t.voided_at IS NULL AND t.tx_date BETWEEN %s AND %s AND t.type = %s';
		$income  = (int) $this->db()->get_var( $this->db()->prepare( $sql, $from, $to, 'income' ) );
		if ( $payouts ) {
			$sql .= ' AND t.id NOT IN (' . implode( ',', array_map( 'intval', $payouts ) ) . ')';
		}
		$expense = (int) $this->db()->get_var( $this->db()->prepare( $sql, $from, $to, 'expense' ) );
		$funds   = Plugin::funds();
		return $income - ( $expense + $funds->accrued_in( $from, $to ) - $funds->released_in( $from, $to ) );
	}

	private function fiscal_groups(): array {
		$out = array();
		foreach ( Plugin::ledger()->categories() as $c ) {
			$out[ $c['name'] ] = $c['fiscal_group'];
		}
		return $out;
	}

	/** Valutazione dell'anno sociale: per attività incassi - costi, più entrate/uscite generali e numero soci. */
	public function social_year( SocialYear $year ): array {
		$activities = Plugin::activities()->for_year( $year->label() );
		$ids        = array_map( 'intval', array_column( $activities, 'id' ) );
		$by_activity = array();
		if ( $ids ) {
			$sql  = "SELECT activity_id, type, SUM(amount_cents) AS s FROM " . Db::t( 'transactions' )
				. ' WHERE voided_at IS NULL AND activity_id IN (' . implode( ',', $ids ) . ') GROUP BY activity_id, type';
			foreach ( $this->db()->get_results( $sql, ARRAY_A ) ?: array() as $r ) {
				$by_activity[ (int) $r['activity_id'] ][ $r['type'] ] = (int) $r['s'];
			}
		}
		$summaries = array();
		foreach ( $activities as $a ) {
			$income      = $by_activity[ (int) $a['id'] ]['income'] ?? 0;
			$cost        = $by_activity[ (int) $a['id'] ]['expense'] ?? 0;
			$summaries[] = array(
				'activity' => $a, 'participants' => Plugin::activities()->active_participants( (int) $a['id'] ),
				'income' => $income, 'cost' => $cost, 'margin' => $income - $cost,
			);
		}

		// Movimenti generali: nel periodo dell'anno sociale, non legati ad attività e non giroconti
		$from = $year->start()->format( 'Y-m-d' );
		$to   = $year->end()->format( 'Y-m-d' );
		$gi   = $this->category_totals( $from, $to, 'income', true );
		$ge   = $this->category_totals( $from, $to, 'expense', true );
		$ti = array_sum( array_column( $summaries, 'income' ) ) + array_sum( array_column( $gi, 'cents' ) );
		$te = array_sum( array_column( $summaries, 'cost' ) ) + array_sum( array_column( $ge, 'cents' ) );

		return array(
			'year' => $year, 'activities' => $summaries, 'members_by_type' => Plugin::people()->count_by_type_for_year( $year ),
			'general_income' => $gi, 'general_expenses' => $ge, 'total_income' => $ti, 'total_expense' => $te, 'result' => $ti - $te,
		);
	}
}
