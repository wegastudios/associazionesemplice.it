<?php
use AssociazioneSemplice\ApplicationRules;
use PHPUnit\Framework\TestCase;

final class ApplicationRulesTest extends TestCase {

	const TODAY = '2026-10-26';

	private function adult( array $over = array() ): array {
		return $over + array(
			'first_name' => 'Mario', 'last_name' => 'Rossi', 'email' => 'Mario@Example.com', 'phone' => '333 1234567', 'tax_code' => 'rssmra80a01h501u',
			'birth_date' => '01/01/1980', 'type' => 'ordinary', 'privacy_ok' => '1', 'rules_ok' => '1',
		);
	}

	private function minor( array $over = array() ): array {
		return $over + array(
			'first_name' => 'Luigi', 'last_name' => 'Verdi', 'email' => '', 'phone' => '', 'tax_code' => 'VRDLGU10A01H501X', 'birth_date' => '2010-01-01', 'type' => 'ordinary',
			'privacy_ok' => '1', 'rules_ok' => '1', 'guardian_ok' => '1', 'guardian_first_name' => 'Anna', 'guardian_last_name' => 'Verdi',
			'guardian_email' => 'anna@example.com', 'guardian_phone' => '339 7654321',
		);
	}

	private function check( array $in, bool $rules = true ): array {
		return ApplicationRules::check( $in, self::TODAY, $rules, array( 'ordinary', 'volunteer' ) );
	}

	public function test_a_good_adult_application(): void {
		$r = $this->check( $this->adult() );
		$this->assertSame( array(), $r['errors'] );
		$this->assertFalse( $r['minor'] );
		$this->assertSame( 'mario@example.com', $r['data']['email'], 'email in minuscolo' );
		$this->assertSame( 'RSSMRA80A01H501U', $r['data']['tax_code'] );
		$this->assertSame( '1980-01-01', $r['data']['birth_date'] );
		$this->assertSame( array(), $r['data']['guardian'] );
	}

	public function test_required_fields(): void {
		$r = $this->check( array( 'privacy_ok' => '1' ) );
		$this->assertNotEmpty( $r['errors'] );
		$text = implode( ' ', $r['errors'] );
		foreach ( array( 'nome e cognome', 'data di nascita', 'codice fiscale', 'tipo di socio', 'email', 'cellulare' ) as $word ) {
			$this->assertStringContainsStringIgnoringCase( $word, $text, $word );
		}
	}

	public function test_tax_code_is_validated_with_the_check_character(): void {
		$r = $this->check( $this->adult( array( 'tax_code' => 'RSSMRA80A01H501X' ) ) );
		$this->assertStringContainsString( 'codice fiscale', implode( ' ', $r['errors'] ) );
	}

	public function test_privacy_and_rules_must_be_accepted(): void {
		$r    = $this->check( $this->adult( array( 'privacy_ok' => '', 'rules_ok' => '' ) ) );
		$text = implode( ' ', $r['errors'] );
		$this->assertStringContainsString( 'privacy', $text );
		$this->assertStringContainsString( 'regolamento', $text );
		$this->assertSame( array(), $this->check( $this->adult( array( 'rules_ok' => '' ) ), false )['errors'], 'senza regolamento attivo non serve accettarlo' );
	}

	public function test_type_must_be_one_of_the_allowed(): void {
		$this->assertNotEmpty( $this->check( $this->adult( array( 'type' => 'founder' ) ) )['errors'] );
		$this->assertNotEmpty( $this->check( $this->adult( array( 'type' => 'guest' ) ) )['errors'] );
	}

	public function test_birth_date_must_be_real_and_not_in_the_future(): void {
		$this->assertNotEmpty( $this->check( $this->adult( array( 'birth_date' => '31/02/1990' ) ) )['errors'] );
		$this->assertNotEmpty( $this->check( $this->adult( array( 'birth_date' => '2030-01-01' ) ) )['errors'] );
		$this->assertNotEmpty( $this->check( $this->adult( array( 'birth_date' => '1900-01-01' ) ) )['errors'], 'più di 110 anni' );
	}

	public function test_minor_needs_the_guardian_data(): void {
		$r = $this->check( $this->minor() );
		$this->assertSame( array(), $r['errors'] );
		$this->assertTrue( $r['minor'] );
		$this->assertSame( 'anna@example.com', $r['data']['guardian']['email'] );
		$this->assertSame( '', $r['data']['email'], 'il minore può non avere email' );

		$text = implode( ' ', $this->check( $this->minor( array( 'guardian_first_name' => '', 'guardian_email' => '', 'guardian_phone' => '', 'guardian_ok' => '' ) ) )['errors'] );
		foreach ( array( 'genitore o tutore', 'email del genitore', 'cellulare del genitore', 'dichiari' ) as $word ) {
			$this->assertStringContainsStringIgnoringCase( $word, $text, $word );
		}
	}

	public function test_minor_may_give_an_email_but_it_must_be_valid(): void {
		$this->assertSame( array(), $this->check( $this->minor( array( 'email' => 'luigi@example.com', 'phone' => '333 1234567' ) ) )['errors'] );
		$this->assertNotEmpty( $this->check( $this->minor( array( 'email' => 'boh' ) ) )['errors'] );
	}

	public function test_guardian_tax_code_is_optional_but_checked(): void {
		$this->assertSame( array(), $this->check( $this->minor( array( 'guardian_tax_code' => '' ) ) )['errors'] );
		$this->assertNotEmpty( $this->check( $this->minor( array( 'guardian_tax_code' => 'ABCDEF12G34H567I' ) ) )['errors'] );
	}

	public function test_guardian_data_is_ignored_for_adults(): void {
		$r = $this->check( $this->adult( array( 'guardian_first_name' => 'Chi', 'guardian_email' => 'x@example.com' ) ) );
		$this->assertSame( array(), $r['errors'] );
		$this->assertSame( array(), $r['data']['guardian'] );
	}

	public function test_turning_eighteen_today_is_an_adult(): void {
		$r = $this->check( $this->adult( array( 'birth_date' => '2008-10-26' ) ) );
		$this->assertFalse( $r['minor'] );
		$r = $this->check( $this->minor( array( 'birth_date' => '2008-10-27' ) ) );
		$this->assertTrue( $r['minor'] );
	}
}
