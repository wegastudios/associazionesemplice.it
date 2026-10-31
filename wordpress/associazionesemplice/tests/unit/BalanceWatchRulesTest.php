<?php
use AssociazioneSemplice\BalanceWatchRules as R;
use AssociazioneSemplice\FiscalYears;
use PHPUnit\Framework\TestCase;

/** Regole pure del controllo di bilancio (Pro Fiscale) e dell'anno fiscale con inizio a scelta. */
final class BalanceWatchRulesTest extends TestCase {

	public function test_projection_scales_to_the_whole_year(): void {
		$p = R::projection( 3000, 1500, 90, 360 );
		$this->assertSame( array( 'income' => 12000, 'expense' => 6000 ), $p );
		$this->assertSame( array( 'income' => 0, 'expense' => 0 ), R::projection( 100, 100, 0, 365 ) );
		$this->assertSame( array( 'income' => 500, 'expense' => 200 ), R::projection( 500, 200, 400, 365 ), 'oltre la fine dell\'anno non si ingrandisce' );
	}

	public function test_enough_data_needs_the_configured_months(): void {
		$this->assertFalse( R::enough_data( 80, 3 ) );
		$this->assertTrue( R::enough_data( 90, 3 ) );
		$this->assertTrue( R::enough_data( 30, 1 ) );
	}

	public function test_uncovered_expenses_alert_over_the_threshold(): void {
		$a = R::alerts( 10000, 11100, 10, 20 ); // 11% in più
		$this->assertCount( 1, $a );
		$this->assertSame( R::UNCOVERED, $a[0]['kind'] );
		$this->assertSame( 1100, $a[0]['gap'] );
		$this->assertSame( 11, $a[0]['percent'] );
		$this->assertSame( array(), R::alerts( 10000, 11000, 10, 20 ), 'esattamente la soglia: nessun avviso' );
		$this->assertSame( array(), R::alerts( 10000, 9000, 10, 20 ) );
	}

	public function test_excess_surplus_alert_over_the_threshold(): void {
		$a = R::alerts( 12100, 10000, 10, 20 ); // 21% in più delle uscite
		$this->assertCount( 1, $a );
		$this->assertSame( R::SURPLUS, $a[0]['kind'] );
		$this->assertSame( 2100, $a[0]['gap'] );
		$this->assertSame( array(), R::alerts( 12000, 10000, 10, 20 ) );
	}

	public function test_zero_income_or_expense(): void {
		$this->assertSame( R::UNCOVERED, R::alerts( 0, 500, 10, 20 )[0]['kind'] );
		$this->assertNull( R::alerts( 0, 500, 10, 20 )[0]['percent'] );
		$this->assertSame( R::SURPLUS, R::alerts( 500, 0, 10, 20 )[0]['kind'] );
		$this->assertSame( array(), R::alerts( 0, 0, 10, 20 ) );
	}

	public function test_thresholds_are_configurable(): void {
		$this->assertCount( 1, R::alerts( 10000, 10600, 5, 20 ) );
		$this->assertSame( array(), R::alerts( 10000, 10600, 10, 20 ) );
	}

	public function test_messages_say_what_to_do_in_plain_italian(): void {
		$m = R::message( array( 'kind' => R::UNCOVERED, 'gap' => 123456, 'percent' => 15 ) );
		$this->assertStringContainsString( 'spese non sono coperte', $m );
		$this->assertStringContainsString( '1.234,56', $m );
		$this->assertStringContainsString( '15% in più', $m );
		$this->assertStringContainsString( 'consiglio direttivo', R::message( array( 'kind' => R::SURPLUS, 'gap' => 100, 'percent' => null ) ) );
	}

