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

	public function test_an_anonymous_get_without_personal_parameters_is_cacheable(): void {
		$this->assertTrue( CachePolicy::page_cacheable( 'GET', false, false, array() ) );
		$this->assertTrue( CachePolicy::page_cacheable( 'head', false, false, array( 'utm_source', 'fbclid' ) ) );
	}

	public function test_a_logged_in_visitor_or_a_member_cookie_is_never_served_from_the_cache(): void {
		$this->assertFalse( CachePolicy::page_cacheable( 'GET', true, false, array() ) );
		$this->assertFalse( CachePolicy::page_cacheable( 'GET', false, true, array() ) );
	}

	public function test_forms_and_other_systems_are_never_cached(): void {
		foreach ( array( 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS', '' ) as $method ) {
			$this->assertFalse( CachePolicy::page_cacheable( $method, false, false, array() ), $method );
		}
		$this->assertFalse( CachePolicy::page_cacheable( 'GET', false, false, array(), true ) );
	}

	public function test_personal_or_one_off_parameters_keep_the_page_out_of_the_cache(): void {
		foreach ( array( 'asem_cat', 'asem_mese', 'asemf_ok', 'asemf_err', 'asemf_sig', 'asem_ml', 'token', 'card_token', '_wpnonce', 'PayerID', 'paymentId', 'paymentid', '_back', '_n', '_sig', 'preview', 's', 'p', 'wp-login' ) as $key ) {
			$this->assertTrue( CachePolicy::private_query_key( $key ), $key );
			$this->assertFalse( CachePolicy::page_cacheable( 'GET', false, false, array( 'utm_source', $key ) ), $key );
		}
		foreach ( array( 'utm_source', 'fbclid', 'gclid', 'ref' ) as $key ) {
			$this->assertFalse( CachePolicy::private_query_key( $key ), $key );
		}
	}

	public function test_a_page_with_a_form_a_nonce_or_a_token_is_not_stored(): void {
		$this->assertTrue( CachePolicy::storable_html( '<div class="asemf"><ul><li>Cena · 5 posti liberi</li></ul></div>' ) );
		foreach ( array( '<form method="post"></form>', '<input type="hidden" name="x">', '<a href="?a=1&token=abc">x</a>', '<i data-x="_wpnonce"></i>', '<a href="?asemf_ok=Fatto">x</a>', '<select></select>', '<textarea></textarea>' ) as $html ) {
			$this->assertFalse( CachePolicy::storable_html( $html ), $html );
		}
	}

	public function test_the_public_headers_allow_a_short_shared_copy_only(): void {
		$h = CachePolicy::public_headers();
		$this->assertStringContainsString( 'public', $h['Cache-Control'] );
		$this->assertStringContainsString( 'max-age=0', $h['Cache-Control'] );
		$this->assertStringContainsString( 's-maxage=' . CachePolicy::PAGE_TTL, $h['Cache-Control'] );
		$this->assertStringNotContainsString( 'no-store', $h['Cache-Control'] );
		$this->assertSame( 600, CachePolicy::PAGE_TTL );
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
