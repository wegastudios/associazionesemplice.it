<?php
use AssociazioneSemplice\DocumentRules;
use AssociazioneSemplice\LoanRules;
use AssociazioneSemplice\PollRules;
use PHPUnit\Framework\TestCase;

final class BlockBRulesTest extends TestCase {

	public function test_poll_window(): void {
		$o = '2026-05-01 09:00:00';
		$c = '2026-05-10 18:00:00';
		$this->assertSame( PollRules::UPCOMING, PollRules::status( $o, $c, '2026-04-30 23:59:59' ) );
		$this->assertSame( PollRules::OPEN, PollRules::status( $o, $c, $o ) );
		$this->assertSame( PollRules::OPEN, PollRules::status( $o, $c, '2026-05-10 17:59:59' ) );
		$this->assertSame( PollRules::CLOSED, PollRules::status( $o, $c, $c ) );
		$this->assertNull( PollRules::check_window( $o, $c ) );
		$this->assertNotNull( PollRules::check_window( $c, $o ) );
	}

	public function test_parse_local(): void {
		$this->assertSame( '2026-05-01 09:30:00', PollRules::parse_local( '2026-05-01T09:30' ) );
		$this->assertNull( PollRules::parse_local( '2026-02-30T09:30' ) );
		$this->assertNull( PollRules::parse_local( '2026-05-01T25:00' ) );
		$this->assertNull( PollRules::parse_local( '' ) );
		$this->assertSame( '2026-05-01T09:30', PollRules::to_local( '2026-05-01 09:30:00' ) );
	}

	public function test_options(): void {
		$this->assertSame( array( 'Sì', 'no' ), PollRules::normalize_options( array( ' Sì ', 'no', '', 'NO', 'No' ) ) );
		$this->expectException( \InvalidArgumentException::class );
		PollRules::normalize_options( array( 'Solo una', 'solo una' ) );
	}

	public function test_eligibility(): void {
		$this->assertTrue( PollRules::is_eligible( 'ordinary', true, true ) );
		$this->assertFalse( PollRules::is_eligible( 'guardian', true, true ) );
		$this->assertFalse( PollRules::is_eligible( 'guest', true, true ) );
		$this->assertFalse( PollRules::is_eligible( 'ordinary', false, true ) ); // sospeso, uscito o tessera scaduta
		$this->assertFalse( PollRules::is_eligible( 'ordinary', true, false ) );
	}

	public function test_one_vote_and_reasons(): void {
		$this->assertSame( '', PollRules::cannot_vote_reason( PollRules::OPEN, true, false ) );
		$this->assertSame( 'Hai già votato.', PollRules::cannot_vote_reason( PollRules::OPEN, true, true ) );
		$this->assertNotSame( '', PollRules::cannot_vote_reason( PollRules::CLOSED, true, false ) );
		$this->assertNotSame( '', PollRules::cannot_vote_reason( PollRules::UPCOMING, true, false ) );
		$this->assertNotSame( '', PollRules::cannot_vote_reason( PollRules::OPEN, false, false ) );
	}

	public function test_choices(): void {
		$this->assertSame( array( 2 ), PollRules::check_choices( false, 0, array( 2, 2 ), array( 1, 2, 3 ) ) );
		$this->assertSame( array( 1, 3 ), PollRules::check_choices( true, 2, array( 1, 3 ), array( 1, 2, 3 ) ) );
		foreach ( array(
			array( false, 0, array( 1, 2 ) ),
			array( true, 1, array( 1, 2 ) ),
			array( false, 0, array() ),
			array( false, 0, array( 9 ) ),
		) as $case ) {
			try {
				PollRules::check_choices( $case[0], $case[1], $case[2], array( 1, 2, 3 ) );
				$this->fail( 'scelta non valida accettata' );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertNotSame( '', $e->getMessage() );
			}
		}
	}

	public function test_secret_ballot_has_no_person(): void {
		$secret = PollRules::ballot_rows( 5, true, 77, array( 1, 2 ) );
		$open   = PollRules::ballot_rows( 5, false, 77, array( 1 ) );
		$this->assertSame( array(), $secret ); // nel voto segreto nessuna scheda: solo i contatori delle opzioni
		$this->assertSame( 77, $open[0]['person_id'] );
	}

