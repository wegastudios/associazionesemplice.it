<?php
use AssociazioneSemplice\RoleAccessRules;
use PHPUnit\Framework\TestCase;

final class RoleAccessRulesTest extends TestCase {

	public function test_a_plain_role_the_operator_does_not_have_can_lose_access(): void {
		$this->assertNull( RoleAccessRules::refuse_removal( 'editor', false, array( 'administrator' ) ) );
		$this->assertNull( RoleAccessRules::refuse_removal( 'editor', false, array() ) );
	}

	public function test_the_role_of_the_operator_cannot_lose_access(): void {
		$this->assertSame( RoleAccessRules::REFUSE_OWN, RoleAccessRules::refuse_removal( 'editor', false, array( 'editor' ) ) );
		$this->assertSame( RoleAccessRules::REFUSE_OWN, RoleAccessRules::refuse_removal( 'editor', false, array( 'author', 'editor' ) ) );
	}

	public function test_a_role_that_manages_the_plugin_cannot_lose_access(): void {
		$this->assertSame( RoleAccessRules::REFUSE_MANAGER, RoleAccessRules::refuse_removal( 'editor', true, array( 'administrator' ) ) );
		$this->assertSame( RoleAccessRules::REFUSE_MANAGER, RoleAccessRules::refuse_removal( 'editor', true, array( 'editor' ) ) );
	}
}
