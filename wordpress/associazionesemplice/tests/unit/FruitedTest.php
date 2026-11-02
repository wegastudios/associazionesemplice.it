<?php
use AssociazioneSemplice\ActivityReset;
use PHPUnit\Framework\TestCase;

/** Cosa è «già fruito» quando si elimina un evento o un corso: rimborsi e annullamenti riguardano solo il resto. */
final class FruitedTest extends TestCase {

	private function ctx( array $held, array $sessions = array(), string $today = '2026-11-12', string $now = '10:00' ): array {
		return array( 'today' => $today, 'now' => $now, 'sessions' => $sessions, 'held' => $held );
	}

	public function test_course_month_already_held_is_fruited_and_the_next_is_not(): void {
		$ctx = $this->ctx( array( '2026-10-01', '2026-10-08', '2026-11-05' ) );
		$this->assertTrue( ActivityReset::is_fruited( array( 'competence_month' => '2026-10' ), $ctx ) );
		$this->assertTrue( ActivityReset::is_fruited( array( 'competence_month' => '2026-11' ), $ctx ), 'novembre: una lezione è già avvenuta' );
		$this->assertFalse( ActivityReset::is_fruited( array( 'competence_month' => '2026-12' ), $ctx ) );
	}

	public function test_month_without_any_held_lesson_is_not_fruited(): void {
		$ctx = $this->ctx( array( '2026-10-01' ) ); // novembre: tutte le lezioni annullate
		$this->assertFalse( ActivityReset::is_fruited( array( 'competence_month' => '2026-11' ), $ctx ) );
	}

	public function test_payment_linked_to_a_session_follows_that_date(): void {
		$ctx = $this->ctx( array(), array( 5 => array( 'date' => '2026-11-03', 'start' => '' ), 6 => array( 'date' => '2026-11-20', 'start' => '' ) ) );
		$this->assertTrue( ActivityReset::is_fruited( array( 'session_id' => 5 ), $ctx ) );
		$this->assertFalse( ActivityReset::is_fruited( array( 'session_id' => 6 ), $ctx ) );
		$this->assertFalse( ActivityReset::is_fruited( array( 'session_id' => 99 ), $ctx ), 'data annullata o sparita' );
	}

	public function test_session_today_counts_only_when_the_start_time_has_passed(): void {
		$s = array( 7 => array( 'date' => '2026-11-12', 'start' => '09:00' ), 8 => array( 'date' => '2026-11-12', 'start' => '21:00' ), 9 => array( 'date' => '2026-11-12', 'start' => '' ) );
		$ctx = $this->ctx( array(), $s );
		$this->assertTrue( ActivityReset::is_fruited( array( 'session_id' => 7 ), $ctx ) );
		$this->assertFalse( ActivityReset::is_fruited( array( 'session_id' => 8 ), $ctx ) );
		$this->assertFalse( ActivityReset::is_fruited( array( 'session_id' => 9 ), $ctx ), 'senza orario di inizio: non si dà per svolta' );
	}

	public function test_single_payment_without_date_or_month_depends_on_the_activity_having_started(): void {
		$this->assertTrue( ActivityReset::is_fruited( array(), $this->ctx( array( '2026-10-01' ) ) ) );
		$this->assertFalse( ActivityReset::is_fruited( array(), $this->ctx( array( '2026-12-01' ) ) ) );
		$this->assertFalse( ActivityReset::is_fruited( array(), $this->ctx( array() ) ) );
		$ev = $this->ctx( array( '2026-10-02' ), array( 1 => array( 'date' => '2026-10-02', 'start' => '' ) ) );
		$this->assertTrue( ActivityReset::is_fruited( array(), $ev ) );
	}
}
