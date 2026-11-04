<?php
use AssociazioneSemplice\MemberPassword as P;
use PHPUnit\Framework\TestCase;

final class MemberPasswordTest extends TestCase {

	public function test_no_lock_before_the_fifth_mistake(): void {
		foreach ( array( 0, 1, 4 ) as $n ) {
			$this->assertSame( 0, P::lock_seconds( $n ) );
		}
	}

	public function test_lock_doubles_and_stops_at_one_hour(): void {
		$this->assertSame( 60, P::lock_seconds( 5 ) );
		$this->assertSame( 120, P::lock_seconds( 6 ) );
		$this->assertSame( 240, P::lock_seconds( 7 ) );
		$this->assertSame( 3600, P::lock_seconds( 20 ) );
		$this->assertSame( 3600, P::lock_seconds( 100000 ) );
	}
}
