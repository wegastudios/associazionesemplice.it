<?php
use AssociazioneSemplice\MemberMigration as M;
use PHPUnit\Framework\TestCase;

final class MemberMigrationTest extends TestCase {

	private function f( array $over = array() ): array {
		return array_merge( array( 'roles' => array( 'asem_member' ), 'power' => false, 'email' => 'a@example.org', 'allowed' => true ), $over );
	}

	public function test_plain_member_can_move(): void {
		$this->assertSame( M::MIGRATE, M::classify( $this->f() ) );
		$this->assertSame( M::MIGRATE, M::classify( $this->f( array( 'roles' => array( 'subscriber' ) ) ) ) );
	}

	public function test_member_without_email_is_counted_apart(): void {
		$this->assertSame( M::MIGRATE_NO_EMAIL, M::classify( $this->f( array( 'email' => '' ) ) ) );
		$this->assertSame( M::MIGRATE_NO_EMAIL, M::classify( $this->f( array( 'email' => '  ' ) ) ) );
	}

	public function test_anyone_with_powers_or_other_roles_stays_on_wordpress(): void {
		$this->assertSame( M::OPERATOR, M::classify( $this->f( array( 'power' => true ) ) ) );
		$this->assertSame( M::OPERATOR, M::classify( $this->f( array( 'roles' => array( 'asem_member', 'editor' ) ) ) ) );
		$this->assertSame( M::OPERATOR, M::classify( $this->f( array( 'roles' => array( 'administrator' ) ) ) ) );
		$this->assertSame( M::OPERATOR, M::classify( $this->f( array( 'roles' => array( 'asem_secretary' ) ) ) ) );
	}

	public function test_a_card_that_cannot_log_in_is_left_alone(): void {
		$this->assertSame( M::NOT_ALLOWED, M::classify( $this->f( array( 'allowed' => false, 'power' => true ) ) ) );
	}
}
