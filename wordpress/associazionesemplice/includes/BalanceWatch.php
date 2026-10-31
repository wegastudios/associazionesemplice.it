<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * Controllo di bilancio (funzione di Associazione Semplice Pro Fiscale): bilancio provvisorio dell'anno fiscale in corso, aggiornato al mese
 * concluso, con l'andamento del mese in corso; avvisi sulla tendenza di fine anno (spese non coperte, utile eccessivo); istantanea mensile del
 * consuntivo contro il preventivo, conservata mese per mese. Il consuntivo è quello della prima nota (movimenti non annullati, senza giroconti),
 * con i fondi accantonati al posto dei rimborsi pagati. Le regole pure stanno in {@see BalanceWatchRules}.
 */
final class BalanceWatch {

	private static function db(): \wpdb {
		return Db::db();
	}

	public static function enabled(): bool {
		return Edition::has( 'watch' );
	}

	// ---------- Consuntivo ----------

	/**
	 * Entrate e uscite della prima nota nel periodo, giroconti e movimenti annullati esclusi. Le uscite comprendono gli accantonamenti dei fondi
	 * (al netto di quelli liberati) e non il pagamento del rimborso, già contato quando si accantona.
	 *
	 * @return array{income:int,expense:int,result:int}
	 */
	public static function totals( string $from, string $to ): array {
		$db  = self::db();
		$t   = Db::t( 'transactions' );
		$e   = Db::t( 'fund_entries' );
		$sum = array( 'income' => 0, 'expense' => 0 );
		foreach ( $db->get_results( $db->prepare( "SELECT type, COALESCE(SUM(amount_cents),0) AS cents FROM $t WHERE voided_at IS NULL AND type IN ('income','expense') AND tx_date BETWEEN %s AND %s AND id NOT IN (SELECT tx_id FROM $e WHERE kind = 'payout' AND tx_id IS NOT NULL) GROUP BY type", $from, $to ), ARRAY_A ) ?: array() as $r ) {
			$sum[ (string) $r['type'] ] = (int) $r['cents'];
		}
		$funds = Plugin::funds();
		$sum['expense'] += $funds->accrued_in( $from, $to ) - $funds->released_in( $from, $to );
		return array( 'income' => $sum['income'], 'expense' => $sum['expense'], 'result' => $sum['income'] - $sum['expense'] );
	}

	/** Entrate e uscite per categoria nel periodo, senza i fondi. @return array category_id => cents */
	public static function actuals( string $from, string $to ): array {
		$db  = self::db();
		$t   = Db::t( 'transactions' );
		$e   = Db::t( 'fund_entries' );
		$out = array();
		foreach ( $db->get_results( $db->prepare( "SELECT category_id, SUM(amount_cents) AS cents FROM $t WHERE voided_at IS NULL AND type IN ('income','expense') AND tx_date BETWEEN %s AND %s AND id NOT IN (SELECT tx_id FROM $e WHERE kind = 'payout' AND tx_id IS NOT NULL) GROUP BY category_id", $from, $to ), ARRAY_A ) ?: array() as $r ) {
			$out[ (int) $r['category_id'] ] = (int) $r['cents'];
		}
		return $out;
	}

	// ---------- Situazione ----------

	/**
	 * La situazione dell'anno fiscale in corso (ricalcolata a ogni chiamata).
	 *
	 * @return array anno, etichetta, da, a, oggi, giorni trascorsi e totali, mesi conclusi, bilancio provvisorio, mese in corso, preventivo, tendenza, avvisi
	 */
	public static function status( ?string $today = null ): array {
		$today = $today ?: Db::today();
		$start = FiscalYears::start_month();
		$year  = FiscalYears::year_for( $today, $start );
		list( $from, $to ) = FiscalYears::range_for( $year, $start );
		$done    = BalanceWatchRules::month_offset( $today, $start ); // mesi interi già conclusi
		$elapsed = BalanceWatchRules::days_between( $from, $today );
		$total   = BalanceWatchRules::days_between( $from, $to );
		$m_from  = substr( $today, 0, 7 ) . '-01';
		$out     = array(
			'year' => $year, 'label' => 1 === $start ? (string) $year : $year . '/' . ( $year + 1 ), 'from' => $from, 'to' => $to, 'today' => $today,
			'elapsed_days' => $elapsed, 'total_days' => $total, 'completed_months' => $done,
			'provisional' => null, 'prov_to' => null, 'month' => self::totals( $m_from, $today ) + array( 'from' => $m_from, 'to' => $today ),
			'budget' => null, 'to_date' => self::totals( $from, $today ), 'projection' => null, 'alerts' => array(),
		);
		if ( $done > 0 ) {
			$prov_to = ( new \DateTimeImmutable( $m_from ) )->modify( '-1 day' )->format( 'Y-m-d' );
			$out['prov_to']      = $prov_to;
			$out['provisional']  = self::totals( $from, $prov_to );
			$out['provisional_rows'] = self::compare_rows( $year, $from, $prov_to, $done );
		}
		$budget = self::budget_for( $year );
		if ( $budget ) {
			$out['budget'] = $budget + array( 'share_income' => BalanceWatchRules::planned_share( $budget['income'], $done ), 'share_expense' => BalanceWatchRules::planned_share( $budget['expense'], $done ) );
		}
		$out['projection'] = BalanceWatchRules::projection( $out['to_date']['income'], $out['to_date']['expense'], $elapsed, $total );
		$out['enough']     = BalanceWatchRules::enough_data( $elapsed, Limits::get( 'watch_min_months' ) );
		$out['alerts']     = self::alerts_for( $out );
		return $out;
	}

