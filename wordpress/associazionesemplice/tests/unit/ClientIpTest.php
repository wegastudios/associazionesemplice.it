<?php
use AssociazioneSemplice\ClientIp as C;
use PHPUnit\Framework\TestCase;

final class ClientIpTest extends TestCase {

	public function test_default_is_the_connection_address(): void {
		$this->assertSame( '203.0.113.5', C::pick( array( 'REMOTE_ADDR' => '203.0.113.5', 'HTTP_X_FORWARDED_FOR' => '9.9.9.9' ), false ) );
		$this->assertSame( '', C::pick( array( 'REMOTE_ADDR' => 'non-un-ip' ), false ) );
		$this->assertSame( '', C::pick( array(), false ) );
	}

	public function test_behind_a_proxy_the_forwarded_address_is_used(): void {
		// Cloudflare (o un tunnel sullo stesso server): l'intestazione di Cloudflare è affidabile
		$this->assertSame( '198.51.100.7', C::pick( array( 'REMOTE_ADDR' => '104.16.0.9', 'HTTP_CF_CONNECTING_IP' => '198.51.100.7' ), true ) );
		$this->assertSame( '198.51.100.7', C::pick( array( 'REMOTE_ADDR' => '2606:4700::1', 'HTTP_CF_CONNECTING_IP' => '198.51.100.7' ), true ) );
		$this->assertSame( '198.51.100.7', C::pick( array( 'REMOTE_ADDR' => '127.0.0.1', 'HTTP_CF_CONNECTING_IP' => '198.51.100.7' ), true ) );
		// L'ultimo indirizzo è quello aggiunto dal proxy del sito: i precedenti li può scrivere chiunque.
		$this->assertSame( '198.51.100.9', C::pick( array( 'REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4, 5.6.7.8, 198.51.100.9' ), true ) );
	}

	public function test_cloudflare_header_from_another_proxy_is_ignored(): void {
		// un proxy qualunque non è Cloudflare: l'intestazione l'ha potuta scrivere il visitatore
		$this->assertSame( '10.0.0.1', C::pick( array( 'REMOTE_ADDR' => '10.0.0.1', 'HTTP_CF_CONNECTING_IP' => '198.51.100.7' ), true ) );
		$this->assertSame( '198.51.100.9', C::pick( array( 'REMOTE_ADDR' => '10.0.0.1', 'HTTP_CF_CONNECTING_IP' => '6.6.6.6', 'HTTP_X_FORWARDED_FOR' => '198.51.100.9' ), true ) );
		$this->assertFalse( C::in_range( '104.15.255.255', '104.16.0.0/13' ) );
		$this->assertTrue( C::in_range( '104.23.255.255', '104.16.0.0/13' ) );
		$this->assertFalse( C::in_range( '2001:db8::1', '2606:4700::/32' ) );
	}

	public function test_garbage_headers_fall_back_to_the_connection(): void {
		$this->assertSame( '10.0.0.1', C::pick( array( 'REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => 'boh, <script>', 'HTTP_CF_CONNECTING_IP' => 'x' ), true ) );
	}
}
