<?php
use AssociazioneSemplice\Calendar;
use PHPUnit\Framework\TestCase;

final class CalendarFeedTest extends TestCase {

	public function test_a_matching_etag_means_not_modified(): void {
		$this->assertTrue( Calendar::not_modified( '"abc"', 1000, '"abc"', '' ) );
		$this->assertTrue( Calendar::not_modified( '"abc"', 1000, 'W/"abc"', '' ), 'ETag debole' );
		$this->assertTrue( Calendar::not_modified( '"abc"', 1000, '"zzz", "abc"', '' ), 'elenco di ETag' );
		$this->assertTrue( Calendar::not_modified( '"abc"', 1000, '*', '' ) );
	}

	public function test_a_different_etag_means_a_new_file(): void {
		$this->assertFalse( Calendar::not_modified( '"abc"', 1000, '"old"', '' ) );
		$this->assertFalse( Calendar::not_modified( '"abc"', 1000, '"old"', gmdate( 'D, d M Y H:i:s', 5000 ) . ' GMT' ), 'con un ETag vale solo quello' );
		$this->assertFalse( Calendar::not_modified( '', 1000, '"abc"', '' ), 'chi non ha un ETag non risponde mai «non modificato»' );
	}

	public function test_the_modification_date_is_used_when_there_is_no_etag(): void {
		$this->assertTrue( Calendar::not_modified( '"abc"', 1000, '', gmdate( 'D, d M Y H:i:s', 1000 ) . ' GMT' ) );
		$this->assertTrue( Calendar::not_modified( '"abc"', 1000, '', gmdate( 'D, d M Y H:i:s', 2000 ) . ' GMT' ) );
		$this->assertFalse( Calendar::not_modified( '"abc"', 1000, '', gmdate( 'D, d M Y H:i:s', 999 ) . ' GMT' ) );
		$this->assertFalse( Calendar::not_modified( '"abc"', 1000, '', 'non è una data' ) );
		$this->assertFalse( Calendar::not_modified( '"abc"', 1000, '', '' ) );
	}
}
