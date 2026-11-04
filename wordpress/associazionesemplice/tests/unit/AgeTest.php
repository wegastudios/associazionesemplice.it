<?php
use AssociazioneSemplice\Age;
use PHPUnit\Framework\TestCase;

final class AgeTest extends TestCase {

	public function test_dates_are_validated(): void {
		$this->assertTrue( Age::is_valid_date( '2010-02-28' ) );
		$this->assertFalse( Age::is_valid_date( '2010-02-30' ) );
		$this->assertFalse( Age::is_valid_date( '2010-13-01' ) );
		$this->assertFalse( Age::is_valid_date( '' ) );
		$this->assertFalse( Age::is_valid_date( '1850-01-01' ), 'troppo vecchia' );
	}

	public function test_parse_accepts_italian_and_iso_formats(): void {
		$this->assertSame( '2010-12-31', Age::parse( '31/12/2010' ) );
		$this->assertSame( '2010-12-31', Age::parse( '31-12-2010' ) );
		$this->assertSame( '2010-12-31', Age::parse( '31.12.2010' ) );
		$this->assertSame( '2010-12-31', Age::parse( '2010-12-31' ) );
		$this->assertSame( '2010-01-05', Age::parse( '5/1/2010' ) );
		$this->assertNull( Age::parse( '32/12/2010' ) );
		$this->assertNull( Age::parse( 'ieri' ) );
		$this->assertNull( Age::parse( '' ) );
	}

	public function test_years_are_whole_years_on_the_day(): void {
		$this->assertSame( 17, Age::years( '2008-06-15', '2026-06-14' ) );
		$this->assertSame( 18, Age::years( '2008-06-15', '2026-06-15' ) );
		$this->assertSame( 0, Age::years( '2026-01-01', '2026-06-15' ) );
		$this->assertNull( Age::years( '2030-01-01', '2026-06-15' ), 'nata nel futuro' );
		$this->assertNull( Age::years( null, '2026-06-15' ) );
		$this->assertNull( Age::years( 'boh', '2026-06-15' ) );
	}

	public function test_minor_until_the_eighteenth_birthday(): void {
		$this->assertTrue( Age::is_minor( '2008-06-15', '2026-06-14' ) );
		$this->assertFalse( Age::is_minor( '2008-06-15', '2026-06-15' ) );
		$this->assertFalse( Age::is_minor( null, '2026-06-15' ), 'senza data non si sa: non è trattato da minore' );
		$this->assertFalse( Age::is_minor( '', '2026-06-15' ) );
	}

	public function test_adult_on_handles_leap_day(): void {
		$this->assertSame( '2026-06-15', Age::adult_on( '2008-06-15' ) );
		$this->assertSame( '2026-03-01', Age::adult_on( '2008-02-29' ), '29 febbraio: il 1 marzo se l anno non è bisestile' );
		$this->assertNull( Age::adult_on( 'x' ) );
	}

	public function test_became_adult_within_a_window(): void {
		$this->assertTrue( Age::became_adult_within( '2008-06-15', '2026-06-20', 30 ) );
		$this->assertTrue( Age::became_adult_within( '2008-06-15', '2026-06-15', 0 ) );
		$this->assertFalse( Age::became_adult_within( '2008-06-15', '2026-08-20', 30 ), 'più di 30 giorni fa' );
		$this->assertFalse( Age::became_adult_within( '2008-06-15', '2026-06-10', 30 ), 'non ancora' );
		$this->assertFalse( Age::became_adult_within( null, '2026-06-10', 30 ) );
	}

	public function test_display(): void {
		$this->assertSame( '05/01/2010', Age::display( '2010-01-05' ) );
		$this->assertSame( '', Age::display( null ) );
		$this->assertSame( '', Age::display( 'boh' ) );
	}
}