	public function test_months_of_the_fiscal_year(): void {
		$this->assertSame( array( '2026-01', '2026-02', '2026-03', '2026-04', '2026-05', '2026-06', '2026-07', '2026-08', '2026-09', '2026-10', '2026-11', '2026-12' ), R::months( 2026, 1 ) );
		$m = R::months( 2025, 7 );
		$this->assertSame( '2025-07', $m[0] );
		$this->assertSame( '2025-12', $m[5] );
		$this->assertSame( '2026-01', $m[6] );
		$this->assertSame( '2026-06', $m[11] );
	}

	public function test_month_offset_and_range(): void {
		$this->assertSame( 0, R::month_offset( '2026-07-15', 7 ) );
		$this->assertSame( 11, R::month_offset( '2027-06-30', 7 ) );
		$this->assertSame( 3, R::month_offset( '2026-04-02', 1 ) );
		$this->assertSame( array( '2024-02-01', '2024-02-29' ), R::month_range( '2024-02' ) );
		$this->assertSame( 31, R::days_between( '2026-01-01', '2026-01-31' ) );
		$this->assertSame( 0, R::days_between( '2026-02-01', '2026-01-31' ) );
	}

	public function test_planned_share_follows_the_completed_months(): void {
		$this->assertSame( 300000, R::planned_share( 1200000, 3 ) );
		$this->assertSame( 0, R::planned_share( 1200000, 0 ) );
		$this->assertSame( 1200000, R::planned_share( 1200000, 20 ) );
	}

	public function test_split_between_funds_oldest_first(): void {
		$this->assertSame( array( 1 => 4000, 2 => 1000 ), R::split( 5000, array( 1 => 4000, 2 => 3000, 3 => 500 ) ), 'una spesa divisa su due fondi' );
		$this->assertSame( array( 1 => 800 ), R::split( 800, array( 1 => 4000 ) ) );
		$this->assertSame( array( 1 => 100, 2 => 100 ), R::split( 500, array( 1 => 100, 2 => 100 ) ), 'il resto non si attribuisce' );
		$this->assertSame( array(), R::split( 500, array( 1 => 0 ) ) );
		$this->assertSame( array(), R::split( 0, array( 1 => 100 ) ) );
	}

	public function test_funds_are_ordered_same_activity_first_then_oldest(): void {
		$funds = array(
			array( 'id' => 3, 'activity_id' => 7, 'created_at' => '2025-03-01 10:00:00' ),
			array( 'id' => 1, 'activity_id' => 5, 'created_at' => '2024-01-01 10:00:00' ),
			array( 'id' => 2, 'activity_id' => 7, 'created_at' => '2024-06-01 10:00:00' ),
		);
		$this->assertSame( array( 2, 3, 1 ), array_column( R::order_funds( $funds, 7 ), 'id' ) );
		$this->assertSame( array( 1, 2, 3 ), array_column( R::order_funds( $funds, 0 ), 'id' ), 'senza attività: i più vecchi prima' );
	}

	public function test_fiscal_year_range_and_membership_by_start_month(): void {
		$this->assertSame( array( '2026-01-01', '2026-12-31' ), FiscalYears::range_for( 2026, 1 ) );
		$this->assertSame( array( '2025-07-01', '2026-06-30' ), FiscalYears::range_for( 2025, 7 ) );
		$this->assertSame( array( '2025-09-01', '2026-08-31' ), FiscalYears::range_for( 2025, 9 ) );
		$this->assertSame( 2026, FiscalYears::year_for( '2026-03-10', 1 ) );
		$this->assertSame( 2025, FiscalYears::year_for( '2026-03-10', 7 ) );
		$this->assertSame( 2026, FiscalYears::year_for( '2026-07-01', 7 ) );
		$this->assertSame( 2025, FiscalYears::year_for( '2026-06-30', 7 ) );
		$this->assertSame( 1, FiscalYears::start_month(), 'senza estensioni l\'anno fiscale è quello solare' );
	}

