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
	}

	public function test_has_looks_only_at_the_path_column(): void {
		$this->assertFalse( ReceiptFiles::has( array(), 'issued' ) );
		$this->assertFalse( ReceiptFiles::has( array( 'pdf_path' => null ), 'issued' ) );
		$this->assertTrue( ReceiptFiles::has( array( 'pdf_path' => 'receipts/2026/1-' . self::MAC . '.pdf' ), 'issued' ) );
		$this->assertFalse( ReceiptFiles::has( array( 'pdf_path' => 'receipts/2026/1-' . self::MAC . '.pdf' ), 'void' ) );
	}
}
