<?php
use AssociazioneSemplice\PaymentCalc;
use AssociazioneSemplice\SocialYear;
use PHPUnit\Framework\TestCase;

final class PaymentQuarterlyTest extends TestCase {

	private function months( array $s ): array {
		return array_column( $s['months'], 'due', 'month' );
	}

	public function test_quarter_is_due_only_at_the_start_of_each_period(): void {
		$s = PaymentCalc::compute( 9000, '2025-10', null, '2026-01', new SocialYear( 2025, 9 ), array(), 0, null, 3 );
		$this->assertSame( array( '2025-10' => 9000, '2026-01' => 9000 ), $this->months( $s ) );
		$this->assertSame( 18000, $s['total_due'] );
	}

	public function test_a_payment_in_any_month_of_the_quarter_covers_it(): void {
		$s = PaymentCalc::compute( 9000, '2025-10', null, '2025-12', new SocialYear( 2025, 9 ), array( '2025-11' => 9000 ), 0, null, 3 );
		$this->assertTrue( $s['regular'] );
		$this->assertSame( 0, $s['balance'] );
		$this->assertCount( 0, $s['unpaid_months'] );
	}

	public function test_monthly_is_unchanged_with_step_one(): void {
		$s = PaymentCalc::compute( 1000, '2025-10', null, '2025-12', new SocialYear( 2025, 9 ), array(), 0, null, 1 );
		$this->assertSame( array( '2025-10' => 1000, '2025-11' => 1000, '2025-12' => 1000 ), $this->months( $s ) );
	}

	public function test_months_between(): void {
		$this->assertSame( 5, PaymentCalc::months_between( '2025-10', '2026-03' ) );
		$this->assertSame( -1, PaymentCalc::months_between( '2025-10', '2025-09' ) );
	}
}
