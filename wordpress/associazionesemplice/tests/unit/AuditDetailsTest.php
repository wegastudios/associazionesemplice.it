<?php
use AssociazioneSemplice\Audit;
use PHPUnit\Framework\TestCase;

final class AuditDetailsTest extends TestCase {

	public function test_the_network_address_is_stored_only_as_a_short_fingerprint(): void {
		$fp = Audit::ip_fingerprint( '203.0.113.7', 'sale' );
		$this->assertSame( 12, strlen( $fp ) );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{12}$/', $fp );
		$this->assertSame( $fp, Audit::ip_fingerprint( '203.0.113.7', 'sale' ), 'stesso indirizzo, stessa impronta' );
		$this->assertNotSame( $fp, Audit::ip_fingerprint( '203.0.113.8', 'sale' ) );
		$this->assertNotSame( $fp, Audit::ip_fingerprint( '203.0.113.7', 'altro sale' ), 'dipende dal sale del sito' );
		$this->assertSame( '', Audit::ip_fingerprint( '', 'sale' ), 'senza indirizzo (lavori programmati) niente impronta' );
	}

	public function test_changed_keys_lists_names_and_never_values(): void {
		$before = array( 'a' => 1, 'b' => 'x', 'c' => array( 1, 2 ), 'secret' => 'old' );
		$after  = array( 'a' => 1, 'b' => 'y', 'c' => array( 1, 3 ), 'secret' => 'new', 'd' => 0 );
		$keys   = Audit::changed_keys( $before, $after );
		$this->assertSame( array( 'b', 'c', 'd', 'secret' ), $keys );
		$this->assertStringNotContainsString( 'new', implode( ',', $keys ) );
		$this->assertSame( array(), Audit::changed_keys( $before, $before ) );
	}
}
