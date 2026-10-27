<?php
use AssociazioneSemplice\AssemblyRules;
use AssociazioneSemplice\BudgetRules;
use AssociazioneSemplice\RefundRules;
use PHPUnit\Framework\TestCase;

final class BlockDRulesTest extends TestCase {

	// ---------- Assemblee ----------

	public function test_required_quorum(): void {
		$this->assertSame( 51, AssemblyRules::required( 100, 50 ) ); // metà più uno
		$this->assertSame( 51, AssemblyRules::required( 101, 50 ) );
		$this->assertSame( 4, AssemblyRules::required( 6, 50 ) );
		$this->assertSame( 75, AssemblyRules::required( 100, 75 ) );
		$this->assertSame( 8, AssemblyRules::required( 10, 75 ) ); // arrotondato per eccesso
		$this->assertSame( 0, AssemblyRules::required( 100, 0 ) );
		$this->assertSame( 0, AssemblyRules::required( 0, 50 ) );
		$this->assertSame( 100, AssemblyRules::required( 100, 150 ) ); // percentuale fuori misura
	}

	public function test_quorum_counts_proxies(): void {
		$q = AssemblyRules::quorum( 10, 4, 1, 50 );
		$this->assertSame( 5, $q['counted'] );
		$this->assertSame( 6, $q['required'] );
		$this->assertFalse( $q['reached'] );
		$this->assertTrue( AssemblyRules::quorum( 10, 4, 2, 50 )['reached'] );
		$this->assertTrue( AssemblyRules::quorum( 10, 0, 0, 0 )['reached'] ); // seconda convocazione senza minimo
	}

	public function test_default_quorum_and_call_percent(): void {
		$this->assertSame( array( 50, 0 ), AssemblyRules::default_quorum( AssemblyRules::ORDINARY ) );
		$this->assertSame( array( 75, 50 ), AssemblyRules::default_quorum( AssemblyRules::EXTRAORDINARY ) );
		$this->assertSame( 50, AssemblyRules::percent_for_call( 1, 50, 0 ) );
		$this->assertSame( 0, AssemblyRules::percent_for_call( 2, 50, 0 ) );
	}

	public function test_max_proxies_depends_on_size(): void {
		$this->assertSame( 3, AssemblyRules::max_proxies( 499 ) );
		$this->assertSame( 5, AssemblyRules::max_proxies( 500 ) );
	}

	public function test_proxy_refusals(): void {
		$ok = function ( int $a, int $b, bool $ea, bool $eb, array $of, array $givers, array $takers, int $max = 3 ) {
			return AssemblyRules::proxy_refusal( $a, $b, $ea, $eb, $of, $givers, $takers, $max );
		};
		$this->assertSame( '', $ok( 1, 2, true, true, array(), array(), array() ) );
		$this->assertNotSame( '', $ok( 1, 1, true, true, array(), array(), array() ) ); // sé stessi
		$this->assertNotSame( '', $ok( 1, 2, false, true, array(), array(), array() ) ); // chi delega non è in regola
		$this->assertNotSame( '', $ok( 1, 2, true, false, array(), array(), array() ) ); // il delegato non è in regola (tutore, ospite, sospeso)
		$this->assertNotSame( '', $ok( 1, 2, true, true, array(), array(), array( 1 ) ) ); // chi riceve non delega
		$this->assertNotSame( '', $ok( 1, 2, true, true, array(), array( 2 ), array() ) ); // il delegato ha già delegato
		$this->assertNotSame( '', $ok( 1, 2, true, true, array(), array( 1 ), array() ) ); // doppia delega dello stesso socio
		$this->assertNotSame( '', $ok( 1, 2, true, true, array( 3, 4, 5 ), array( 3, 4, 5 ), array( 2 ) ) ); // già tre deleghe
		$this->assertSame( '', $ok( 1, 2, true, true, array( 3, 4 ), array( 3, 4 ), array( 2 ) ) );
	}

