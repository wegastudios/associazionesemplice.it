<?php

use AssociazioneSemplice\CourseCalendar;
use AssociazioneSemplice\LessonExceptions;
use AssociazioneSemplice\RedDays;
use PHPUnit\Framework\TestCase;

/** Giorni senza lezione: festività, Pasqua, patrono, chiusure. */
final class RedDaysTest extends TestCase {

	public function test_easter_on_known_years(): void {
		$known = array( 2000 => '04-23', 2008 => '03-23', 2011 => '04-24', 2016 => '03-27', 2019 => '04-21', 2024 => '03-31', 2025 => '04-20', 2026 => '04-05', 2027 => '03-28', 2028 => '04-16', 2030 => '04-21', 2038 => '04-25' );
		foreach ( $known as $y => $md ) {
			$this->assertSame( $y . '-' . $md, RedDays::easter( $y ) );
		}
	}

	public function test_national_days(): void {
		$this->assertCount( 12, RedDays::national( 2025 ) ); // 2025: il 4 ottobre non è ancora festivo
		$this->assertArrayNotHasKey( '2025-10-04', RedDays::national( 2025 ) );
		$this->assertArrayHasKey( '2026-10-04', RedDays::national( 2026 ) );
		$this->assertSame( 'Lunedì dell\'Angelo', RedDays::national( 2026 )['2026-04-06'] );
	}

	public function test_patron_and_closures(): void {
		$this->assertSame( '06-29', RedDays::clean_patron( '29/6' ) );
		$this->assertSame( '06-29', RedDays::clean_patron( '06-29' ) );
		$this->assertSame( '', RedDays::clean_patron( ' ' ) );
		$c = RedDays::parse_closures( "24/12/2026 - 06/01/2027 Vacanze <b>di Natale\n\n14/08/2026" );
		$this->assertSame( array( 'from' => '2026-12-24', 'to' => '2027-01-06', 'note' => 'Vacanze bdi Natale' ), $c[0] );
		$this->assertSame( $c, RedDays::parse_closures( RedDays::closures_text( $c ) ) );
		$r = RedDays::between( '2026-12-30', '2027-01-07', '01-03', $c );
		$this->assertSame( array( '2026-12-30', '2026-12-31', '2027-01-01', '2027-01-02', '2027-01-03', '2027-01-04', '2027-01-05', '2027-01-06' ), array_keys( $r ) );
		$this->assertSame( 'Capodanno', $r['2027-01-01'] );
		$this->assertSame( array(), RedDays::between( '2027-02-28', '2027-03-01', '02-29', array() ) ); // patrono il 29 febbraio in un anno normale
	}

	/** @dataProvider wrong */
	public function test_wrong_closures( string $text ): void {
		$this->expectException( \InvalidArgumentException::class );
		RedDays::parse_closures( $text );
	}

	public static function wrong(): array {
		return array( array( '31/02/2026' ), array( '10/01/2026 - 01/01/2026' ), array( 'Natale' ), array( '01/01/2026 - 01/01/2030' ) );
	}

	public function test_wrong_patron(): void {
		$this->expectException( \InvalidArgumentException::class );
		RedDays::clean_patron( '31/02' );
	}

	public function test_weekly_lessons_skip_red_days_and_single_dates_do_not(): void {
		$lessons = array( array( 'type' => 'weekly', 'day' => 5, 'from' => null, 'until' => null ), array( 'type' => 'single', 'date' => '2026-12-26' ) );
		$skip    = RedDays::between( '2026-12-01', '2026-12-31', '', array() );
		$this->assertSame( array( '2026-12-04', '2026-12-11', '2026-12-18', '2026-12-26' ), CourseCalendar::expand( $lessons, '2026-12-01', '2026-12-31', null, null, false, $skip ) ); // il 25 è venerdì
		$keep = array( array( 'lesson_date' => '2026-12-18', 'action' => LessonExceptions::KEEP, 'new_date' => null ) );
		$this->assertSame( array( '2026-12-18' ), LessonExceptions::adjust_dates( array( '2026-12-18' ), $keep, '2026-12-01', '2026-12-31' ) );
	}
}
