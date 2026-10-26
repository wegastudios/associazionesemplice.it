<?php
use AssociazioneSemplice\MemberType;
use PHPUnit\Framework\TestCase;

final class MemberTypeGuardianTest extends TestCase {

	public function test_guardian_can_log_in_but_is_not_a_member(): void {
		$this->assertTrue( MemberType::can_login( MemberType::GUARDIAN ) );
		$this->assertFalse( MemberType::is_member( MemberType::GUARDIAN ) );
		$this->assertFalse( MemberType::is_auto_renewed( MemberType::GUARDIAN ) );
		$this->assertTrue( MemberType::is_valid( MemberType::GUARDIAN ) );
		$this->assertFalse( MemberType::can_login( MemberType::GUEST ) );
		foreach ( MemberType::member_types() as $t ) {
			$this->assertTrue( MemberType::can_login( $t ), $t );
		}
	}

	public function test_guardian_is_not_among_the_member_types(): void {
		$this->assertNotContains( MemberType::GUARDIAN, MemberType::member_types() );
	}
}