	public function test_status_moves(): void {
		$this->assertTrue( AssemblyRules::can_move( AssemblyRules::DRAFT, AssemblyRules::CONVENED ) );
		$this->assertTrue( AssemblyRules::can_move( AssemblyRules::CONVENED, AssemblyRules::HELD ) );
		$this->assertFalse( AssemblyRules::can_move( AssemblyRules::DRAFT, AssemblyRules::HELD ) );
		$this->assertFalse( AssemblyRules::can_move( AssemblyRules::HELD, AssemblyRules::DRAFT ) );
	}

	public function test_notice_and_calls(): void {
		$this->assertSame( 10, AssemblyRules::notice_days( '2026-05-01', '2026-05-11' ) );
		$this->assertNotSame( '', AssemblyRules::notice_warning( 7 ) );
		$this->assertSame( '', AssemblyRules::notice_warning( 8 ) );
		$this->assertNull( AssemblyRules::check_calls( '2026-05-01 10:00:00', '' ) );
		$this->assertNull( AssemblyRules::check_calls( '2026-05-01 10:00:00', '2026-05-02 10:00:00' ) );
		$this->assertNotNull( AssemblyRules::check_calls( '2026-05-01 10:00:00', '2026-05-01 09:00:00' ) );
	}

	public function test_summary_line(): void {
		$q = AssemblyRules::quorum( 10, 3, 0, 50 );
		$this->assertStringContainsString( 'NON raggiunge', AssemblyRules::summary_line( 10, 3, 0, $q, 1 ) );
		$q = AssemblyRules::quorum( 10, 6, 0, 50 );
		$this->assertStringContainsString( 'validamente costituita', AssemblyRules::summary_line( 10, 6, 0, $q, 2 ) );
	}

	// ---------- Preventivo ----------

	public function test_compare_expense(): void {
		$c = BudgetRules::compare( BudgetRules::EXPENSE, 100000, 40000 );
		$this->assertSame( 60000, $c['diff'] ); // quanto resta
		$this->assertSame( 40, $c['percent'] );
		$this->assertSame( BudgetRules::OK, $c['status'] );
		$this->assertSame( BudgetRules::NEAR, BudgetRules::compare( BudgetRules::EXPENSE, 1000, 950 )['status'] );
		$over = BudgetRules::compare( BudgetRules::EXPENSE, 1000, 1200 );
		$this->assertSame( BudgetRules::OVER, $over['status'] );
		$this->assertSame( -200, $over['diff'] );
	}

	public function test_compare_income_and_unplanned(): void {
		$c = BudgetRules::compare( BudgetRules::INCOME, 5000, 6000 );
		$this->assertSame( 1000, $c['diff'] ); // incassato in più
		$this->assertSame( BudgetRules::OK, $c['status'] );
		$this->assertSame( BudgetRules::UNPLANNED, BudgetRules::compare( BudgetRules::EXPENSE, 0, 300 )['status'] );
		$none = BudgetRules::compare( BudgetRules::INCOME, 0, 0 );
		$this->assertSame( BudgetRules::NONE, $none['status'] );
		$this->assertNull( $none['percent'] );
	}

	public function test_merge_adds_unplanned_actuals(): void {
		$lines = array( array( 'category_id' => 1, 'type' => 'expense', 'planned_cents' => 1000 ) );
		$rows  = BudgetRules::merge( $lines, array( 1 => 400, 2 => 250, 3 => 0 ), array( 1 => 'expense', 2 => 'income', 3 => 'expense' ) );
		$this->assertCount( 2, $rows ); // la 3 non ha movimenti
		$this->assertSame( 1000, $rows[0]['planned'] );
		$this->assertSame( BudgetRules::UNPLANNED, $rows[1]['status'] );
		$t = BudgetRules::totals( $rows );
		$this->assertSame( 1000, $t['expense_planned'] );
		$this->assertSame( 400, $t['expense_actual'] );
		$this->assertSame( 250, $t['income_actual'] );
		$this->assertSame( -1000, $t['result_planned'] );
		$this->assertSame( -150, $t['result_actual'] );
	}

