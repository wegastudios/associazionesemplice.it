<?php
use AssociazioneSemplice\MemberBook;
use PHPUnit\Framework\TestCase;

/** Il criterio che decide chi entra nel libro soci di un esercizio: un'unica regola, provata con mesi di inizio diversi. */
final class MemberBookCriterionTest extends TestCase {

	private function person( string $type = 'ordinary', string $joined = '2020-01-01', string $left = '' ): array {
		return array( 'type' => $type, 'joined_on' => $joined, 'left_on' => $left );
	}

	private function card( string $from, string $to ): array {
		return array( 'valid_from' => $from, 'valid_to' => $to );
	}

	public function test_calendar_card_belongs_to_one_calendar_year_only(): void {
		$p = $this->person();
		$c = array( $this->card( '2025-01-01', '2025-12-31' ) );
		$this->assertTrue( MemberBook::qualifies( $p, $c, '2025-01-01', '2025-12-31' ) );
		$this->assertFalse( MemberBook::qualifies( $p, $c, '2024-01-01', '2024-12-31' ) );
		$this->assertFalse( MemberBook::qualifies( $p, $c, '2026-01-01', '2026-12-31' ) );
	}

	public function test_card_across_two_fiscal_years_is_in_both_books(): void {
		$p = $this->person();
		$c = array( $this->card( '2025-01-01', '2025-12-31' ) );
		// esercizio a luglio: 2024/25 va dal 1/7/2024 al 30/6/2025, 2025/26 dal 1/7/2025 al 30/6/2026
		$this->assertTrue( MemberBook::qualifies( $p, $c, '2024-07-01', '2025-06-30' ) );
		$this->assertTrue( MemberBook::qualifies( $p, $c, '2025-07-01', '2026-06-30' ) );
		$this->assertFalse( MemberBook::qualifies( $p, $c, '2026-07-01', '2027-06-30' ) );
		// esercizio a settembre
		$this->assertTrue( MemberBook::qualifies( $p, $c, '2024-09-01', '2025-08-31' ) );
		$this->assertTrue( MemberBook::qualifies( $p, $c, '2025-09-01', '2026-08-31' ) );
		$this->assertFalse( MemberBook::qualifies( $p, $c, '2023-09-01', '2024-08-31' ) );
	}

	public function test_boundaries_are_inclusive(): void {
		$p = $this->person();
		$this->assertTrue( MemberBook::qualifies( $p, array( $this->card( '2024-06-30', '2024-06-30' ) ), '2024-06-30', '2025-06-29' ) );
		$this->assertTrue( MemberBook::qualifies( $p, array( $this->card( '2023-01-01', '2024-06-30' ) ), '2024-06-30', '2025-06-29' ) );
		$this->assertFalse( MemberBook::qualifies( $p, array( $this->card( '2023-01-01', '2024-06-29' ) ), '2024-06-30', '2025-06-29' ) );
		$this->assertFalse( MemberBook::qualifies( $p, array( $this->card( '2025-06-30', '2025-12-31' ) ), '2024-06-30', '2025-06-29' ) );
	}

	public function test_member_without_a_valid_card_is_absent(): void {
		$this->assertFalse( MemberBook::qualifies( $this->person(), array(), '2025-01-01', '2025-12-31' ) );
		$this->assertFalse( MemberBook::qualifies( $this->person(), array( $this->card( '2023-01-01', '2023-12-31' ) ), '2025-01-01', '2025-12-31' ) );
	}

	public function test_founder_enters_from_admission_unless_already_left(): void {
		$f = $this->person( 'founder', '2025-03-01' );
		$this->assertFalse( MemberBook::qualifies( $f, array(), '2024-01-01', '2024-12-31' ) );
		$this->assertTrue( MemberBook::qualifies( $f, array(), '2025-01-01', '2025-12-31' ) );
		$this->assertTrue( MemberBook::qualifies( $f, array(), '2030-01-01', '2030-12-31' ) );
		$gone = $this->person( 'founder', '2020-03-01', '2025-05-10' );
		$this->assertTrue( MemberBook::qualifies( $gone, array(), '2025-01-01', '2025-12-31' ), 'chi ha lasciato durante l\'esercizio compare' );
		$this->assertFalse( MemberBook::qualifies( $gone, array(), '2026-01-01', '2026-12-31' ), 'chi aveva lasciato prima dell\'inizio non compare' );
	}

	public function test_member_who_left_during_the_year_is_in_that_book_only(): void {
		$p = $this->person( 'ordinary', '2020-01-01', '2025-05-10' );
		$c = array( $this->card( '2025-01-01', '2025-12-31' ) );
		$this->assertTrue( MemberBook::qualifies( $p, $c, '2025-01-01', '2025-12-31' ) );
		$this->assertFalse( MemberBook::qualifies( $p, array( $this->card( '2026-01-01', '2026-12-31' ) ), '2026-01-01', '2026-12-31' ) );
	}

	public function test_admitted_after_the_end_is_absent_and_guests_are_never_in(): void {
		$this->assertFalse( MemberBook::qualifies( $this->person( 'ordinary', '2026-02-01' ), array( $this->card( '2025-01-01', '2026-12-31' ) ), '2025-01-01', '2025-12-31' ) );
		$this->assertFalse( MemberBook::qualifies( $this->person( 'guest' ), array( $this->card( '2025-01-01', '2025-12-31' ) ), '2025-01-01', '2025-12-31' ) );
		$this->assertFalse( MemberBook::qualifies( $this->person( 'guardian' ), array( $this->card( '2025-01-01', '2025-12-31' ) ), '2025-01-01', '2025-12-31' ) );
	}

	public function test_snapshot_fields_are_the_minimum_data_only(): void {
		$f = MemberBook::snapshot_fields();
		foreach ( array( 'email', 'phone', 'card_number', 'fee_cents', 'fee_year', 'guardian', 'applied_on' ) as $not ) {
			$this->assertNotContains( $not, $f );
		}
		foreach ( array( 'last_name', 'first_name', 'birth_date', 'birth_place', 'tax_code', 'residence', 'level', 'joined_on', 'approved_on', 'left_on', 'left_reason' ) as $yes ) {
			$this->assertContains( $yes, $f );
		}
	}
}
