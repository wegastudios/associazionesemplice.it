<?php
use AssociazioneSemplice\SportCatalog;
use PHPUnit\Framework\TestCase;

final class SportCatalogTest extends TestCase {

	public function test_known_sport_is_kept(): void {
		$this->assertSame( 'Judo', SportCatalog::clean( ' Judo ' ) );
		$this->assertSame( 'Tiro con l\'arco', SportCatalog::clean( 'Tiro con l\'arco' ) );
	}

	public function test_unknown_sport_is_dropped(): void {
		$this->assertNull( SportCatalog::clean( 'Quidditch' ) );
		$this->assertNull( SportCatalog::clean( '' ) );
	}

	public function test_list_has_no_duplicates(): void {
		$s = SportCatalog::sports();
		$this->assertSame( count( $s ), count( array_unique( $s ) ) );
		$this->assertContains( 'Danza sportiva', $s );
	}
}