	public function test_scale_period_and_kinds(): void {
		$this->assertSame( 11000, BudgetRules::scale( 10000, 10 ) );
		$this->assertSame( 9000, BudgetRules::scale( 10000, -10 ) );
		$this->assertSame( 0, BudgetRules::scale( 10000, -100 ) );
		$this->assertSame( array( '2026-01-01', '2026-12-31' ), BudgetRules::period( 2026 ) );
		$this->assertTrue( BudgetRules::valid_year( 2026 ) );
		$this->assertFalse( BudgetRules::valid_year( 1800 ) );
		$this->assertSame( BudgetRules::INCOME, BudgetRules::type_of_kind( 'donation' ) );
		$this->assertSame( BudgetRules::EXPENSE, BudgetRules::type_of_kind( 'general_cost' ) );
		$this->assertNull( BudgetRules::type_of_kind( 'adjustment' ) );
		$this->assertNull( BudgetRules::type_of_kind( 'boh' ) );
	}

	// ---------- Rimborsi ----------

	public function test_refund_moves(): void {
		$this->assertTrue( RefundRules::can_move( RefundRules::REQUESTED, RefundRules::APPROVED ) );
		$this->assertTrue( RefundRules::can_move( RefundRules::APPROVED, RefundRules::PAID ) );
		$this->assertFalse( RefundRules::can_move( RefundRules::REQUESTED, RefundRules::PAID ) ); // non si paga senza approvare
		$this->assertFalse( RefundRules::can_move( RefundRules::PAID, RefundRules::REJECTED ) );
		$this->assertFalse( RefundRules::can_move( RefundRules::REJECTED, RefundRules::APPROVED ) );
	}

	public function test_selfcert_limits(): void {
		$this->assertSame( '', RefundRules::amount_refusal( RefundRules::SELFCERT, 1000 ) );
		$this->assertNotSame( '', RefundRules::amount_refusal( RefundRules::SELFCERT, 1001 ) );
		$this->assertNotSame( '', RefundRules::amount_refusal( RefundRules::SELFCERT, 500, 600, 600 ) ); // 10 € al giorno
		$this->assertNotSame( '', RefundRules::amount_refusal( RefundRules::SELFCERT, 500, 0, 14600 ) ); // 150 € al mese
		$this->assertSame( '', RefundRules::amount_refusal( RefundRules::DOCUMENTED, 50000, 0, 0 ) ); // le spese documentate non hanno questo tetto
		$this->assertNotSame( '', RefundRules::amount_refusal( RefundRules::DOCUMENTED, 0 ) );
	}

	public function test_km_and_kinds(): void {
		$this->assertSame( 1800, RefundRules::km_amount( 40, 45 ) );
		$this->assertSame( 0, RefundRules::km_amount( -5, 45 ) );
		$this->assertArrayNotHasKey( RefundRules::KM, RefundRules::available_kinds( 0 ) );
		$this->assertArrayHasKey( RefundRules::KM, RefundRules::available_kinds( 45 ) );
		$this->assertTrue( RefundRules::needs_receipt( RefundRules::DOCUMENTED ) );
		$this->assertFalse( RefundRules::needs_receipt( RefundRules::SELFCERT ) );
	}

	public function test_self_approval_and_dates(): void {
		$this->assertTrue( RefundRules::self_approval_blocked( 5, 5, false ) );
		$this->assertFalse( RefundRules::self_approval_blocked( 5, 5, true ) );
		$this->assertFalse( RefundRules::self_approval_blocked( 5, 6, false ) );
		$this->assertFalse( RefundRules::self_approval_blocked( 0, 0, false ) ); // utente senza scheda socio
		$this->assertNull( RefundRules::check_expense_date( '2026-05-01', '2026-05-01' ) );
		$this->assertNotNull( RefundRules::check_expense_date( '2026-05-02', '2026-05-01' ) );
		$this->assertNotNull( RefundRules::check_expense_date( '2026-02-30', '2026-05-01' ) );
	}
}
