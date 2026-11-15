<?php
use AssociazioneSemplice\Contacts;
use PHPUnit\Framework\TestCase;

final class ContactsTest extends TestCase {

	public function test_phone_validity(): void {
		foreach ( array( '011 1234567', '+39 333 1234567', '(011) 123-45.67', '123456' ) as $ok ) {
			$this->assertTrue( Contacts::phone_valid( $ok ), $ok );
		}
		foreach ( array( '', '12345', 'abc', '011 abc 123456', str_repeat( '1', 31 ), '123456; DROP' ) as $bad ) {
			$this->assertFalse( Contacts::phone_valid( $bad ), $bad );
		}
	}
}
