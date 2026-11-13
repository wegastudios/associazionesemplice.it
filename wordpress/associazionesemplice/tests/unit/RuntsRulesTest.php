<?php
use AssociazioneSemplice\RuntsRules;
use AssociazioneSemplice\VotingRight;
use PHPUnit\Framework\TestCase;

/** Regole pure dei dati per il RUNTS e del diritto di voto: date, minimo di soci, scadenze. */
final class RuntsRulesTest extends TestCase {

	private function persons( int $n, string $joined = '2020-01-01', string $left = '' ): array {
		return array_fill( 0, $n, array( 'kind' => 'person', 'same' => false, 'joined' => $joined, 'left' => $left ) );
	}

	public function test_reference_date_is_always_december_31(): void {
		$this->assertSame( '2025-12-31', RuntsRules::reference_date( 2025 ) );
		$this->assertSame( 2025, RuntsRules::last_reference_year( '2026-03-10' ) );
		$this->assertSame( 2026, RuntsRules::last_reference_year( '2026-12-31' ) );
	}

	public function test_june_deadline_and_reminder_window(): void {
		$this->assertSame( '2026-06-30', RuntsRules::june_deadline( 2025 ) );
		$this->assertNull( RuntsRules::june_reminder_year( '2026-04-30' ) );
		$this->assertSame( 2025, RuntsRules::june_reminder_year( '2026-05-01' ) );
		$this->assertSame( 2025, RuntsRules::june_reminder_year( '2026-06-30' ) );
		$this->assertNull( RuntsRules::june_reminder_year( '2026-07-01' ) );
	}

	public function test_minimum_is_seven_persons_or_three_entities(): void {
		$this->assertTrue( RuntsRules::below_minimum( 6, 2 ) );
		$this->assertFalse( RuntsRules::below_minimum( 7, 0 ) );
		$this->assertFalse( RuntsRules::below_minimum( 0, 3 ) );
		$this->assertTrue( RuntsRules::below_minimum( 6, 0 ) );
		$this->assertSame( 7, RuntsRules::MIN_PERSONS );
		$this->assertSame( 3, RuntsRules::MIN_ENTITIES );
		$this->assertSame( 30, RuntsRules::NOTICE_DAYS );
	}

	public function test_only_odv_and_aps_have_the_minimum(): void {
		$this->assertTrue( RuntsRules::has_minimum( 'odv' ) );
		$this->assertTrue( RuntsRules::has_minimum( 'aps' ) );
		$this->assertFalse( RuntsRules::has_minimum( 'other' ) );
		$this->assertFalse( RuntsRules::has_minimum( '' ) );
	}

	public function test_below_since_finds_the_day_the_count_dropped(): void {
		$m   = $this->persons( 6 ); // sei soci da sempre...
		$m[] = array( 'kind' => 'person', 'same' => false, 'joined' => '2020-01-01', 'left' => '2026-10-01' ); // ...e un settimo che esce il 1/10
		$this->assertSame( '2026-10-01', RuntsRules::below_since( $m, '2026-11-13' ) );
		$this->assertSame( '2026-10-31', RuntsRules::notice_deadline( '2026-10-01' ) );
	}

	public function test_below_since_is_null_when_not_below_or_since_always(): void {
		$this->assertNull( RuntsRules::below_since( $this->persons( 7 ), '2026-11-13' ) );
		$this->assertNull( RuntsRules::below_since( $this->persons( 3 ), '2026-11-13' ), 'sotto da sempre: non si sa da quando' );
	}

	public function test_below_since_restarts_after_going_back_above(): void {
		$m   = $this->persons( 7 );
		$m[0]['left'] = '2026-03-01'; // sotto il minimo dal 1/3...
		$m[]          = array( 'kind' => 'person', 'same' => false, 'joined' => '2026-04-01', 'left' => '' ); // ...di nuovo a sette il 1/4
		$m[1]['left'] = '2026-09-15'; // ...e di nuovo sotto dal 15/9
		$this->assertSame( '2026-09-15', RuntsRules::below_since( $m, '2026-11-13' ) );
	}

	public function test_entities_of_the_same_section_make_the_minimum(): void {
		$m = $this->persons( 2 );
		for ( $i = 0; $i < 3; $i++ ) {
			$m[] = array( 'kind' => 'entity', 'same' => true, 'joined' => '2020-01-01', 'left' => '' );
		}
		$this->assertSame( array( 2, 3 ), RuntsRules::counts_on( $m, '2026-11-13' ) );
		$this->assertNull( RuntsRules::below_since( $m, '2026-11-13' ) );
		$m[2]['same'] = false; // un ente non della stessa sezione non vale
		$this->assertSame( array( 2, 2 ), RuntsRules::counts_on( $m, '2026-11-13' ) );
	}

	public function test_member_who_leaves_stops_counting_from_that_day(): void {
		$m = array( array( 'kind' => 'person', 'same' => false, 'joined' => '2020-01-01', 'left' => '2026-05-10' ) );
		$this->assertSame( array( 1, 0 ), RuntsRules::counts_on( $m, '2026-05-09' ) );
		$this->assertSame( array( 0, 0 ), RuntsRules::counts_on( $m, '2026-05-10' ) );
	}

	public function test_minors_never_vote(): void {
		$this->assertTrue( VotingRight::decide( true, '1990-01-01', '2025-12-31' ) );
		$this->assertFalse( VotingRight::decide( true, '2012-06-01', '2025-12-31' ), 'minorenne' );
		$this->assertTrue( VotingRight::decide( true, '2007-12-31', '2025-12-31' ), '18 anni compiuti proprio il 31 dicembre' );
		$this->assertTrue( VotingRight::decide( true, '2007-12-30', '2025-12-31' ), '18 anni il giorno prima: vota' );
		$this->assertFalse( VotingRight::decide( true, '2008-01-01', '2025-12-31' ), '17 anni al 31 dicembre' );
		$this->assertFalse( VotingRight::decide( false, '1990-01-01', '2025-12-31' ), 'tipo di socio che non vota' );
		$this->assertTrue( VotingRight::decide( true, null, '2025-12-31' ), 'senza data di nascita si lascia il voto, come prima' );
	}

	public function test_voting_types_are_cleaned(): void {
		$this->assertSame( array( 'founder', 'ordinary', 'volunteer' ), VotingRight::clean_types( array() ) );
		$this->assertSame( array( 'founder', 'ordinary', 'volunteer' ), VotingRight::clean_types( array( 'guest', 'xx' ) ) );
		$this->assertSame( array( 'ordinary' ), VotingRight::clean_types( array( 'ordinary', 'guest' ) ) );
	}

	public function test_kind_defaults_to_person(): void {
		$this->assertSame( 'entity', RuntsRules::kind( 'entity' ) );
		$this->assertSame( 'person', RuntsRules::kind( '' ) );
		$this->assertSame( 'person', RuntsRules::kind( 'altro' ) );
	}

	public function test_entity_tax_code_check(): void {
		$this->assertSame( '', RuntsRules::entity_tax_code_error( '' ) );
		$this->assertSame( '', RuntsRules::entity_tax_code_error( '12345678903' ) );
		$this->assertNotSame( '', RuntsRules::entity_tax_code_error( '12345678901' ) );
	}
}
