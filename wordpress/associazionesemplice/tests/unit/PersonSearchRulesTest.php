<?php
use AssociazioneSemplice\PersonSearchRules;
use PHPUnit\Framework\TestCase;

final class PersonSearchRulesTest extends TestCase {

	private function people(): array {
		$rows = array();
		for ( $i = 1; $i <= 14; $i++ ) {
			$rows[] = array( 'id' => $i, 'type' => 'ordinary', 'first_name' => 'Maria', 'last_name' => 'Rossi' . $i, 'card_number' => (string) ( 100 + $i ), 'email' => 'maria' . $i . '@example.com', 'tax_code' => 'RSSMRA85T50A562W', 'active_until' => '2026-12-31' );
		}
		$rows[] = array( 'id' => 20, 'type' => 'guest', 'first_name' => 'Luigi', 'last_name' => 'Verdi', 'card_number' => '', 'email' => 'luigi@example.com' );
		return $rows;
	}

	private function results( string $q ): array {
		return PersonSearchRules::results(
			$this->people(),
			PersonSearchRules::clean( $q ),
			'2026-11-16',
			function ( string $t ): bool { return 'ordinary' === $t; },
			function ( array $p ): string { return trim( $p['first_name'] . ' ' . $p['last_name'] ); }
		);
	}

	public function test_under_three_letters_nothing_is_searched(): void {
		$this->assertFalse( PersonSearchRules::is_searchable( 'Ma' ) );
		$this->assertFalse( PersonSearchRules::is_searchable( PersonSearchRules::clean( '   ' ) ) );
		$this->assertTrue( PersonSearchRules::is_searchable( 'Mar' ) );
		$this->assertSame( array(), $this->results( 'Ma' ) );
	}

	public function test_at_most_ten_results(): void {
		$this->assertCount( 10, $this->results( 'Maria' ) );
	}

	public function test_only_name_surname_and_card_are_searched(): void {
		$this->assertCount( 1, $this->results( 'verdi' ) );
		$this->assertCount( 1, $this->results( 'Rossi3 Maria' ) );
		$this->assertCount( 1, $this->results( 'Verdi Luigi' ) );
		$this->assertCount( 1, $this->results( '105' ) );
		$this->assertSame( array(), $this->results( 'example.com' ) );
		$this->assertSame( array(), $this->results( 'RSSMRA85T50A562W' ) );
	}

	public function test_a_match_only_on_email_or_tax_code_is_discarded_even_if_the_search_returned_it(): void {
		// la ricerca in anagrafica può portare anche chi corrisponde solo per email o codice fiscale: qui vengono scartati
		$people = array(
			array( 'id' => 1, 'type' => 'ordinary', 'first_name' => 'Mario', 'last_name' => 'Rossi', 'card_number' => '1', 'email' => 'luigi.verdi@example.com', 'tax_code' => 'VRDLGU80A01H501X', 'phone' => '3331234567' ),
			array( 'id' => 2, 'type' => 'ordinary', 'first_name' => 'Anna', 'last_name' => 'Bianchi', 'card_number' => '2', 'email' => 'mario@example.com', 'tax_code' => 'BNCNNA80A41H501Z' ),
		);
		$run = function ( string $q ) use ( $people ): array {
			return PersonSearchRules::results( $people, PersonSearchRules::clean( $q ), '2026-11-16', function (): bool { return true; }, function ( array $p ): string { return $p['first_name']; } );
		};
		$this->assertSame( array(), $run( 'mario@' ) );
		$this->assertSame( array(), $run( 'luigi.verdi' ) );
		$this->assertSame( array(), $run( 'VRDLGU' ) );
		$this->assertSame( array(), $run( 'BNCNNA80' ) );
		$this->assertSame( array(), $run( '333123' ) );
		$this->assertCount( 1, $run( 'Mario' ) );
		$this->assertSame( 1, $run( 'Mario' )[0]['id'] );
	}

	public function test_only_the_minimum_fields_are_returned(): void {
		$r = $this->results( 'Luigi' );
		$this->assertSame( array( 'id', 'label', 'quota', 'type' ), $this->sorted_keys( $r[0] ) );
	}

	private function sorted_keys( array $a ): array {
		$k = array_keys( $a );
		sort( $k );
		return $k;
	}

	public function test_quota_text(): void {
		$this->assertSame( '', PersonSearchRules::quota_text( false, null, '2026-11-16' ) );
		$this->assertSame( 'tessera non in regola', PersonSearchRules::quota_text( true, null, '2026-11-16' ) );
		$this->assertSame( 'tessera non in regola', PersonSearchRules::quota_text( true, '2026-01-31', '2026-11-16' ) );
		$this->assertSame( 'tessera valida fino al 31/12/2026', PersonSearchRules::quota_text( true, '2026-12-31', '2026-11-16' ) );
	}
}
