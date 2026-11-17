<?php
/**
 * Prove dei totali del rendiconto: il calcolo con le somme fatte dal database deve dare esattamente gli stessi numeri di quello di prima, che caricava
 * tutte le righe della prima nota in PHP. La vecchia versione resta qui sotto come riferimento. Si carica da smoke.php.
 */

use AssociazioneSemplice\Db;
use AssociazioneSemplice\Labels;
use AssociazioneSemplice\Plugin;
use AssociazioneSemplice\ReportService;
use AssociazioneSemplice\Settings;

global $wpdb;

/** Il rendiconto di un periodo come si calcolava prima: tutte le righe caricate e sommate in PHP. */
function asem_ref_period( string $from, string $to ): array {
	$ledger   = Plugin::ledger();
	$before   = $ledger->balances( ( new \DateTimeImmutable( $from ) )->modify( '-1 day' )->format( 'Y-m-d' ), true );
	$rows     = $ledger->rows( $from, $to, null, true );
	$accounts = array();
	foreach ( $before as $a ) {
		if ( null !== $a['closed_at'] && 0 === (int) $a['balance'] && ! array_filter( $rows, function ( $r ) use ( $a ) {
			return (int) $r['account_id'] === (int) $a['id'];
		} ) ) {
			continue;
		}
		$own       = array_filter( $rows, function ( $r ) use ( $a ) {
			return (int) $r['account_id'] === (int) $a['id'];
		} );
		$income    = 0;
		$expense   = 0;
		$transfers = 0;
		foreach ( $own as $r ) {
			if ( 'income' === $r['type'] ) {
				$income += (int) $r['amount_cents'];
			} elseif ( 'expense' === $r['type'] ) {
				$expense += (int) $r['amount_cents'];
			} else {
				$transfers += Labels::sign( $r['type'] ) * (int) $r['amount_cents'];
			}
		}
		$accounts[] = array(
			'account' => $a, 'opening' => $a['balance'], 'income' => $income, 'expense' => $expense, 'transfers' => $transfers,
			'closing' => $a['balance'] + $income - $expense + $transfers,
		);
	}
	$payouts = Plugin::funds()->payout_transactions();
	$group   = function ( string $type ) use ( $rows, $payouts ) {
		$by = array();
		foreach ( $rows as $r ) {
			if ( $r['type'] !== $type || ( 'expense' === $type && isset( $payouts[ (int) $r['id'] ] ) ) ) {
				continue;
			}
			$key = $r['category_id'];
			if ( ! isset( $by[ $key ] ) ) {
				$by[ $key ] = array( 'name' => $r['category_name'], 'cents' => 0 );
			}
			$by[ $key ]['cents'] += (int) $r['amount_cents'];
		}
		return array_values( $by );
	};
	$fiscal = array();
	foreach ( $ledger->categories() as $c ) {
		$fiscal[ $c['name'] ] = $c['fiscal_group'];
	}
	$decorate = function ( array $list ) use ( $fiscal ) {
		foreach ( $list as &$l ) {
			$l['fiscal_group'] = $fiscal[ $l['name'] ] ?? null;
		}
		return $list;
	};
	$income   = $decorate( $group( 'income' ) );
	$expenses = $decorate( $group( 'expense' ) );
	$fund_accrued  = Plugin::funds()->accrued_in( $from, $to );
	$fund_released = Plugin::funds()->released_in( $from, $to );
	if ( 0 !== $fund_accrued - $fund_released ) {
		$expenses[] = array( 'name' => 'Accantonamenti per rimborsi (fondi)', 'cents' => $fund_accrued - $fund_released, 'fiscal_group' => null );
	}
	$ti    = array_sum( array_column( $income, 'cents' ) );
	$te    = array_sum( array_column( $expenses, 'cents' ) );
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

/** Entrate e uscite generali dell'anno sociale come si calcolavano prima. @return array{0:array,1:array} */
function asem_ref_general( \AssociazioneSemplice\SocialYear $year ): array {
	$general = array_filter(
		Plugin::ledger()->rows( $year->start()->format( 'Y-m-d' ), $year->end()->format( 'Y-m-d' ), null, true ),
		function ( $r ) {
			return empty( $r['activity_id'] ) && ! Labels::is_transfer( $r['type'] );
		}
	);
	$group = function ( string $type ) use ( $general ) {
		$by = array();
		foreach ( $general as $r ) {
			if ( $r['type'] === $type ) {
				$by[ $r['category_id'] ]['name']  = $r['category_name'];
				$by[ $r['category_id'] ]['cents'] = ( $by[ $r['category_id'] ]['cents'] ?? 0 ) + (int) $r['amount_cents'];
			}
		}
		return array_values( $by );
	};
	return array( $group( 'income' ), $group( 'expense' ) );
}

$rt_ledger = Plugin::ledger();
$rt_tx     = array();
$rt_ins    = function ( string $date, string $type, int $cents, int $account, int $cat, ?int $activity = null, bool $void = false ) use ( $wpdb, &$rt_tx ) {
	$wpdb->insert(
		Db::t( 'transactions' ),
		array(
			'tx_date' => $date, 'type' => $type, 'amount_cents' => $cents, 'account_id' => $account, 'method' => 'cash', 'category_id' => $cat,
			'activity_id' => $activity, 'description' => 'prova totali', 'voided_at' => $void ? Db::now() : null, 'created_at' => Db::now(),
		)
	);
	$rt_tx[] = (int) $wpdb->insert_id;
	return (int) $wpdb->insert_id;
};
$rt_cat = function ( string $name, string $group = '' ) use ( $wpdb ) {
	$wpdb->insert( Db::t( 'categories' ), array( 'name' => $name, 'kind' => 'prova_totali', 'fiscal_group' => '' === $group ? null : $group ) );
	return (int) $wpdb->insert_id;
};

$rt_a  = $rt_ledger->add_account( 'Prova totali cassa', 'cash', 5000 );
$rt_b  = $rt_ledger->add_account( 'Prova totali banca', 'bank', 0 );
$rt_c  = $rt_ledger->add_account( 'Prova totali chiuso senza movimenti', 'cash', 0 );
$rt_d  = $rt_ledger->add_account( 'Prova totali chiuso a zero', 'cash', 0 );
$wpdb->update( Db::t( 'accounts' ), array( 'closed_at' => Db::now() ), array( 'id' => $rt_c ) );
$wpdb->update( Db::t( 'accounts' ), array( 'closed_at' => Db::now() ), array( 'id' => $rt_d ) );
$rt_i1    = $rt_cat( 'Prova totali entrata uno', 'Quote' );
$rt_i2    = $rt_cat( 'Prova totali entrata due' );
$rt_e1    = $rt_cat( 'Prova totali uscita uno', 'Servizi' );
$rt_e2    = $rt_cat( 'Prova totali uscita due' );
$rt_ep    = $rt_cat( 'Prova totali solo rimborsi' );
$rt_empty = $rt_cat( 'Prova totali senza movimenti' );
$rt_tr    = $rt_ledger->category_id_of_kind( 'adjustment' );
$rt_act   = 987654; // un'attività qualunque: i suoi movimenti non sono «generali»

// importi con i centesimi (12,34 €), un incasso annullato, giroconto, movimenti legati a un'attività, anni diversi
$rt_ins( '2003-03-10', 'income', 1234, $rt_a, $rt_i1 );
$rt_ins( '2003-03-11', 'income', 5, $rt_b, $rt_i2 );
$rt_ins( '2003-03-12', 'income', 99, $rt_b, $rt_i1 );
$rt_ins( '2003-04-01', 'expense', 999, $rt_a, $rt_e1 );
$rt_ins( '2003-04-02', 'expense', 100001, $rt_b, $rt_e2 );
$rt_ins( '2003-04-03', 'income', 7777, $rt_a, $rt_i1, null, true ); // annullato: non conta
$rt_ins( '2003-04-04', 'expense', 4242, $rt_a, $rt_e1, null, true ); // annullato: non conta
$rt_ins( '2003-05-01', 'transfer_out', 2500, $rt_a, $rt_tr );
$rt_ins( '2003-05-01', 'transfer_in', 2500, $rt_b, $rt_tr );
$rt_ins( '2003-06-01', 'income', 333, $rt_a, $rt_i2, $rt_act );
$rt_ins( '2003-06-02', 'expense', 111, $rt_a, $rt_e1, $rt_act );
$rt_ins( '2003-06-03', 'income', 17, $rt_a, $rt_i2 ); // generale, stessa voce dell'incasso di attività
$rt_ins( '2002-12-01', 'income', 100, $rt_d, $rt_i1 ); // conto chiuso: a zero prima del 2003, ha movimenti nel 2003 e torna a zero, nel 2004 non ne ha
$rt_ins( '2002-12-05', 'expense', 100, $rt_d, $rt_e1 );
$rt_ins( '2003-02-01', 'expense', 100, $rt_d, $rt_e1 );
$rt_ins( '2003-02-02', 'income', 100, $rt_d, $rt_i1 );
$rt_pay1 = $rt_ins( '2003-07-01', 'expense', 600, $rt_a, $rt_e1 ); // rimborso di un fondo, pagato nel 2003
$rt_ins( '2004-01-05', 'income', 1, $rt_a, $rt_i1 );
$rt_ins( '2004-01-06', 'expense', 50, $rt_b, $rt_e1 );
$rt_pay2 = $rt_ins( '2004-02-02', 'expense', 400, $rt_a, $rt_e2 );
$rt_pay3 = $rt_ins( '2005-01-01', 'expense', 800, $rt_a, $rt_ep ); // voce che ha solo rimborsi: non compare
$rt_pay4 = $rt_ins( '2005-01-02', 'expense', 90, $rt_a, $rt_e2, null, true ); // rimborso annullato: la riga di fondo non conta

$wpdb->insert( Db::t( 'funds' ), array( 'name' => 'Prova totali fondo', 'created_at' => Db::now() ) );
$rt_fund = (int) $wpdb->insert_id;
$rt_fe   = array();
foreach ( array(
	array( 'share', 700, '2003-06-20', null ), array( 'share', 350, '2004-01-20', null ), array( 'release', 200, '2004-02-10', null ), array( 'release', 25, '2003-11-11', null ),
	array( 'payout', 600, '2003-07-01', $rt_pay1 ), array( 'payout', 400, '2004-02-02', $rt_pay2 ), array( 'payout', 800, '2005-01-01', $rt_pay3 ), array( 'payout', 90, '2005-01-02', $rt_pay4 ),
) as $e ) {
	$wpdb->insert( Db::t( 'fund_entries' ), array( 'fund_id' => $rt_fund, 'kind' => $e[0], 'cents' => $e[1], 'entry_date' => $e[2], 'tx_id' => $e[3], 'note' => 'prova', 'created_at' => Db::now() ) );
	$rt_fe[] = (int) $wpdb->insert_id;
}

$rt_rep    = new ReportService();
$rt_ranges = array(
	array( '2003-01-01', '2003-12-31' ), array( '2004-01-01', '2004-12-31' ), array( '2005-01-01', '2005-12-31' ), array( '2003-03-01', '2004-12-31' ),
	array( '2003-06-01', '2003-06-30' ), array( '1990-01-01', '1990-12-31' ), array( '2000-01-01', '2100-12-31' ),
);
$rt_cur  = \AssociazioneSemplice\FiscalYears::range( \AssociazioneSemplice\FiscalYears::current() );
$rt_ranges[] = array( $rt_cur[0], $rt_cur[1] );
$rt_ranges[] = array( \AssociazioneSemplice\FiscalYears::range( \AssociazioneSemplice\FiscalYears::current() - 1 )[0], \AssociazioneSemplice\FiscalYears::range( \AssociazioneSemplice\FiscalYears::current() - 1 )[1] );
foreach ( $rt_ranges as $rt_r ) {
	$rt_old = asem_ref_period( $rt_r[0], $rt_r[1] );
	$rt_new = $rt_rep->period( $rt_r[0], $rt_r[1] );
	asem_ok( $rt_old === $rt_new, 'totali del rendiconto: ' . $rt_r[0] . ' - ' . $rt_r[1] . ' identici a prima (conti, voci, fondi, avanzo)' . ( $rt_old === $rt_new ? '' : ' ' . wp_json_encode( array( $rt_old, $rt_new ) ) ) );
	asem_ok( $rt_old['result'] === $rt_rep->period_result( $rt_r[0], $rt_r[1] ), 'totali del rendiconto: ' . $rt_r[0] . ' - ' . $rt_r[1] . ' il solo risultato coincide con quello del rendiconto intero' );
}
// i casi limite scelti davvero contano: 2003 ha importi con i centesimi, annullati, rimborsi, fondi e una voce senza movimenti che non compare
$rt_2003 = $rt_rep->period( '2003-01-01', '2003-12-31' );
asem_ok( 1234 + 99 + 100 === $rt_2003['income'][0]['cents'] && 'Quote' === $rt_2003['income'][0]['fiscal_group'] && 2 === count( array_filter( $rt_2003['income'], function ( $l ) {
	return false !== strpos( $l['name'], 'Prova totali' );
} ) ), 'totali del rendiconto: le entrate con i centesimi sono sommate per voce, l\'annullato non conta' );
asem_ok( array() === array_filter( array_merge( $rt_2003['income'], $rt_2003['expenses'] ), function ( $l ) {
	return false !== strpos( $l['name'], 'senza movimenti' ) || false !== strpos( $l['name'], 'solo rimborsi' );
} ), 'totali del rendiconto: una voce senza movimenti e una con soli rimborsi non compaiono' );
asem_ok( 700 === $rt_2003['fund_accrued'] && 25 === $rt_2003['fund_released'], 'totali del rendiconto: gli accantonamenti dei fondi del 2003 sono contati (i rimborsi pagati non due volte)' );
$rt_has = function ( array $report, int $account ) {
	return 1 === count( array_filter( $report['accounts'], function ( $a ) use ( $account ) {
		return (int) $a['account']['id'] === $account;
	} ) );
};
$rt_2004 = $rt_rep->period( '2004-01-01', '2004-12-31' );
asem_ok( ! $rt_has( $rt_2003, $rt_c ) && $rt_has( $rt_2003, $rt_d ) && ! $rt_has( $rt_2004, $rt_d ) && $rt_has( $rt_2004, $rt_a ), 'totali del rendiconto: un conto chiuso a zero compare solo se ha movimenti nel periodo' );

// la valutazione dell'anno sociale: entrate e uscite generali per voce identiche a prima
foreach ( array( '2003-06-15', '2004-01-10', '2005-01-02', Db::today() ) as $rt_d_ ) {
	$rt_year = Settings::social_year( $rt_d_ );
	list( $rt_gi, $rt_ge ) = asem_ref_general( $rt_year );
	$rt_sy = $rt_rep->social_year( $rt_year );
	asem_ok( $rt_gi === $rt_sy['general_income'] && $rt_ge === $rt_sy['general_expenses'], 'anno sociale ' . $rt_year->label() . ': entrate e uscite generali per voce identiche a prima' );
	asem_ok( $rt_sy['total_income'] - $rt_sy['total_expense'] === $rt_sy['result'] && array_sum( array_column( $rt_gi, 'cents' ) ) + array_sum( array_column( $rt_sy['activities'], 'income' ) ) === $rt_sy['total_income'], 'anno sociale ' . $rt_year->label() . ': i totali tornano' );
}

// il controllo di fine anno (Pro): stesso risultato di prima e poche query
if ( class_exists( '\AssociazioneSemplice\YearEndCheck' ) ) {
	foreach ( array( 2003, 2004, 2005, \AssociazioneSemplice\FiscalYears::current(), \AssociazioneSemplice\FiscalYears::current() - 1 ) as $rt_y ) {
		$rt_span = \AssociazioneSemplice\FiscalYears::range( $rt_y );
		asem_ok( asem_ref_period( $rt_span[0], $rt_span[1] )['result'] === \AssociazioneSemplice\YearEndCheck::year_result( $rt_y ), 'controllo di fine anno: il risultato del ' . $rt_y . ' è identico a prima' );
	}
	$rt_q0 = $wpdb->num_queries;
	\AssociazioneSemplice\YearEndCheck::year_result( 2003 );
	asem_ok( $wpdb->num_queries - $rt_q0 <= 6, 'controllo di fine anno: il risultato di un anno costa poche query (' . ( $wpdb->num_queries - $rt_q0 ) . ')' );
}

// pulizia
$wpdb->query( 'DELETE FROM ' . Db::t( 'transactions' ) . ' WHERE id IN (' . implode( ',', array_map( 'intval', $rt_tx ) ) . ')' );
$wpdb->query( 'DELETE FROM ' . Db::t( 'fund_entries' ) . ' WHERE id IN (' . implode( ',', array_map( 'intval', $rt_fe ) ) . ')' );
$wpdb->delete( Db::t( 'funds' ), array( 'id' => $rt_fund ) );
$wpdb->query( 'DELETE FROM ' . Db::t( 'categories' ) . " WHERE kind = 'prova_totali'" );
$wpdb->query( 'DELETE FROM ' . Db::t( 'accounts' ) . ' WHERE id IN (' . (int) $rt_a . ',' . (int) $rt_b . ',' . (int) $rt_c . ',' . (int) $rt_d . ')' );
