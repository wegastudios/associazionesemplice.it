<?php
use AssociazioneSemplice\StatementSnapshot;
use PHPUnit\Framework\TestCase;

/** Le parti pure del rendiconto fotografato: numero del documento, data e dati per il PDF emesso. */
final class StatementSnapshotTest extends TestCase {

	public function test_number_text_has_number_and_year(): void {
		$this->assertSame( 'n. 1/2025', StatementSnapshot::number_text( 1, 2025 ) );
		$this->assertSame( 'n. 12/2031', StatementSnapshot::number_text( 12, 2031 ) );
	}

	public function test_dmy_reads_dates_and_datetimes(): void {
		$this->assertSame( '13/11/2026', StatementSnapshot::dmy( '2026-11-13' ) );
		$this->assertSame( '05/01/2025', StatementSnapshot::dmy( '2025-01-05 23:59:59' ) );
		$this->assertSame( '', StatementSnapshot::dmy( '' ) );
		$this->assertSame( '', StatementSnapshot::dmy( 'ieri' ) );
	}

	public function test_pdf_meta_carries_everything_the_pdf_needs(): void {
		$payload  = array(
			'label' => '2025/2026', 'prev_label' => '2024/2025', 'from' => '2025-07-01', 'to' => '2026-06-30',
			'entity' => array( 'name' => 'Associazione di prova', 'tax_code' => '12345678901' ), 'president' => 'Mario Rossi',
		);
		$emission = array( 'number' => 2, 'year' => 2025, 'issued_at' => '2026-07-02 10:00:00', 'retroactive' => 0, 'version' => 2 );
		$meta     = StatementSnapshot::pdf_meta( $payload, $emission );
		$this->assertSame( 'Associazione di prova', $meta['entity']['name'] );
		$this->assertSame( 'Mario Rossi', $meta['president'] );
		$this->assertSame( '2025/2026', $meta['label'] );
		$this->assertSame( '2025-07-01', $meta['from'] );
		$this->assertSame( $emission, $meta['emission'] );
		$this->assertNull( StatementSnapshot::pdf_meta( $payload, null )['emission'] );
	}
}
