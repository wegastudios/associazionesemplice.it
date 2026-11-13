<?php
use AssociazioneSemplice\MemberBook;
use AssociazioneSemplice\MembershipRules;
use AssociazioneSemplice\SocialYear;
use PHPUnit\Framework\TestCase;

/** La tessera segue l'anno sociale (stesso mese di inizio) e non dipende dall'esercizio contabile. */
final class MembershipRulesTest extends TestCase {

	public function test_calendar_card_is_unchanged_with_january(): void {
		$y = SocialYear::for_date( '2026-11-13', 1 );
		$this->assertSame( '2026', $y->label() );
		$this->assertSame( '2026-01-01', $y->start()->format( 'Y-m-d' ) );
		$this->assertSame( '2026-12-31', $y->end()->format( 'Y-m-d' ) );
		$this->assertSame( '2027', $y->next()->label() );
	}

	public function test_september_card_runs_september_to_august(): void {
		$y = SocialYear::for_date( '2026-11-13', 9 );
		$this->assertSame( '2026/2027', $y->label() );
		$this->assertSame( '2026-09-01', $y->start()->format( 'Y-m-d' ) );
		$this->assertSame( '2027-08-31', $y->end()->format( 'Y-m-d' ) );
		$this->assertSame( '2025/2026', SocialYear::for_date( '2026-08-31', 9 )->label() );
		$this->assertSame( '2026/2027', SocialYear::for_date( '2026-09-01', 9 )->label() );
	}

	public function test_july_card_runs_july_to_june(): void {
		$this->assertSame( '2025/2026', SocialYear::for_date( '2026-06-30', 7 )->label() );
		$this->assertSame( '2026/2027', SocialYear::for_date( '2026-07-01', 7 )->label() );
		$this->assertSame( '2027-06-30', SocialYear::for_date( '2026-07-01', 7 )->end()->format( 'Y-m-d' ) );
	}

	public function test_renewal_opens_in_last_days_of_card(): void {
		$sol = SocialYear::for_date( '2026-12-01', 1 );
		$this->assertTrue( MembershipRules::renewal_open( $sol, '2026-12-01', 30 ) );
		$this->assertFalse( MembershipRules::renewal_open( $sol, '2026-11-30', 30 ) );
		$this->assertTrue( MembershipRules::renewal_open( $sol, '2026-12-31', 30 ) );
		$sep = SocialYear::for_date( '2027-08-01', 9 );
		$this->assertTrue( MembershipRules::renewal_open( $sep, '2027-08-01', 30 ) );
		$this->assertFalse( MembershipRules::renewal_open( $sep, '2027-07-31', 30 ) );
		$this->assertFalse( MembershipRules::renewal_open( SocialYear::for_date( '2026-11-13', 9 ), '2026-11-13', 30 ), 'a metà anno nessun rinnovo anticipato' );
	}

	public function test_plan_outside_renewal_is_current_year(): void {
		$cur = SocialYear::for_date( '2026-11-13', 9 );
		$this->assertSame( array( 'year' => '2026/2027', 'free' => null ), MembershipRules::plan( $cur, false, true, true ) );
		$this->assertSame( array( 'year' => '2026/2027', 'free' => null ), MembershipRules::plan( $cur, false, false, false ) );
	}

	public function test_plan_in_renewal_window(): void {
		$cur = SocialYear::for_date( '2027-08-15', 9 );
		$this->assertSame( '2026/2027', $cur->label() );
		$this->assertSame( array( 'year' => '2027/2028', 'free' => null ), MembershipRules::plan( $cur, true, true, true ), 'chi ha l\'anno in corso rinnova in anticipo' );
		$this->assertSame( array( 'year' => '2027/2028', 'free' => '2026/2027' ), MembershipRules::plan( $cur, true, false, false ), 'il nuovo socio paga l\'anno dopo e ha quello in corso in omaggio' );
		$this->assertSame( array( 'year' => '2026/2027', 'free' => null ), MembershipRules::plan( $cur, true, false, true ), 'chi non ha rinnovato paga prima quello in corso' );
		$sol = SocialYear::for_date( '2026-12-20', 1 );
		$this->assertSame( array( 'year' => '2027', 'free' => null ), MembershipRules::plan( $sol, true, true, true ), 'anno solare: etichette senza barra come prima' );
		$this->assertSame( array( 'year' => '2027', 'free' => '2026' ), MembershipRules::plan( $sol, true, false, false ) );
	}