	public function test_every_date_falls_in_the_range_of_its_year(): void {
		foreach ( array( 1, 4, 7, 9, 12 ) as $start ) {
			for ( $m = 1; $m <= 12; $m++ ) {
				foreach ( array( 1, 15, 28 ) as $d ) {
					$date = sprintf( '2026-%02d-%02d', $m, $d );
					$y    = FiscalYears::year_for( $date, $start );
					list( $from, $to ) = FiscalYears::range_for( $y, $start );
					$this->assertTrue( $date >= $from && $date <= $to, "$date con inizio al mese $start" );
				}
			}
		}
	}

	public function test_reconcile_reimbursed_against_documented_per_person(): void {
		$rows = R::reconcile( array( 1 => 100000, 2 => 50000, 3 => 20000 ), array( 1 => 40000, 2 => 50050, 4 => 30000 ), 100 );
		$by   = array();
		foreach ( $rows as $r ) {
			$by[ $r['person_id'] ] = $r;
		}
		$this->assertSame( 60000, $by[1]['diff'] );
		$this->assertSame( R::RECON_UNCOVERED, $by[1]['status'] );
		$this->assertSame( R::RECON_OK, $by[2]['status'], 'differenza di 0,50 €: sotto la tolleranza' );
		$this->assertSame( R::RECON_UNCOVERED, $by[3]['status'], 'rimborsato e niente documentato' );
		$this->assertSame( R::RECON_UNPAID, $by[4]['status'], 'documentato e mai rimborsato' );
		$this->assertSame( 1, $rows[0]['person_id'], 'prima chi ha la differenza più grande' );
		$this->assertCount( 3, R::gaps( $rows ) );
		$this->assertCount( 2, R::gaps( $rows, true ) );
		$this->assertSame( R::RECON_UNCOVERED, R::reconcile( array( 1 => 101 ), array(), 100 )[0]['status'] );
		$this->assertSame( R::RECON_OK, R::reconcile( array( 1 => 100 ), array(), 100 )[0]['status'] );
	}

	public function test_year_end_window_follows_the_fiscal_year(): void {
		$w = R::year_end_window( '2026-11-01', 1, 1 );
		$this->assertTrue( $w['active'] );
		$this->assertSame( '2026-11-01', $w['start'] );
		$this->assertSame( '2026-12-31', $w['end'] );
		$this->assertFalse( R::year_end_window( '2026-10-31', 1, 1 )['active'] );
		$this->assertSame( '2026-10-01', R::year_end_window( '2026-10-15', 1, 2 )['start'] );
		$s = R::year_end_window( '2026-07-01', 9, 1 ); // l'anno inizia a settembre: finisce il 31 agosto
		$this->assertTrue( $s['active'] );
		$this->assertSame( '2026-08-31', $s['end'] );
		$this->assertSame( 2025, $s['year'] );
		$this->assertFalse( R::year_end_window( '2026-06-30', 9, 1 )['active'] );
		$this->assertFalse( R::year_end_window( '2026-09-01', 9, 1 )['active'], 'un nuovo anno è appena iniziato' );
		$this->assertSame( '2026-09-01', R::year_end_window( '2026-12-01', 1, 9 )['start'], 'anticipo limitato a 3 mesi' );
	}

	public function test_year_end_notice_phase_comes_before_the_fixed_one(): void {
		$n = R::year_end_window( '2026-09-10', 1, 1, 3 );
		$this->assertSame( 'notice', $n['phase'] );
		$this->assertSame( '2026-08-01', $n['notice_start'] );
		$this->assertFalse( $n['active'] );
		$this->assertSame( 'fixed', R::year_end_window( '2026-11-02', 1, 1, 3 )['phase'] );
		$this->assertSame( '', R::year_end_window( '2026-07-31', 1, 1, 3 )['phase'] );
		$this->assertSame( '', R::year_end_window( '2026-10-15', 1, 1, 0 )['phase'], '0 = nessun preavviso' );
		$this->assertSame( '2026-01-01', R::year_end_window( '2026-02-01', 1, 1, 11 )['notice_start'], 'il preavviso non esce dall\'anno fiscale' );
	}
}
