<?php
use AssociazioneSemplice\Audit;
use PHPUnit\Framework\TestCase;

/** Registro azioni: il numero di righe si conta davvero solo se sono poche, altrimenti basta la stima del database (niente conteggio a ogni apertura). */
final class AuditRowsTest extends TestCase {

	public function test_small_table_is_counted_exactly(): void {
		$calls = 0;
		$r     = Audit::pick_rows(
			150,
			function () use ( &$calls ) {
				$calls++;
				return 163;
			}
		);
		$this->assertSame( array( 163, true ), $r );
		$this->assertSame( 1, $calls );
	}

	public function test_big_table_uses_the_estimate_without_counting(): void {
		$calls = 0;
		$r     = Audit::pick_rows(
			Audit::EXACT_COUNT_UNDER,
			function () use ( &$calls ) {
				$calls++;
				return 1;
			}
		);
		$this->assertSame( array( Audit::EXACT_COUNT_UNDER, false ), $r );
		$this->assertSame( 0, $calls );
	}
}
