<?php
use AssociazioneSemplice\PeopleCsv;
use AssociazioneSemplice\Rules;
use PHPUnit\Framework\TestCase;

final class PeopleBirthGuardianTest extends TestCase {

	private function parse( array $table ): array {
		$r = PeopleCsv::parse_table( $table );
		$this->assertArrayHasKey( 'rows', $r );
		return $r['rows'];
	}

	public function test_birth_and_guardian_columns_are_recognised(): void {
		$rows = $this->parse(
			array(
				array( 'Nome', 'Cognome', 'Email', 'Data di nascita', 'Tutore' ),
				array( 'Luigi', 'Verdi', '', '01/02/2012', '15' ),
				array( 'Anna', 'Neri', 'anna@example.com', '1980-05-06', '' ),
			)
		);
		$this->assertSame( '2012-02-01', $rows[0]['birth'] );
		$this->assertSame( '15', $rows[0]['guardian'] );
		$this->assertSame( '1980-05-06', $rows[1]['birth'] );
		$this->assertNull( $rows[1]['guardian'] );
	}

	public function test_alternative_headers(): void {
		$rows = $this->parse( array( array( 'Nome', 'Cognome', 'Email', 'Nato il', 'Genitore' ), array( 'Eva', 'Blu', 'e@example.com', '3.4.2015', 'madre@example.com' ) ) );
		$this->assertSame( '2015-04-03', $rows[0]['birth'] );
		$this->assertSame( 'madre@example.com', $rows[0]['guardian'] );
	}

	public function test_columns_are_optional(): void {
		$rows = $this->parse( array( array( 'Nome', 'Cognome', 'Email' ), array( 'Eva', 'Blu', 'e@example.com' ) ) );
		$this->assertNull( $rows[0]['birth'] );
		$this->assertNull( $rows[0]['birth_text'] );
		$this->assertNull( $rows[0]['guardian'] );
	}

	public function test_invalid_birth_date_is_an_error_in_the_plan(): void {
		$rows = $this->parse( array( array( 'Nome', 'Cognome', 'Email', 'Data di nascita' ), array( 'Eva', 'Blu', 'e@example.com', '31/02/2015' ) ) );
		$plan = PeopleCsv::plan( $rows, array() );
		$this->assertSame( 'error', $plan[0]['action'] );
		$this->assertStringContainsString( 'Data di nascita', $plan[0]['message'] );
	}

	public function test_guardian_needs_the_birth_date(): void {
		$rows = $this->parse( array( array( 'Nome', 'Cognome', 'Email', 'Tutore' ), array( 'Eva', 'Blu', 'e@example.com', '15' ) ) );
		$plan = PeopleCsv::plan( $rows, array() );
		$this->assertSame( 'error', $plan[0]['action'] );
		$this->assertStringContainsString( 'data di nascita', $plan[0]['message'] );
	}

	public function test_a_valid_row_with_birth_and_guardian_is_planned(): void {
		$rows = $this->parse( array( array( 'Nome', 'Cognome', 'Email', 'Data di nascita', 'Tutore' ), array( 'Eva', 'Blu', 'e@example.com', '03/04/2015', '15' ) ) );
		$plan = PeopleCsv::plan( $rows, array() );
		$this->assertSame( 'create', $plan[0]['action'], (string) $plan[0]['message'] );
	}

	public function test_person_rules_check_the_birth_date(): void {
		$base = array( 'type' => 'ordinary', 'first_name' => 'Eva', 'last_name' => 'Blu', 'email' => '' , 'card_number' => '', 'host_person_id' => null );
		$this->assertSame( array(), Rules::validate_person( $base + array( 'birth_date' => '2015-04-03' ) ) );
		$this->assertSame( array(), Rules::validate_person( $base + array( 'birth_date' => null ) ) );
		$this->assertNotEmpty( Rules::validate_person( $base + array( 'birth_date' => '2015-02-30' ) ) );
		$this->assertNotEmpty( Rules::validate_person( $base + array( 'birth_date' => '2999-01-01' ) ), 'nel futuro' );
	}
}