	/** Gli avvisi di una situazione (vuoti se spenti nei limiti o se i dati non bastano ancora). */
	public static function alerts_for( array $status ): array {
		if ( ! Limits::get( 'watch_alerts' ) || empty( $status['enough'] ) || empty( $status['projection'] ) ) {
			return array();
		}
		return BalanceWatchRules::alerts( (int) $status['projection']['income'], (int) $status['projection']['expense'], Limits::get( 'watch_over_pct' ), Limits::get( 'watch_surplus_pct' ) );
	}

	/** Totali del preventivo dell'anno (null se manca). @return array{id:int,title:string,status:string,income:int,expense:int}|null */
	private static function budget_for( int $year ): ?array {
		if ( ! Edition::has( 'budget' ) ) {
			return null;
		}
		$b = Budget::by_year( $year );
		if ( ! $b ) {
			return null;
		}
		$t = Budget::planned_totals()[ (int) $b['id'] ] ?? array();
		return array( 'id' => (int) $b['id'], 'title' => (string) $b['title'], 'status' => (string) $b['status'], 'income' => (int) ( $t[ BudgetRules::INCOME ] ?? 0 ), 'expense' => (int) ( $t[ BudgetRules::EXPENSE ] ?? 0 ) );
	}

	/** Voce per voce: consuntivo fino alla data contro il preventivo annuale. @return array[] come {@see BudgetRules::merge()} con il nome */
	private static function compare_rows( int $year, string $from, string $to, int $done ): array {
		$b = Edition::has( 'budget' ) ? Budget::by_year( $year ) : null;
		if ( ! $b ) {
			return array();
		}
		$cats  = Budget::categories();
		$types = array();
		foreach ( $cats as $cid => $c ) {
			$types[ $cid ] = $c[1];
		}
		$lines = array();
		foreach ( Budget::lines( (int) $b['id'] ) as $l ) {
			$l['planned_cents'] = BalanceWatchRules::planned_share( (int) $l['planned_cents'], $done ); // quota del preventivo per i mesi conclusi
			$lines[]            = $l;
		}
		$rows = BudgetRules::merge( $lines, self::actuals( $from, $to ), $types );
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
		return $rows;
	}

	// ---------- Istantanee mensili ----------

	/** Istantanee salvate di un anno fiscale (o di tutti), dalla più recente. @return array[] */
	public static function snapshots( int $year = 0 ): array {
		$db    = self::db();
		$table = Db::t( 'balance_snaps' );
		return $year
			? ( $db->get_results( $db->prepare( "SELECT * FROM $table WHERE fiscal_year = %d ORDER BY month DESC", $year ), ARRAY_A ) ?: array() )
			: ( $db->get_results( "SELECT * FROM $table ORDER BY month DESC", ARRAY_A ) ?: array() );
	}

	/** Anni fiscali che hanno almeno un'istantanea. @return int[] */
	public static function snapshot_years(): array {
		return array_map( 'intval', self::db()->get_col( 'SELECT DISTINCT fiscal_year FROM ' . Db::t( 'balance_snaps' ) . ' ORDER BY fiscal_year DESC' ) ?: array() );
	}

