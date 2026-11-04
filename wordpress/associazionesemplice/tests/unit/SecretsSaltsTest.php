<?php
use AssociazioneSemplice\Secrets as S;
use PHPUnit\Framework\TestCase;

final class SecretsSaltsTest extends TestCase {

	public function test_missing_short_or_example_salts_are_weak(): void {
		$good = str_repeat( 'aB3$', 12 );
		$this->assertFalse( S::salts_weak( $good, $good ) );
		$this->assertTrue( S::salts_weak( null, $good ) );
		$this->assertTrue( S::salts_weak( $good, null ) );
		$this->assertTrue( S::salts_weak( 'corto', $good ) );
		$this->assertTrue( S::salts_weak( 'put your unique phrase here', $good ) );
		$this->assertTrue( S::salts_weak( $good, 'Put Your Unique Phrase Here xxxxxxxxxxxxxxxxxxxxxxxx' ) );
	}
}
