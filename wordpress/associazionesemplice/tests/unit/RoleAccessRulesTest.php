<?php
use AssociazioneSemplice\RoleAccessRules;
use PHPUnit\Framework\TestCase;

final class RoleAccessRulesTest extends TestCase {

	public function test_a_plain_role_the_operator_does_not_have_can_lose_access(): void {
		$this->assertNull( RoleAccessRules::refuse_removal( 'editor', array( 'administrator' ) ) );
		$this->assertNull( RoleAccessRules::refuse_removal( 'editor', array() ) );
	}

	public function test_the_role_of_the_operator_cannot_lose_access(): void {
		$this->assertSame( RoleAccessRules::REFUSE_OWN, RoleAccessRules::refuse_removal( 'editor', array( 'editor' ) ) );
		$this->assertSame( RoleAccessRules::REFUSE_OWN, RoleAccessRules::refuse_removal( 'editor', array( 'author', 'editor' ) ) );
	}

	public function test_a_wordpress_administrator_is_never_removed_or_limited(): void {
		$this->assertSame( RoleAccessRules::REFUSE_WP_ADMIN, RoleAccessRules::refuse_removal( 'administrator', array( 'administrator' ) ) );
		$this->assertSame( RoleAccessRules::REFUSE_WP_ADMIN, RoleAccessRules::refuse_removal( 'administrator', array( 'editor' ) ) );
		$this->assertSame( RoleAccessRules::REFUSE_WP_ADMIN, RoleAccessRules::refuse_removal( 'administrator', array() ) );
	}

	public function test_an_association_administrator_role_can_be_removed_by_another_administrator_even_if_new(): void {
		// un ruolo del plugin con il permesso di amministrare, che non è «administrator»: lo toglie un altro amministratore
		$this->assertNull( RoleAccessRules::refuse_removal( 'amministratore_associazione', array( 'administrator' ) ) );
		$this->assertNull( RoleAccessRules::refuse_removal( 'amministratore_associazione', array( 'editor' ) ) );
	}

	public function test_nobody_removes_their_own_role_including_an_association_administrator(): void {
		$this->assertSame( RoleAccessRules::REFUSE_OWN, RoleAccessRules::refuse_removal( 'amministratore_associazione', array( 'amministratore_associazione' ) ) );
	}
}
