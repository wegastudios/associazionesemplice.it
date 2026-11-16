<?php
use AssociazioneSemplice\CachePolicy;
use AssociazioneSemplice\Visibility;
use PHPUnit\Framework\TestCase;

final class CachePolicyTest extends TestCase {

	public function test_public_content_without_a_member_session_can_be_cached(): void {
		$this->assertFalse( CachePolicy::skip_page_cache( Visibility::PUBLIC_, false ) );
	}

	public function test_any_restricted_rule_keeps_the_page_out_of_the_cache_for_everyone(): void {
		foreach ( array( Visibility::MEMBERS, Visibility::VOLUNTEERS, Visibility::ACTIVITY ) as $rule ) {
			$this->assertTrue( CachePolicy::skip_page_cache( $rule, false ), $rule . ' (visitatore qualunque)' );
			$this->assertTrue( CachePolicy::skip_page_cache( $rule, true ), $rule . ' (con sessione)' );
		}
	}

	public function test_a_valid_member_session_keeps_even_a_public_page_out_of_the_cache(): void {
		$this->assertTrue( CachePolicy::skip_page_cache( Visibility::PUBLIC_, true ) );
	}

	public function test_the_headers_forbid_shared_caches_and_storage(): void {
		$h = CachePolicy::headers();
		$this->assertArrayHasKey( 'Cache-Control', $h );
		foreach ( array( 'private', 'no-store', 'no-cache', 'max-age=0' ) as $directive ) {
			$this->assertStringContainsString( $directive, $h['Cache-Control'] );
		}
		$this->assertArrayHasKey( 'X-LiteSpeed-Cache-Control', $h );
		$this->assertSame( 'no-cache', $h['X-LiteSpeed-Cache-Control'] );
	}
}
