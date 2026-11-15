<?php
use AssociazioneSemplice\EntityRules;
use PHPUnit\Framework\TestCase;

final class EntityRulesTest extends TestCase {

	public function test_tax_code_shape(): void {
		foreach ( array( '12345678903', '12345678901', 'RSSMRA80A01H501U', 'rssmra80a01h501u', ' 1234 5678 903 ', 'RSSMRA80A01H501' . '1' ) as $ok ) {
			$this->assertTrue( EntityRules::tax_code_shape( $ok ), $ok );
		}
		foreach ( array( '', '123', '1234567890', '123456789012', 'RSSMRA80A01H501', 'RSSMRA80A01H501UU', 'RSSMRA80A01H50-U' ) as $bad ) {
			$this->assertFalse( EntityRules::tax_code_shape( $bad ), $bad );
		}
	}

	public function test_zip_province_sdi(): void {
		$this->assertTrue( EntityRules::zip_valid( '20100' ) );
		$this->assertFalse( EntityRules::zip_valid( '2010' ) );
		$this->assertFalse( EntityRules::zip_valid( '2010A' ) );
		$this->assertTrue( EntityRules::province_valid( 'MI' ) );
		$this->assertTrue( EntityRules::province_valid( 'mi' ) );
		$this->assertFalse( EntityRules::province_valid( 'M' ) );
		$this->assertFalse( EntityRules::province_valid( 'MIL' ) );
		$this->assertFalse( EntityRules::province_valid( 'M1' ) );
		$this->assertTrue( EntityRules::sdi_valid( 'abc1234' ) );
		$this->assertFalse( EntityRules::sdi_valid( 'abc123' ) );
		$this->assertFalse( EntityRules::sdi_valid( 'abc12345' ) );
		$this->assertFalse( EntityRules::sdi_valid( 'abc-123' ) );
	}

	public function test_forms(): void {
		$this->assertSame( array( 'asd', 'aps', 'odv', 'ets', 'circolo', 'altro' ), EntityRules::FORM_KEYS );
		$this->assertSame( 'APS', EntityRules::form_short( 'aps' ) );
		$this->assertSame( 'Circolo', EntityRules::form_short( 'circolo' ) );
	}
}
