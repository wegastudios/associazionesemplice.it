<?php

use AssociazioneSemplice\PaymentCalc;
use AssociazioneSemplice\SocialYear;
use PHPUnit\Framework\TestCase;

/** Il primo mese di chi ha fatto la prova: quota in proporzione, gli altri mesi interi. */
final class TrialShareTest extends TestCase {

	public function test_share(): void {
		$this->assertSame( 2000, PaymentCalc::share( 4000, 2, 4 ) );
		$this->assertSame( 1467, PaymentCalc::share( 4000, 11, 30 ) );
		$this->assertSame( 4000, PaymentCalc::share( 4000, 4, 4 ) );
		$this->assertSame( 4000, PaymentCalc::share( 4000, 1, 0 ) );
		$this->assertSame( 0, PaymentCalc::share( 0, 2, 4 ) );
	}

	public function test_only_the_first_month_changes(): void {
		$y = SocialYear::from_label( '2026', 1 );
		$s = PaymentCalc::compute( 4000, '2026-03', null, '2026-05', $y, array(), 0, null, 1, 2000 );
		$this->assertSame( array( 2000, 4000, 4000 ), array_column( $s['months'], 'due' ) );
		$this->assertSame( 10000, $s['total_due'] );
		$paid = PaymentCalc::compute( 4000, '2026-03', null, '2026-03', $y, array( '2026-03' => 2000 ), 0, null, 1, 2000 );
		$this->assertSame( PaymentCalc::PAID, $paid['months'][0]['state'] );
		$this->assertSame( 0, $paid['balance'] );
	}

	public function test_without_a_trial_nothing_changes(): void {
		$y = SocialYear::from_label( '2026', 1 );
		$this->assertSame( PaymentCalc::compute( 4000, '2026-03', null, '2026-05', $y, array() ), PaymentCalc::compute( 4000, '2026-03', null, '2026-05', $y, array(), 0, null, 1, null ) );
	}
}
