<?php
use AssociazioneSemplice\RuntsRules;
use AssociazioneSemplice\VotingRight;
use PHPUnit\Framework\TestCase;

/** Regole pure dei dati per il RUNTS e del diritto di voto: date, minimo di soci, scadenze. */
final class RuntsRulesTest extends TestCase {

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

	/** Il conteggio come lo fa il criterio unico del libro soci: tessera valida quel giorno, non cessato prima. @param array[] $members kind, same, left, cards */
	private function counter( array $members ): callable {
		return function ( string $d ) use ( $members ): array {
			$p = 0;
			$e = 0;
			foreach ( $members as $m ) {
				if ( '' !== (string) ( $m['left'] ?? '' ) && (string) $m['left'] < $d ) {
					continue;
				}
				$ok = false;
				foreach ( $m['cards'] as $c ) {
					$ok = $ok || ( $c[0] <= $d && $c[1] >= $d );
				}
				if ( ! $ok ) {
					continue;
				}
				if ( 'entity' === ( $m['kind'] ?? 'person' ) ) {
					$e += ! empty( $m['same'] ) ? 1 : 0;
				} else {
					++$p;
				}
			}
			return array( $p, $e );
		};
	}

	private function members( int $n, array $cards, string $left = '' ): array {
		return array_fill( 0, $n, array( 'kind' => 'person', 'same' => false, 'joined' => '2019-01-01', 'left' => $left, 'cards' => array( $cards ) ) );
	}

	public function test_below_since_is_the_day_after_the_last_card_that_kept_us_above(): void {
		$m   = $this->members( 6, array( '2019-01-01', '2026-12-31' ) ); // sei soci con la tessera valida tutto l'anno...
		$m[] = array( 'kind' => 'person', 'same' => false, 'joined' => '2019-01-01', 'left' => '', 'cards' => array( array( '2019-01-01', '2026-09-30' ) ) ); // ...e un settimo la cui tessera scade il 30/9
		$dates = RuntsRules::change_dates( $m, '2026-11-13' );
		$this->assertSame( '2026-10-01', RuntsRules::below_since( $dates, $this->counter( $m ) ), 'sotto dal giorno dopo la scadenza, non dalla cessazione' );
		$this->assertSame( '2026-10-31', RuntsRules::notice_deadline( '2026-10-01' ) );
	}

	public function test_below_since_is_null_when_not_below_or_since_always(): void {
		$seven = $this->members( 7, array( '2019-01-01', '2026-12-31' ) );
		$this->assertNull( RuntsRules::below_since( RuntsRules::change_dates( $seven, '2026-11-13' ), $this->counter( $seven ) ) );
		$three = $this->members( 3, array( '2019-01-01', '2026-12-31' ) );
		$this->assertNull( RuntsRules::below_since( RuntsRules::change_dates( $three, '2026-11-13' ), $this->counter( $three ) ), 'sotto da sempre: non si sa da quando' );
	}

	public function test_below_since_restarts_after_going_back_above(): void {
		$m   = $this->members( 7, array( '2019-01-01', '2026-12-31' ) );
		$m[0]['cards'] = array( array( '2019-01-01', '2026-02-28' ) ); // tessera scaduta il 28/2: sotto dal 1/3...
		$m[]           = array( 'kind' => 'person', 'same' => false, 'joined' => '2026-04-01', 'left' => '', 'cards' => array( array( '2026-04-01', '2026-12-31' ) ) ); // ...di nuovo sette dal 1/4
		$m[1]['left']  = '2026-09-14'; // ...e di nuovo sotto dal 15/9 (cessato il 14: l'ultimo giorno conta ancora)
		$dates = RuntsRules::change_dates( $m, '2026-11-13' );
		$this->assertSame( '2026-09-15', RuntsRules::below_since( $dates, $this->counter( $m ) ) );
	}

	public function test_a_renewal_brings_the_member_back_into_the_count(): void {
		$m               = $this->members( 7, array( '2019-01-01', '2026-12-31' ) );
		$m[0]['cards']   = array( array( '2019-01-01', '2025-12-31' ), array( '2026-01-20', '2026-12-31' ) ); // scaduta a fine anno e rinnovata il 20/1
		$dates           = RuntsRules::change_dates( $m, '2026-01-10' );
		$this->assertSame( array( 6, 0 ), $this->counter( $m )( '2026-01-10' ), 'a gennaio, prima del rinnovo, la tessera scaduta non conta' );
		$this->assertSame( '2026-01-01', RuntsRules::below_since( $dates, $this->counter( $m ) ) );
		$this->assertSame( array( 7, 0 ), $this->counter( $m )( '2026-01-20' ) );
	}

	public function test_entities_of_the_same_section_make_the_minimum(): void {
		$m = $this->members( 2, array( '2019-01-01', '2026-12-31' ) );
		for ( $i = 0; $i < 3; $i++ ) {
			$m[] = array( 'kind' => 'entity', 'same' => true, 'joined' => '2019-01-01', 'left' => '', 'cards' => array( array( '2019-01-01', '2026-12-31' ) ) );
		}
		$this->assertSame( array( 2, 3 ), $this->counter( $m )( '2026-11-13' ) );
		$this->assertNull( RuntsRules::below_since( RuntsRules::change_dates( $m, '2026-11-13' ), $this->counter( $m ) ) );
		$m[2]['same'] = false; // un ente non della stessa sezione non vale
		$this->assertSame( array( 2, 2 ), $this->counter( $m )( '2026-11-13' ) );
	}

	public function test_change_dates_include_card_edges_and_today_only(): void {
		$m = array( array( 'joined' => '2020-01-01', 'left' => '2026-05-10', 'cards' => array( array( '2025-01-01', '2025-12-31' ) ) ), array( 'joined' => '2030-01-01', 'left' => '', 'cards' => array() ) );
		$this->assertSame( array( '2020-01-01', '2025-01-01', '2026-01-01', '2026-05-10', '2026-05-11', '2026-11-13' ), RuntsRules::change_dates( $m, '2026-11-13' ), 'solo date fino a oggi, giorno dopo la fine della tessera e della cessazione compresi' );
	}

	public function test_recently_expired_cards(): void {
		$today = '2026-01-15';
		$this->assertTrue( RuntsRules::recently_expired( array( array( '2025-01-01', '2025-12-31' ) ), '', $today ), 'scaduta da due settimane' );
		$this->assertFalse( RuntsRules::recently_expired( array( array( '2024-01-01', '2024-12-31' ) ), '', $today ), 'scaduta da più di tre mesi' );
		$this->assertFalse( RuntsRules::recently_expired( array( array( '2025-01-01', '2026-12-31' ) ), '', $today ), 'tessera valida: non è scaduta' );
		$this->assertFalse( RuntsRules::recently_expired( array( array( '2025-01-01', '2025-12-31' ) ), '2025-12-01', $today ), 'cessato davvero: non si aspetta il rinnovo' );
		$this->assertFalse( RuntsRules::recently_expired( array(), '', $today ), 'mai avuto una tessera' );
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
