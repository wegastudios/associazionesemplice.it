<?php
use AssociazioneSemplice\ReceiptFiles;
use PHPUnit\Framework\TestCase;

/** Regole pure dei PDF definitivi delle ricevute: percorsi, nomi e colonne. (Il salvataggio e le impronte sono provati da tests/smoke.php.) */
final class ReceiptFilesTest extends TestCase {

	private const MAC = 'a1b2c3d4e5f60718293a';

	public function test_path_is_built_from_year_number_kind_and_code(): void {
		$this->assertSame( 'receipts/2026/12-' . self::MAC . '.pdf', ReceiptFiles::rel_path( 2026, 12, 'issued', self::MAC ) );
		$this->assertSame( 'receipts/2026/12a-' . self::MAC . '.pdf', ReceiptFiles::rel_path( 2026, 12, 'void', self::MAC ) );
		$this->assertSame( 'receipts/2026/7-' . self::MAC . 'abc123.pdf', ReceiptFiles::rel_path( 2026, 7, 'issued', self::MAC, 'abc123' ) );
	}

	public function test_built_paths_are_valid(): void {
		foreach ( array( 'issued', 'void' ) as $kind ) {
			$this->assertTrue( ReceiptFiles::valid_path( ReceiptFiles::rel_path( 2031, 1, $kind, self::MAC ) ) );
			$this->assertTrue( ReceiptFiles::valid_path( ReceiptFiles::rel_path( 2031, 123456789, $kind, self::MAC, 'ffffff' ) ) );
		}
	}

	/** @dataProvider bad_paths */
	public function test_other_paths_are_never_accepted( string $p ): void {
		$this->assertFalse( ReceiptFiles::valid_path( $p ) );
	}

	public static function bad_paths(): array {
		$m = self::MAC;
		return array(
			array( '' ),
			array( '../wp-config.php' ),
			array( 'receipts/2026/../../x.pdf' ),
			array( '/receipts/2026/1-' . $m . '.pdf' ),
			array( 'receipts/2026/1-' . $m . '.php' ),
			array( 'receipts/2026/1-' . $m . '.pdf/../../x' ),
			array( 'receipts/26/1-' . $m . '.pdf' ),
			array( 'receipts/2026/1-' . strtoupper( $m ) . '.pdf' ),
			array( 'receipts/2026/1-' . substr( $m, 0, 19 ) . '.pdf' ),
			array( 'receipts/2026/1-' . $m . "\n.pdf" ),
			array( 'receipts/2026/1-' . $m . ".pdf\n" ),
			array( 'receipts/2026/x/1-' . $m . '.pdf' ),
			array( 'receipts\\2026\\1-' . $m . '.pdf' ),
			array( 'allegati/2026/1-' . $m . '.pdf' ),
			array( 'receipts/2026/1-' . $m . '.pdf.alterato-20260101000000' ),
		);
	}

	public function test_columns_of_each_kind(): void {
		$this->assertSame( array( 'pdf_path', 'pdf_sha256', 'pdf_at' ), ReceiptFiles::columns( 'issued' ) );
		$this->assertSame( array( 'void_pdf_path', 'void_pdf_sha256', 'void_pdf_at' ), ReceiptFiles::columns( 'void' ) );
		$this->assertSame( array( 'issued', 'void' ), ReceiptFiles::kinds() );
		$this->assertSame( 'pdf_enc_ver', ReceiptFiles::enc_col( 'issued' ) );
		$this->assertSame( 'void_pdf_enc_ver', ReceiptFiles::enc_col( 'void' ) );
	}

	public function test_has_looks_only_at_the_path_column(): void {
		$this->assertFalse( ReceiptFiles::has( array(), 'issued' ) );
		$this->assertFalse( ReceiptFiles::has( array( 'pdf_path' => null ), 'issued' ) );
		$this->assertTrue( ReceiptFiles::has( array( 'pdf_path' => 'receipts/2026/1-' . self::MAC . '.pdf' ), 'issued' ) );
		$this->assertFalse( ReceiptFiles::has( array( 'pdf_path' => 'receipts/2026/1-' . self::MAC . '.pdf' ), 'void' ) );
	}
	public function test_summaries_of_two_rounds_add_up(): void {
		$a = array( 'total' => 500, 'ok' => 498, 'locked' => 0, 'missing' => 1, 'altered' => 1, 'pending' => 0, 'nodata' => 0 );
		$b = array( 'total' => 120, 'ok' => 119, 'locked' => 0, 'missing' => 0, 'altered' => 1, 'pending' => 0, 'nodata' => 0 );
		$s = ReceiptFiles::merge_summary( $a, $b );
		$this->assertSame( 620, $s['total'] );
		$this->assertSame( 617, $s['ok'] );
		$this->assertSame( 1, $s['missing'] );
		$this->assertSame( 2, $s['altered'] );
		$this->assertSame( 0, ReceiptFiles::merge_summary( ReceiptFiles::empty_summary(), ReceiptFiles::empty_summary() )['total'] );
	}

	public function test_result_keeps_only_files_to_fix_and_no_more_than_the_limit(): void {
		$p = static function ( string $state, int $i ): array {
			return array( 'key' => 'k' . $i, 'label' => $i . '/2026', 'state' => $state, 'detail' => array( 'issued' => $state ) );
		};
		$kept = ReceiptFiles::keep_problems( array(), array( $p( 'pending', 1 ), $p( 'nodata', 2 ), $p( 'missing', 3 ), $p( 'altered', 4 ), $p( 'locked', 5 ) ) );
		$this->assertSame( array( 'k3', 'k4', 'k5' ), array_column( $kept, 'key' ) );
		$many = array();
		for ( $i = 0; $i < ReceiptFiles::VERIFY_KEEP + 50; $i++ ) {
			$many[] = $p( 'missing', $i );
		}
		$this->assertCount( ReceiptFiles::VERIFY_KEEP, ReceiptFiles::keep_problems( array(), $many ) );
		$this->assertCount( ReceiptFiles::VERIFY_KEEP, ReceiptFiles::keep_problems( ReceiptFiles::keep_problems( array(), $many ), $many ) );
	}

	public function test_a_round_is_bounded_and_uses_its_own_options(): void {
		$this->assertSame( 500, ReceiptFiles::VERIFY_BATCH );
		$this->assertGreaterThan( 0, ReceiptFiles::VERIFY_BUDGET );
		$this->assertNotSame( ReceiptFiles::VERIFY_RUN, ReceiptFiles::VERIFY_LAST );
	}
}
