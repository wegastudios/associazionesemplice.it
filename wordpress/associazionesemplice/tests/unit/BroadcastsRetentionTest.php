<?php
use AssociazioneSemplice\Broadcasts;
use PHPUnit\Framework\TestCase;

/** Destinatari delle comunicazioni: si cancellano dopo 24 mesi, mai prima (la prova sul database è in tests/smoke.php). */
final class BroadcastsRetentionTest extends TestCase {

	public function test_retention_is_24_months(): void {
		$this->assertSame( 24, Broadcasts::KEEP_RECIPIENTS_MONTHS );
	}

	public function test_cutoff_is_exactly_24_months_before_now(): void {
		$this->assertSame( '2024-11-22 10:00:00', Broadcasts::recipients_cutoff( '2026-11-22 10:00:00' ) );
		$this->assertSame( '2023-12-31 23:59:59', Broadcasts::recipients_cutoff( '2025-12-31 23:59:59' ) );
	}
}
