<?php
use AssociazioneSemplice\WpRoleCleanup;
use PHPUnit\Framework\TestCase;

final class WpRoleCleanupTest extends TestCase {

	public function test_only_administrator_and_the_three_internal_roles_are_left_alone(): void {
		$this->assertSame( array( 'administrator', 'asem_member', 'asem_secretary', 'asem_employee' ), WpRoleCleanup::OWN_ROLES );
		$all = array(
			'administrator'  => array( 'asem_manage' => true, 'asem_operate' => true ),
			'asem_secretary' => array( 'read' => true, 'asem_operate' => true ),
			'asem_employee'  => array( 'read' => true, 'asem_operate' => true ),
			'asem_member'    => array( 'read' => true ),
			'editor'         => array( 'edit_posts' => true, 'asem_operate' => true ),
			'author'         => array( 'edit_posts' => true, 'asem_manage' => true ),
			'contributor'    => array( 'edit_posts' => true ),
			'subscriber'     => array( 'read' => true ),
			'shop_manager'   => array( 'read' => true, 'asem_operate' => false ),
		);
		$this->assertSame( array( 'editor', 'author', 'shop_manager' ), WpRoleCleanup::roles_to_clean( $all ) );
	}

	public function test_a_second_pass_finds_nothing_to_clean(): void {
		$clean = array(
			'administrator' => array( 'asem_manage' => true, 'asem_operate' => true ),
			'editor'        => array( 'edit_posts' => true ),
			'author'        => array( 'edit_posts' => true ),
		);
		$this->assertSame( array(), WpRoleCleanup::roles_to_clean( $clean ) );
	}

	public function test_a_user_who_entered_only_through_a_cleaned_role_is_listed(): void {
		$this->assertTrue( WpRoleCleanup::entered_only_by_cleaned_role( array( 'editor' ), array( 'editor', 'author' ), false ) );
		$this->assertTrue( WpRoleCleanup::entered_only_by_cleaned_role( array( 'editor', 'author' ), array( 'editor', 'author' ), false ) );
		$this->assertTrue( WpRoleCleanup::entered_only_by_cleaned_role( array( 'editor', 'asem_member' ), array( 'editor' ), false ) );
	}

	public function test_administrators_internal_roles_and_the_board_are_never_listed(): void {
		$cleaned = array( 'editor' );
		$this->assertFalse( WpRoleCleanup::entered_only_by_cleaned_role( array( 'administrator', 'editor' ), $cleaned, false ) );
		$this->assertFalse( WpRoleCleanup::entered_only_by_cleaned_role( array( 'asem_secretary', 'editor' ), $cleaned, false ) );
		$this->assertFalse( WpRoleCleanup::entered_only_by_cleaned_role( array( 'asem_employee', 'editor' ), $cleaned, false ) );
		$this->assertFalse( WpRoleCleanup::entered_only_by_cleaned_role( array( 'editor' ), $cleaned, true ) );
	}

	public function test_a_user_without_a_cleaned_role_is_not_listed(): void {
		$this->assertFalse( WpRoleCleanup::entered_only_by_cleaned_role( array( 'subscriber' ), array( 'editor' ), false ) );
		$this->assertFalse( WpRoleCleanup::entered_only_by_cleaned_role( array(), array( 'editor' ), false ) );
	}
}
