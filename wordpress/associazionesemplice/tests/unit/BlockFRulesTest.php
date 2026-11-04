<?php
use AssociazioneSemplice\ConventionRules;
use PHPUnit\Framework\TestCase;

final class BlockFRulesTest extends TestCase {

	public function test_status_by_dates(): void {
		$today = '2026-06-15';
		$this->assertSame( ConventionRules::STATUS_ACTIVE, ConventionRules::status( null, null, false, $today ) );
		$this->assertSame( ConventionRules::STATUS_ACTIVE, ConventionRules::status( '2026-06-15', '2026-06-15', false, $today ) ); // primo e ultimo giorno compresi
		$this->assertSame( ConventionRules::STATUS_UPCOMING, ConventionRules::status( '2026-06-16', null, false, $today ) );
		$this->assertSame( ConventionRules::STATUS_EXPIRED, ConventionRules::status( null, '2026-06-14', false, $today ) );
		$this->assertSame( ConventionRules::STATUS_ARCHIVED, ConventionRules::status( null, null, true, $today ) );
		$this->assertSame( ConventionRules::STATUS_ARCHIVED, ConventionRules::status( null, '2026-01-01', true, $today ) ); // archiviata prevale
		$this->assertSame( ConventionRules::STATUS_ACTIVE, ConventionRules::status( '', '', false, $today ) );
	}

	public function test_valid_on_only_when_active(): void {
		$this->assertTrue( ConventionRules::is_valid_on( '2026-01-01', '2026-12-31', false, '2026-06-15' ) );
		$this->assertFalse( ConventionRules::is_valid_on( '2026-01-01', '2026-06-14', false, '2026-06-15' ) );
		$this->assertFalse( ConventionRules::is_valid_on( '2026-01-01', '2026-12-31', true, '2026-06-15' ) );
		$this->assertFalse( ConventionRules::is_valid_on( '2026-07-01', null, false, '2026-06-15' ) );
	}

	public function test_check_period(): void {
		$this->assertNull( ConventionRules::check_period( null, null ) );
		$this->assertNull( ConventionRules::check_period( '2026-01-01', '2026-01-01' ) );
		$this->assertNotNull( ConventionRules::check_period( '2026-02-01', '2026-01-01' ) );
		$this->assertNotNull( ConventionRules::check_period( '2026-02-30', null ) );
		$this->assertNotNull( ConventionRules::check_period( null, 'domani' ) );
	}

	public function test_expiring_within_thirty_days(): void {
		$today = '2026-06-15';
		$this->assertTrue( ConventionRules::is_expiring( null, '2026-06-15', false, $today ) );
		$this->assertTrue( ConventionRules::is_expiring( null, '2026-07-15', false, $today ) ); // 30 giorni compresi
		$this->assertFalse( ConventionRules::is_expiring( null, '2026-07-16', false, $today ) );
		$this->assertFalse( ConventionRules::is_expiring( null, '2026-06-14', false, $today ) ); // già scaduta
		$this->assertFalse( ConventionRules::is_expiring( null, null, false, $today ) ); // senza scadenza
		$this->assertFalse( ConventionRules::is_expiring( null, '2026-06-20', true, $today ) ); // archiviata
		$this->assertFalse( ConventionRules::is_expiring( '2026-06-20', '2026-06-25', false, $today ) ); // non ancora iniziata
	}

	public function test_entitlement(): void {
		// tutti i soci in regola
		$this->assertTrue( ConventionRules::is_entitled( 'all', array(), 'ordinary', true ) );
		$this->assertTrue( ConventionRules::is_entitled( 'all', array(), 'volunteer', true ) );
		$this->assertTrue( ConventionRules::is_entitled( 'all', array(), 'founder', true ) );
		$this->assertFalse( ConventionRules::is_entitled( 'all', array(), 'ordinary', false ) ); // tessera scaduta o sospeso
		$this->assertFalse( ConventionRules::is_entitled( 'all', array(), 'guest', true ) );
		$this->assertFalse( ConventionRules::is_entitled( 'all', array(), 'guardian', true ) );
		// solo alcuni tipi
		$this->assertTrue( ConventionRules::is_entitled( 'types', array( 'volunteer' ), 'volunteer', true ) );
		$this->assertFalse( ConventionRules::is_entitled( 'types', array( 'volunteer' ), 'ordinary', true ) );
		$this->assertFalse( ConventionRules::is_entitled( 'types', array( 'volunteer' ), 'volunteer', false ) );
		$this->assertFalse( ConventionRules::is_entitled( 'types', array( 'guest' ), 'guest', true ) ); // un tipo che non è socio non conta
		$this->assertFalse( ConventionRules::is_entitled( 'types', array(), 'ordinary', true ) );
	}

	public function test_types(): void {
		$this->assertSame( array( 'founder', 'volunteer' ), ConventionRules::clean_types( array( 'volunteer', 'guest', 'founder', 'volunteer', 'x' ) ) );
		$this->assertSame( 'founder,ordinary', ConventionRules::types_to_string( array( 'ordinary', 'founder' ) ) );
		$this->assertSame( array( 'founder', 'ordinary' ), ConventionRules::types_from_string( 'ordinary, founder,guest' ) );
		$this->assertSame( array(), ConventionRules::types_from_string( '' ) );
	}

	public function test_website(): void {
		$this->assertSame( 'https://esempio.it', ConventionRules::normalize_website( 'esempio.it' ) );
		$this->assertSame( 'http://esempio.it/a?b=1', ConventionRules::normalize_website( ' http://esempio.it/a?b=1 ' ) );
		$this->assertSame( '', ConventionRules::normalize_website( 'javascript:alert(1)' ) );
		$this->assertSame( '', ConventionRules::normalize_website( 'ftp://esempio.it' ) );
		$this->assertSame( '', ConventionRules::normalize_website( '' ) );
		$this->assertSame( '', ConventionRules::normalize_website( 'https://a b.it' ) );
	}

	public function test_categories(): void {
		$this->assertSame( array( 'Sport', 'cultura', 'SALUTE' ), ConventionRules::parse_categories( "Sport\r\n cultura \nSALUTE\n\nsport;Cultura" ) );
		$this->assertSame( array( 'Sport', 'Salute', 'Cinema' ), ConventionRules::merge_categories( array( 'Sport', 'Salute' ), array( 'sport', 'Cinema', '' ) ) );
	}
}
