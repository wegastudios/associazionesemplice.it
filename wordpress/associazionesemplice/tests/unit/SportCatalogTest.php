<?php
use AssociazioneSemplice\SportCatalog;
use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $s ) {
		return trim( strip_tags( (string) $s ) );
	}
}

final class SportCatalogTest extends TestCase {

	public function test_clean_trims_and_strips_tags(): void {
		$this->assertSame( 'Judo', SportCatalog::clean( ' Judo ' ) );
		$this->assertSame( 'Yoga dolce', SportCatalog::clean( "<b>Yoga</b>\n  dolce" ) );
		$this->assertSame( 60, mb_strlen( (string) SportCatalog::clean( str_repeat( 'x', 90 ) ) ) );
	}

	public function test_empty_is_null(): void {
		$this->assertNull( SportCatalog::clean( '   ' ) );
		$this->assertNull( SportCatalog::clean( '' ) );
	}

	public function test_default_list_has_no_duplicates(): void {
		$s = SportCatalog::defaults();
		$this->assertSame( count( $s ), count( array_unique( $s ) ) );
		$this->assertContains( 'Danza sportiva', $s );
		$this->assertContains( 'Scacchi', $s );
	}

	public function test_normalize_removes_duplicates_and_sorts(): void {
		$this->assertSame( array( 'Judo', 'Pilates', 'Yoga' ), SportCatalog::normalize( array( 'yoga', 'Yoga', 'Pilates', '', 'Judo' ) ) );
	}

	public function test_parse_accepts_lines_and_semicolons(): void {
		$this->assertSame( array( 'Yoga', 'Pilates', 'Zumba' ), SportCatalog::parse( "Yoga\r\nPilates;Zumba\n\n" ) );
	}
}
