<?php
use AssociazioneSemplice\PreSession;
use PHPUnit\Framework\TestCase;

final class PreSessionTest extends TestCase {

	private const NOW = 1790000000;

	public function test_cookie_is_random_and_well_formed(): void {
		$a = PreSession::make( self::NOW );
		$b = PreSession::make( self::NOW );
		$this->assertNotSame( $a, $b );
		$this->assertTrue( PreSession::valid( $a, self::NOW ) );
		$this->assertFalse( PreSession::valid( '', self::NOW ) );
		$this->assertFalse( PreSession::valid( 'abc', self::NOW ) );
		$this->assertFalse( PreSession::valid( self::NOW . '.corto', self::NOW ) );
	}

	public function test_cookie_expires(): void {
		$c = PreSession::make( self::NOW );
		$this->assertTrue( PreSession::valid( $c, self::NOW + PreSession::TTL - 5 ) );
		$this->assertFalse( PreSession::valid( $c, self::NOW + PreSession::TTL + 5 ) );
		$this->assertFalse( PreSession::valid( PreSession::make( self::NOW + 3600 ), self::NOW ), 'un orario nel futuro non vale' );
	}

	public function test_form_token_is_bound_to_the_cookie(): void {
		$c = PreSession::make( self::NOW );
		$t = PreSession::token( $c, 'member_login', 's' );
		$this->assertTrue( PreSession::check( $t, $c, 'member_login', 's', self::NOW ) );
		$this->assertFalse( PreSession::check( $t, PreSession::make( self::NOW ), 'member_login', 's', self::NOW ), 'cookie di un altro browser' );
		$this->assertFalse( PreSession::check( $t, '', 'member_login', 's', self::NOW ), 'nessun cookie' );
		$this->assertFalse( PreSession::check( '', $c, 'member_login', 's', self::NOW ), 'nessun segno' );
		$this->assertFalse( PreSession::check( $t, $c, 'altra_azione', 's', self::NOW ) );
		$this->assertFalse( PreSession::check( $t, $c, 'member_login', 'altro', self::NOW ), 'altro segreto' );
		$this->assertFalse( PreSession::check( $t, $c, 'member_login', 's', self::NOW + PreSession::TTL + 5 ), 'cookie scaduto' );
	}
}