	public function test_tally_and_visibility(): void {
		$t = PollRules::tally( array( 1, 2, 3 ), array( 1, 1, 2, 9 ) );
		$this->assertSame( array( 1 => 2, 2 => 1, 3 => 0 ), $t );
		$this->assertSame( array( 1 ), PollRules::winners( $t ) );
		$this->assertSame( array(), PollRules::winners( array( 1 => 0 ) ) );
		$this->assertSame( 67, PollRules::percent( 2, 3 ) );
		$this->assertSame( 0, PollRules::percent( 0, 0 ) );
		$this->assertTrue( PollRules::results_visible( true, false, PollRules::OPEN ) );
		$this->assertFalse( PollRules::results_visible( false, true, PollRules::OPEN ) );
		$this->assertTrue( PollRules::results_visible( false, true, PollRules::CLOSED ) );
		$this->assertFalse( PollRules::results_visible( false, false, PollRules::CLOSED ) );
	}

	public function test_loan_overdue(): void {
		$this->assertFalse( LoanRules::is_overdue( '2026-05-10', null, '2026-05-10' ) );
		$this->assertTrue( LoanRules::is_overdue( '2026-05-10', null, '2026-05-11' ) );
		$this->assertFalse( LoanRules::is_overdue( '2026-05-10', '2026-05-12', '2026-06-01' ) );
		$this->assertSame( LoanRules::OVERDUE, LoanRules::status( '2026-05-10', null, '2026-05-20' ) );
		$this->assertSame( LoanRules::RETURNED, LoanRules::status( '2026-05-10', '2026-05-09', '2026-05-20' ) );
		$this->assertSame( LoanRules::OUT, LoanRules::status( '2026-05-10', null, '2026-05-01' ) );
		$this->assertSame( 10, LoanRules::days_late( '2026-05-10', null, '2026-05-20' ) );
		$this->assertSame( 0, LoanRules::days_late( '2026-05-10', '2026-05-12', '2026-05-20' ) );
	}

	public function test_loan_dates_and_quantity(): void {
		$this->assertNull( LoanRules::check_dates( '2026-05-01', '2026-05-01' ) );
		$this->assertNotNull( LoanRules::check_dates( '2026-05-02', '2026-05-01' ) );
		$this->assertNotNull( LoanRules::check_dates( '2026-02-31', '2026-05-01' ) );
		$this->assertNotNull( LoanRules::check_return( '2026-05-02', '2026-05-01' ) );
		$this->assertSame( 2, LoanRules::available( 5, 3 ) );
		$this->assertSame( 0, LoanRules::available( 1, 4 ) );
		$this->assertNull( LoanRules::check_quantity( 2, 2, 'good' ) );
		$this->assertNotNull( LoanRules::check_quantity( 3, 2, 'good' ) );
		$this->assertNotNull( LoanRules::check_quantity( 1, 1, 'broken' ) );
		$this->assertNotNull( LoanRules::check_quantity( 0, 1, 'good' ) );
	}

	public function test_documents(): void {
		$this->assertTrue( DocumentRules::can_read( 'public', false, false ) );
		$this->assertTrue( DocumentRules::can_read( 'members', false, true ) );
		$this->assertFalse( DocumentRules::can_read( 'members', false, false ) );
		$this->assertFalse( DocumentRules::can_read( 'staff', false, true ) );
		$this->assertTrue( DocumentRules::can_read( 'staff', true, false ) );
		$this->assertNull( DocumentRules::check( 'statuto.pdf', 1000, 'application/pdf' ) );
		$this->assertNull( DocumentRules::check( 'modulo.docx', 1000, 'application/zip' ) );
		$this->assertNotNull( DocumentRules::check( 'evil.php', 1000, 'text/x-php' ) );
		$this->assertNotNull( DocumentRules::check( 'foto.jpg', 1000, 'text/html' ) );
		$this->assertNotNull( DocumentRules::check( 'a.pdf', 0, 'application/pdf' ) );
		$this->assertNotNull( DocumentRules::check( 'a.pdf', DocumentRules::MAX_BYTES + 1, 'application/pdf' ) );
		$this->assertSame( 'Statuto 2026.pdf', DocumentRules::download_name( 'Statuto 2026', 'x.PDF' ) );
		$this->assertTrue( DocumentRules::is_inline( 'pdf' ) );
		$this->assertFalse( DocumentRules::is_inline( 'docx' ) );
	}
}