	/**
	 * Salva le istantanee dei mesi conclusi che mancano nell'anno fiscale in corso (e, nel primo mese del nuovo anno, l'ultimo mese del precedente).
	 * Un'istantanea non si ricalcola: resta com'era quando è stata fatta. @return int quante ne ha salvate
	 */
	public static function take_missing( ?string $today = null ): int {
		$today = $today ?: Db::today();
		$start = FiscalYears::start_month();
		$year  = FiscalYears::year_for( $today, $start );
		$cur_m = substr( $today, 0, 7 );
		$n     = 0;
		$todo  = array();
		foreach ( BalanceWatchRules::months( $year, $start ) as $m ) {
			if ( $m < $cur_m ) {
				$todo[] = array( $year, $m );
			}
		}
		if ( 0 === BalanceWatchRules::month_offset( $today, $start ) ) { // primo mese del nuovo anno: manca l'ultimo mese del vecchio
			$prev = BalanceWatchRules::months( $year - 1, $start );
			$todo[] = array( $year - 1, $prev[11] );
		}
		foreach ( $todo as $row ) {
			if ( self::snapshot_month( (int) $row[0], (string) $row[1], $today ) ) {
				$n++;
			}
		}
		return $n;
	}

	private static function snapshot_month( int $year, string $month, string $today ): bool {
		$db    = self::db();
		$table = Db::t( 'balance_snaps' );
		if ( $db->get_var( $db->prepare( "SELECT id FROM $table WHERE fiscal_year = %d AND month = %s", $year, $month ) ) ) {
			return false;
		}
		$start = FiscalYears::start_month();
		list( $from ) = FiscalYears::range_for( $year, $start );
		list( $m_from, $m_to ) = BalanceWatchRules::month_range( $month );
		$cell   = self::totals( $m_from, $m_to );
		$ytd    = self::totals( $from, $m_to );
		$budget = self::budget_for( $year );
		$late   = BalanceWatchRules::days_between( $m_to, $today ) > 8; // oltre la prima settimana dopo la fine del mese la foto è una ricostruzione
		$ok     = $db->insert(
			$table,
			array(
				'fiscal_year' => $year, 'month' => $month, 'month_income_cents' => $cell['income'], 'month_expense_cents' => $cell['expense'],
				'ytd_income_cents' => $ytd['income'], 'ytd_expense_cents' => $ytd['expense'],
				'planned_income_cents' => $budget ? $budget['income'] : null, 'planned_expense_cents' => $budget ? $budget['expense'] : null,
				'source' => $late ? 'rebuilt' : 'monthly', 'created_at' => Db::now(),
			)
		);
		if ( $ok ) {
			Audit::log( 'balance.snapshot', 'balance', $year, array( 'month' => $month ) );
		}
		return (bool) $ok;
	}

	/** Lavoro periodico: salva le istantanee dei mesi conclusi. */
	public static function snapshot_job(): void {
		if ( ! self::enabled() ) {
			return;
		}
		YearEndCheck::mail_job(); // promemoria di fine anno (facoltativo)
		if ( ! Limits::get( 'watch_snapshots' ) ) {
			return;
		}
		self::take_missing();
	}

	/** Lo storico in CSV (un anno fiscale, o tutti). */
	public static function snapshots_csv( int $year = 0 ): string {
		$rows = array();
		foreach ( array_reverse( self::snapshots( $year ) ) as $s ) {
			$pi     = null === $s['planned_income_cents'] ? null : (int) $s['planned_income_cents'];
			$pe     = null === $s['planned_expense_cents'] ? null : (int) $s['planned_expense_cents'];
			$rows[] = array(
				(int) $s['fiscal_year'], (string) $s['month'], Money::plain( (int) $s['month_income_cents'] ), Money::plain( (int) $s['month_expense_cents'] ),
				Money::plain( (int) $s['ytd_income_cents'] ), Money::plain( (int) $s['ytd_expense_cents'] ), Money::plain( (int) $s['ytd_income_cents'] - (int) $s['ytd_expense_cents'] ),
				null === $pi ? '' : Money::plain( $pi ), null === $pe ? '' : Money::plain( $pe ), 'monthly' === $s['source'] ? 'fine mese' : 'ricostruita', (string) $s['created_at'],
			);
		}
		return Docs::csv(
			array(
				array( 'label' => 'Anno fiscale' ), array( 'label' => 'Mese' ), array( 'label' => 'Entrate del mese' ), array( 'label' => 'Uscite del mese' ),
				array( 'label' => 'Entrate dall\'inizio dell\'anno' ), array( 'label' => 'Uscite dall\'inizio dell\'anno' ), array( 'label' => 'Risultato dall\'inizio dell\'anno' ),
				array( 'label' => 'Entrate previste (anno)' ), array( 'label' => 'Uscite previste (anno)' ), array( 'label' => 'Origine' ), array( 'label' => 'Salvata il' ),
			),
			$rows
		);
	}
}
