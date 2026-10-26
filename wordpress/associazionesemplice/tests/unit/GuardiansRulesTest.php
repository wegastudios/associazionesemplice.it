<?php
use AssociazioneSemplice\Guardians;
use PHPUnit\Framework\TestCase;

final class GuardiansRulesTest extends TestCase {

	private function minor( array $over = array() ): array {
		return $over + array( 'id' => 1, 'birth_date' => '2012-05-10', 'email' => null );
	}

	private function parent_( array $over = array() ): array {
		return $over + array( 'id' => 2, 'type' => 'ordinary', 'email' => 'mamma@example.com', 'birth_date' => '1980-01-01' );
	}

	public function test_a_normal_link_has_no_errors(): void {
		$this->assertSame( array(), Guardians::validate( $this->minor(), $this->parent_(), 0, '2026-10-26' ) );
		$this->assertSame( array(), Guardians::validate( $this->minor(), $this->parent_( array( 'birth_date' => null ) ), 1, '2026-10-26' ), 'il secondo tutore; la nascita del tutore è facoltativa' );
	}

	public function test_minor_needs_a_birth_date(): void {
		$e = Guardians::validate( $this->minor( array( 'birth_date' => null ) ), $this->parent_(), 0, '2026-10-26' );
		$this->assertCount( 1, $e );
		$this->assertStringContainsString( 'data di nascita', $e[0] );
	}

	public function test_guardian_needs_a_valid_email(): void {
		$this->assertNotEmpty( Guardians::validate( $this->minor(), $this->parent_( array( 'email' => '' ) ), 0, '2026-10-26' ) );
		$this->assertNotEmpty( Guardians::validate( $this->minor(), $this->parent_( array( 'email' => 'non-una-email' ) ), 0, '2026-10-26' ) );
	}

	public function test_guardian_must_be_an_adult(): void {
		$e = Guardians::validate( $this->minor(), $this->parent_( array( 'birth_date' => '2010-01-01' ) ), 0, '2026-10-26' );
		$this->assertStringContainsString( 'maggiorenne', implode( ' ', $e ) );
	}

	public function test_at_most_two_guardians(): void {
		$e = Guardians::validate( $this->minor(), $this->parent_(), 2, '2026-10-26' );
		$this->assertStringContainsString( 'al massimo 2', implode( ' ', $e ) );
	}

	public function test_nobody_is_their_own_guardian(): void {
		$e = Guardians::validate( $this->minor(), $this->parent_( array( 'id' => 1 ) ), 0, '2026-10-26' );
		$this->assertStringContainsString( 'se stessa', implode( ' ', $e ) );
	}

	public function test_guardian_must_be_able_to_log_in(): void {
		foreach ( array( 'ordinary', 'volunteer', 'founder', 'guardian' ) as $t ) {
			$this->assertSame( array(), Guardians::validate( $this->minor(), $this->parent_( array( 'type' => $t ) ), 0, '2026-10-26' ), $t );
		}
		$e = Guardians::validate( $this->minor(), $this->parent_( array( 'type' => 'guest' ) ), 0, '2026-10-26' );
		$this->assertStringContainsString( 'area riservata', implode( ' ', $e ), 'un ospite non può essere tutore' );
	}
}
