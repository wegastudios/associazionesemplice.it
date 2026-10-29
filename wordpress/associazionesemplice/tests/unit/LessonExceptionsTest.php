<?php
use AssociazioneSemplice\LessonExceptions;
use PHPUnit\Framework\TestCase;

final class LessonExceptionsTest extends TestCase {

	public function test_cancelled_date_disappears(): void {
		$ex = array( array( 'lesson_date' => '2026-12-24', 'action' => 'cancel', 'new_date' => null ) );
		$this->assertSame( array( '2026-12-17', '2026-12-31' ), LessonExceptions::adjust_dates( array( '2026-12-17', '2026-12-24', '2026-12-31' ), $ex, '2026-12-01', '2026-12-31' ) );
	}

	public function test_moved_date_appears_in_the_new_day(): void {
		$ex = array( array( 'lesson_date' => '2026-12-24', 'action' => 'move', 'new_date' => '2026-12-23' ) );
		$this->assertSame( array( '2026-12-17', '2026-12-23', '2026-12-31' ), LessonExceptions::adjust_dates( array( '2026-12-17', '2026-12-24', '2026-12-31' ), $ex, '2026-12-01', '2026-12-31' ) );
	}

	public function test_move_outside_the_period_is_not_added(): void {
		$ex = array( array( 'lesson_date' => '2026-12-24', 'action' => 'move', 'new_date' => '2027-01-02' ) );
		$this->assertSame( array( '2026-12-17' ), LessonExceptions::adjust_dates( array( '2026-12-17', '2026-12-24' ), $ex, '2026-12-01', '2026-12-31' ) );
	}

	public function test_moved_in_from_outside_the_period_appears(): void {
		$ex = array( array( 'lesson_date' => '2026-11-26', 'action' => 'move', 'new_date' => '2026-12-02' ) );
		$this->assertSame( array( '2026-12-02', '2026-12-10' ), LessonExceptions::adjust_dates( array( '2026-12-10' ), $ex, '2026-12-01', '2026-12-31' ) );
	}
}
