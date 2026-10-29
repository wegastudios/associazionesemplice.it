<?php
use AssociazioneSemplice\Receipts;
use PHPUnit\Framework\TestCase;

final class ReceiptMinorTest extends TestCase {

	private function kid( array $over = array() ): array {
		return $over + array( 'id' => 1, 'first_name' => 'Kid', 'last_name' => 'Minore', 'tax_code' => 'MNRKDI14A01H501X', 'birth_date' => '2014-03-01' );
	}

	private function mum(): array {
		return array( 'id' => 2, 'first_name' => 'Marta', 'last_name' => 'Madre', 'tax_code' => 'MDRMRT80A41H501Y', 'is_operative' => 1 );
	}

	private function dad(): array {
		return array( 'id' => 3, 'first_name' => 'Pino', 'last_name' => 'Padre', 'tax_code' => 'PDRPNI78A01H501W', 'is_operative' => 0 );
	}

	public function test_adult_is_unchanged(): void {
		$payer = array( 'id' => 9 );
		$b     = Receipts::minor_block( $payer, $this->kid( array( 'birth_date' => '1990-01-01' ) ), array( $this->mum() ), '2026-10-29', 'cash', 'Contanti', array( 'activity_fee' ) );
		$this->assertSame( $payer, $b['holder'] );
		$this->assertSame( '', $b['note'] );
	}

	public function test_minor_receipt_goes_to_operative_guardian_with_both_tax_codes(): void {
		$b = Receipts::minor_block( $this->kid(), $this->kid(), array( $this->dad(), $this->mum() ), '2026-10-29', 'bank_transfer', 'Bonifico', array( 'activity_fee' ) );
		$this->assertSame( 2, $b['holder']['id'] );
		$text = implode( ' ', $b['lines'] );
		$this->assertStringContainsString( 'PDRPNI78A01H501W', $text );
		$this->assertStringContainsString( 'MNRKDI14A01H501X', $text );
	}

	public function test_the_other_parent_who_paid_gets_the_receipt(): void {
		$b = Receipts::minor_block( $this->dad(), $this->kid(), array( $this->mum(), $this->dad() ), '2026-10-29', 'pos', 'POS / carta', array( 'activity_fee' ) );
		$this->assertSame( 3, $b['holder']['id'] );
		$this->assertStringContainsString( 'MDRMRT80A41H501Y', implode( ' ', $b['lines'] ) );
	}

	public function test_traced_and_untraced_payment_between_five_and_eighteen(): void {
		$traced = Receipts::minor_block( $this->kid(), $this->kid(), array( $this->mum() ), '2026-10-29', 'bank_transfer', 'Bonifico', array( 'activity_fee' ) );
		$this->assertStringContainsString( 'Pagamento tracciato', $traced['note'] );
		$cash = Receipts::minor_block( $this->kid(), $this->kid(), array( $this->mum() ), '2026-10-29', 'cash', 'Contanti', array( 'activity_fee' ) );
		$this->assertStringContainsString( 'non tracciato', $cash['note'] );
		$small = Receipts::minor_block( $this->kid( array( 'birth_date' => '2024-01-01' ) ), $this->kid( array( 'birth_date' => '2024-01-01' ) ), array( $this->mum() ), '2026-10-29', 'cash', 'Contanti', array( 'activity_fee' ) );
		$this->assertSame( '', $small['note'], 'sotto i 5 anni nessuna detrazione' );
		$donation = Receipts::minor_block( $this->kid(), $this->kid(), array( $this->mum() ), '2026-10-29', 'cash', 'Contanti', array( 'donation' ) );
		$this->assertSame( '', $donation['note'], 'solo quote di attività e iscrizione' );
	}

	public function test_minor_without_guardian_is_unchanged(): void {
		$b = Receipts::minor_block( $this->kid(), $this->kid(), array(), '2026-10-29', 'cash', 'Contanti', array( 'activity_fee' ) );
		$this->assertSame( 1, $b['holder']['id'] );
		$this->assertSame( array(), $b['lines'] );
	}
}
