<?php
use AssociazioneSemplice\SessionToken as T;
use PHPUnit\Framework\TestCase;

/** Con l'accesso proprio l'API risponde solo a richieste che arrivano dal sito stesso. */
final class RestOriginTest extends TestCase {

	private const SITE = array( 'https://example.org', 'https://example.org/wp' );

	public function test_same_origin_is_accepted(): void {
		$this->assertTrue( T::request_origin_allowed( 'https://example.org', 'same-origin', self::SITE ) );
		$this->assertTrue( T::request_origin_allowed( 'https://EXAMPLE.org:443', '', self::SITE ) );
	}

	public function test_request_without_headers_is_accepted(): void {
		$this->assertTrue( T::request_origin_allowed( '', '', self::SITE ) );
		$this->assertTrue( T::request_origin_allowed( '', 'none', self::SITE ) );
	}

	public function test_other_subdomain_is_refused(): void {
		$this->assertFalse( T::request_origin_allowed( 'https://altro.example.org', '', self::SITE ) );
		$this->assertFalse( T::request_origin_allowed( '', 'same-site', self::SITE ) );
		$this->assertFalse( T::request_origin_allowed( 'https://altro.example.org', 'same-site', self::SITE ) );
	}

	public function test_foreign_origin_is_refused(): void {
		$this->assertFalse( T::request_origin_allowed( 'https://evil.test', 'cross-site', self::SITE ) );
		$this->assertFalse( T::request_origin_allowed( 'https://evil.test', '', self::SITE ) );
		$this->assertFalse( T::request_origin_allowed( 'null', '', self::SITE ) );
		$this->assertFalse( T::request_origin_allowed( 'http://example.org', '', self::SITE ), 'schema diverso' );
		$this->assertFalse( T::request_origin_allowed( 'https://example.org:8443', '', self::SITE ), 'porta diversa' );
	}

	public function test_origin_of(): void {
		$this->assertSame( 'https://example.org', T::origin_of( 'https://Example.org/percorso?x=1' ) );
		$this->assertSame( 'http://example.org:8080', T::origin_of( 'http://example.org:8080/' ) );
		$this->assertSame( '', T::origin_of( 'javascript:alert(1)' ) );
		$this->assertSame( '', T::origin_of( '' ) );
	}

	/** L'API controlla l'origine per ogni richiesta con l'accesso proprio, GET compresi, prima del token delle modifiche. */
	public function test_api_guard_checks_origin_before_csrf(): void {
		$src  = (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/Rest/Api.php' );
		$orig = strpos( $src, 'request_origin_allowed' );
		$csrf = strpos( $src, "check_csrf( 'rest'" );
		$this->assertNotFalse( $orig );
		$this->assertLessThan( $csrf, $orig );
	}
}