	public function test_label_uses_month_in_force(): void {
		$this->assertSame( '2026', MembershipRules::from_label( '2026', 9 )->label(), 'senza barra resta l\'anno solare: le tessere già emesse non si spostano' );
		$this->assertSame( '2026-12-31', MembershipRules::from_label( '2026', 9 )->end()->format( 'Y-m-d' ) );
		$this->assertSame( '2026/2027', MembershipRules::from_label( '2026/2027', 9 )->label() );
		$this->assertSame( '2027-08-31', MembershipRules::from_label( '2026/2027', 9 )->end()->format( 'Y-m-d' ) );
		$this->assertSame( '2026-12-31', MembershipRules::from_label( '2026', 1 )->end()->format( 'Y-m-d' ) );
	}

	public function test_import_year_columns_follow_the_social_year(): void {
		$this->assertSame( '2025/2026', MembershipRules::import_label( '2025', 9 ) );
		$this->assertSame( '2025/2026', MembershipRules::import_label( '2025', 7 ) );
		$this->assertSame( '2025', MembershipRules::import_label( '2025', 1 ) );
		$this->assertSame( '2025/2026', MembershipRules::import_label( '2025/2026', 9 ) );
	}

	public function test_month_change_never_mixes_cards_with_the_same_label(): void {
		$cards = array( array( 'valid_from' => '2026-09-01', 'valid_to' => '2027-08-31' ) );
		$this->assertTrue( MembershipRules::month_change_conflicts( $cards, 7, '2026-11-13' ), 'da settembre a luglio con tessere in corso: confonderebbe le etichette' );
		$this->assertFalse( MembershipRules::month_change_conflicts( $cards, 9, '2026-11-13' ), 'stesso mese: nessun conflitto' );
		$this->assertFalse( MembershipRules::month_change_conflicts( $cards, 1, '2026-11-13' ), 'gennaio ha etichette senza barra' );
		$this->assertFalse( MembershipRules::month_change_conflicts( array( array( 'valid_from' => '2025-09-01', 'valid_to' => '2026-08-31' ) ), 7, '2026-11-13' ), 'tessere scadute non contano' );
		$this->assertFalse( MembershipRules::month_change_conflicts( array(), 7, '2026-11-13' ) );
	}

	public function test_card_across_two_financial_years_is_in_both_books(): void {
		$p     = array( 'type' => 'ordinary', 'joined_on' => '2020-01-01', 'left_on' => '' );
		$cards = array( array( 'valid_from' => '2026-09-01', 'valid_to' => '2027-08-31' ) );
		$this->assertTrue( MemberBook::qualifies( $p, $cards, '2026-01-01', '2026-12-31' ) );
		$this->assertTrue( MemberBook::qualifies( $p, $cards, '2027-01-01', '2027-12-31' ) );
		$this->assertFalse( MemberBook::qualifies( $p, $cards, '2025-01-01', '2025-12-31' ) );
		$this->assertFalse( MemberBook::qualifies( $p, $cards, '2028-01-01', '2028-12-31' ) );
		$this->assertTrue( MemberBook::qualifies( $p, $cards, '2026-07-01', '2027-06-30' ), 'esercizio a luglio: una sola volta in quello che la contiene' );
	}

	public function test_both_start_months_begin_in_january(): void {
		$d = \AssociazioneSemplice\Settings::defaults();
		$this->assertSame( 1, (int) $d['social_year_start_month'], 'anno sociale: di partenza gennaio (tessera = anno solare)' );
		$this->assertSame( 1, (int) $d['fiscal_start_month'], 'anno fiscale: di partenza gennaio' );
		$this->assertSame( '2026', SocialYear::for_date( '2026-11-13', (int) $d['social_year_start_month'] )->label(), 'con il valore di partenza la tessera è l\'anno solare' );
	}
}
