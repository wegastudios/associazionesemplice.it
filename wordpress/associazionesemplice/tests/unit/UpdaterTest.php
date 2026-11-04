<?php
use AssociazioneSemplice\Updater as U;
use PHPUnit\Framework\TestCase;

final class UpdaterTest extends TestCase {

	public function test_valid_answer_is_parsed(): void {
		$r = U::parse( '{"product":"free","version":"1.2","update":true,"package":"https:\/\/associazionesemplice.it\/api\/plugin\/package?t=x","url":"https:\/\/associazionesemplice.it\/accedi"}' );
		$this->assertSame( '1.2', $r['version'] );
		$this->assertTrue( $r['update'] );
		$this->assertSame( 'https://associazionesemplice.it/api/plugin/package?t=x', $r['package'] );
	}

	public function test_no_update_has_no_package(): void {
		$r = U::parse( '{"version":"1.1.20","update":false,"package":null,"url":"https://associazionesemplice.it/accedi"}' );
		$this->assertFalse( $r['update'] );
		$this->assertSame( '', $r['package'] );
	}

	public function test_garbage_and_insecure_values_are_dropped(): void {
		$this->assertNull( U::parse( 'non json' ) );
		$this->assertNull( U::parse( '{"version":"<b>","update":true}' ) );
		$this->assertNull( U::parse( '{"update":true}' ) );
		$r = U::parse( '{"version":"1.3","update":true,"package":"http://x.it/a.zip","url":"javascript:alert(1)"}' );
		$this->assertSame( '', $r['package'], 'solo https' );
		$this->assertSame( '', $r['url'] );
	}

	private function b64( string $s ): string {
		return rtrim( strtr( base64_encode( $s ), '+/', '-_' ), '=' );
	}

	/** @return array [chiavi pubbliche, firma] per «prodotto|versione|sha256» */
	private function signed( string $msg ): array {
		$pair = sodium_crypto_sign_keypair();
		$sig  = sodium_crypto_sign_detached( $msg, sodium_crypto_sign_secretkey( $pair ) );
		return array( array( 'k1' => $this->b64( sodium_crypto_sign_publickey( $pair ) ) ), $this->b64( $sig ) );
	}

	public function test_package_signature_binds_product_version_and_hash(): void {
		$sha = hash( 'sha256', 'zip' );
		list( $keys, $sig ) = $this->signed( 'pro|1.1.6|' . $sha );
		$this->assertTrue( U::verify_signature( 'pro', '1.1.6', $sha, 'k1', $sig, $keys ) );
		$this->assertFalse( U::verify_signature( 'free', '1.1.6', $sha, 'k1', $sig, $keys ), 'altro prodotto' );
		$this->assertFalse( U::verify_signature( 'pro', '1.1.5', $sha, 'k1', $sig, $keys ), 'altra versione' );
		$this->assertFalse( U::verify_signature( 'pro', '1.1.6', hash( 'sha256', 'altro' ), 'k1', $sig, $keys ), 'altra impronta' );
		$this->assertFalse( U::verify_signature( 'pro', '1.1.6', $sha, 'sconosciuto', $sig, $keys ), 'kid sconosciuto' );
		$this->assertFalse( U::verify_signature( 'pro', '1.1.6', $sha, 'k1', 'non-base64!', $keys ) );
		$this->assertFalse( U::verify_signature( 'pro', '1.1.6', 'XYZ', 'k1', $sig, $keys ), 'impronta non valida' );
		list( $other ) = $this->signed( 'x' );
		$this->assertFalse( U::verify_signature( 'pro', '1.1.6', $sha, 'k1', $sig, $other ), 'chiave diversa' );
	}

	public function test_published_package_key_has_the_right_shape(): void {
		$this->assertArrayHasKey( 'pkg-2026-10-85dce7', U::PACKAGE_KEYS );
		foreach ( U::PACKAGE_KEYS as $x ) {
			$this->assertSame( 32, strlen( base64_decode( strtr( $x, '-_', '+/' ) . '=' ) ) );
		}
	}

	public function test_parse_keeps_hash_signature_and_kid(): void {
		$r = U::parse( '{"version":"1.2","update":true,"package":"https://x.it/p","sha256":"ABC","signature":"sig","kid":"k"}' );
		$this->assertSame( 'abc', $r['sha256'] );
		$this->assertSame( 'sig', $r['signature'] );
		$this->assertSame( 'k', $r['kid'] );
	}

	public function test_changelog_becomes_safe_html_and_ignores_other_lines(): void {
		$r = U::parse( '{"version":"1.2.19","changelog":"## 1.2.19\n- Anni solari nel gratuito.\n- <b>x</b>\n\nTesto libero\n## 1.2.18\n- Listini."}' );
		$html = U::changelog_html( $r['changelog'] );
		$this->assertStringContainsString( '<h4>1.2.19</h4><ul><li>Anni solari nel gratuito.</li>', $html );
		$this->assertStringContainsString( '&lt;b&gt;x&lt;/b&gt;', $html );
		$this->assertStringContainsString( '<h4>1.2.18</h4>', $html );
		$this->assertStringNotContainsString( 'Testo libero', $html );
		$this->assertSame( '', U::changelog_html( 'niente di valido' ) );
	}
}
