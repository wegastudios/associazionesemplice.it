<?php
use AssociazioneSemplice\EventRequestRules;
use PHPUnit\Framework\TestCase;

/** Quando una richiesta di evento può riusare la scheda esistente di un ospite. */
final class EventRequestRulesTest extends TestCase {

	private function card(): array {
		return array( 'first_name' => 'Nicolò', 'last_name' => "D'Angelo Rossi", 'birth_date' => '1992-04-15' );
	}

	public function test_same_person_ignores_case_spaces_accents_and_apostrophes(): void {
		$this->assertTrue( EventRequestRules::same_person( $this->card(), array( 'first_name' => 'NICOLO', 'last_name' => 'd angelo-rossi ', 'birth_date' => '1992-04-15' ) ) );
		$this->assertTrue( EventRequestRules::same_person( $this->card(), array( 'first_name' => ' nicolò ', 'last_name' => 'DAngelo   Rossi', 'birth_date' => '1992-04-15' ) ) );
	}

	public function test_a_different_name_or_surname_is_another_person(): void {
		$this->assertFalse( EventRequestRules::same_person( $this->card(), array( 'first_name' => 'Marco', 'last_name' => "D'Angelo Rossi", 'birth_date' => '1992-04-15' ) ) );
		$this->assertFalse( EventRequestRules::same_person( $this->card(), array( 'first_name' => 'Nicolò', 'last_name' => 'Bianchi', 'birth_date' => '1992-04-15' ) ) );
	}

	public function test_birth_date_counts_only_when_the_card_has_one(): void {
		$this->assertFalse( EventRequestRules::same_person( $this->card(), array( 'first_name' => 'Nicolò', 'last_name' => "D'Angelo Rossi", 'birth_date' => '1992-04-16' ) ) );
		$no_birth = array( 'first_name' => 'Nicolò', 'last_name' => "D'Angelo Rossi", 'birth_date' => null );
		$this->assertTrue( EventRequestRules::same_person( $no_birth, array( 'first_name' => 'Nicolò', 'last_name' => "D'Angelo Rossi", 'birth_date' => '1992-04-16' ) ) );
	}

	public function test_empty_names_never_match_a_filled_card(): void {
		$this->assertFalse( EventRequestRules::same_person( $this->card(), array( 'first_name' => '', 'last_name' => '', 'birth_date' => '1992-04-15' ) ) );
	}
}
