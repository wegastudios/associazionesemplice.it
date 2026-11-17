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

	private const NOW = 1800000000;

	/** @return array [chiavi, risposta v2 firmata (come da parse), chiave segreta] */
	private function v2( array $over = array(), string $tag = 'asem-pkg-v2' ): array {
		$pair = sodium_crypto_sign_keypair();
		$sk   = sodium_crypto_sign_secretkey( $pair );
		$keys = array( 'k2' => $this->b64( sodium_crypto_sign_publickey( $pair ) ) );
		$r    = $over + array( 'product' => 'pro', 'version' => '1.5.0', 'sha256' => hash( 'sha256', 'zip' ), 'signed_at' => self::NOW - 60, 'min_version' => '1.4', 'kid_v2' => 'k2' );
		$msg  = $tag . '|' . $r['product'] . '|' . $r['version'] . '|' . $r['sha256'] . '|' . $r['signed_at'] . '|' . $r['min_version'];
		$r   += array( 'signature_v2' => $this->b64( sodium_crypto_sign_detached( $msg, $sk ) ) );
		$r['v2'] = true;
		return array( $keys, $r, $sk );
	}

	private function verdict( array $keys, array $r, array $seen = array() ): string {
		return U::v2_verdict( $r['product'] ?? 'pro', $r, $keys, $seen, self::NOW );
	}

	public function test_valid_v2_is_accepted(): void {
		list( $keys, $r ) = $this->v2();
		$this->assertSame( '', $this->verdict( $keys, $r ) );
		$this->assertTrue( U::verify_signature_v2( 'pro', '1.5.0', $r['sha256'], $r['signed_at'], '1.4', 'k2', $r['signature_v2'], $keys ) );
	}

	public function test_v2_with_wrong_prefix_or_v1_signature_is_rejected(): void {
		list( $keys, $r ) = $this->v2( array(), 'asem-pkg-v1' );
		$this->assertSame( 'invalid', $this->verdict( $keys, $r ), 'prefisso sbagliato' );
		$sha  = hash( 'sha256', 'zip' );
		$pair = sodium_crypto_sign_keypair();
		$keys = array( 'k2' => $this->b64( sodium_crypto_sign_publickey( $pair ) ) );
		$sig1 = $this->b64( sodium_crypto_sign_detached( 'pro|1.5.0|' . $sha, sodium_crypto_sign_secretkey( $pair ) ) );
		$r    = array( 'v2' => true, 'product' => 'pro', 'version' => '1.5.0', 'sha256' => $sha, 'signed_at' => self::NOW, 'min_version' => '1.4', 'kid_v2' => 'k2', 'signature_v2' => $sig1 );
		$this->assertSame( 'invalid', $this->verdict( $keys, $r ), 'firma v1 usata come v2' );
	}

	public function test_v2_unknown_kid_partial_fields_and_changed_data_are_rejected(): void {
		list( $keys, $r ) = $this->v2();
		$this->assertSame( 'invalid', $this->verdict( $keys, array( 'kid_v2' => 'ignoto' ) + $r ), 'kid_v2 ignoto' );
		foreach ( array( 'signature_v2' => '', 'kid_v2' => '', 'signed_at' => 0, 'min_version' => '' ) as $k => $empty ) {
			$this->assertSame( 'invalid', $this->verdict( $keys, array( $k => $empty ) + $r ), "campo v2 mancante: $k" );
		}
		$this->assertSame( 'invalid', $this->verdict( $keys, array( 'sha256' => hash( 'sha256', 'altro' ) ) + $r ), 'sha256 diverso' );
		$this->assertSame( 'invalid', $this->verdict( $keys, array( 'version' => '1.5.1' ) + $r ), 'versione diversa' );
		$this->assertSame( 'invalid', $this->verdict( $keys, array( 'signed_at' => $r['signed_at'] + 1 ) + $r ), 'data diversa' );
		$this->assertSame( 'invalid', $this->verdict( $keys, array( 'product' => 'free' ) + $r ), 'prodotto diverso' );
		$this->assertSame( 'invalid', $this->verdict( $keys, array( 'min_version' => '1.3' ) + $r ), 'soglia diversa' );
	}

	public function test_parse_reads_v2_fields_and_flags_partial_ones(): void {
		$r = U::parse( '{"version":"1.2","update":true,"signature_v2":"s","kid_v2":"k","signed_at":1700000000,"min_version":"1.1"}' );
		$this->assertTrue( $r['v2'] );
		$this->assertSame( array( 's', 'k', 1700000000, '1.1' ), array( $r['signature_v2'], $r['kid_v2'], $r['signed_at'], $r['min_version'] ) );
		$this->assertTrue( U::parse( '{"version":"1.2","kid_v2":"k"}' )['v2'], 'anche un solo campo' );
		$p = U::parse( '{"version":"1.2","signed_at":"1700000000","min_version":5}' );
		$this->assertTrue( $p['v2'] );
		$this->assertSame( 0, $p['signed_at'], 'solo interi' );
		$this->assertSame( '', $p['min_version'] );
		$this->assertFalse( U::parse( '{"version":"1.2","signature":"s","kid":"k","signature_v2":null}' )['v2'], 'campi nulli = non dichiarati' );
	}

	public function test_min_version_above_version_is_rejected(): void {
		list( $keys, $r ) = $this->v2( array( 'min_version' => '1.6' ) );
		$this->assertSame( 'min', $this->verdict( $keys, $r ) );
		list( $keys, $r ) = $this->v2( array( 'min_version' => 'abc' ) );
		$this->assertSame( 'invalid', $this->verdict( $keys, $r ), 'formato non valido' );
		list( $keys, $r ) = $this->v2( array( 'min_version' => '1.5.0' ) );
		$this->assertSame( '', $this->verdict( $keys, $r ), 'soglia uguale alla versione' );
	}

	public function test_version_below_highest_seen_is_rejected_but_same_version_is_allowed(): void {
		list( $keys, $r ) = $this->v2();
		$this->assertSame( 'rollback', $this->verdict( $keys, $r, array( 'version' => '1.5.1', 'min_version' => '1.4' ) ) );
		$this->assertSame( '', $this->verdict( $keys, $r, array( 'version' => '1.5.0', 'min_version' => '1.4' ) ), 'stessa versione ripubblicata' );
		$this->assertSame( '', $this->verdict( $keys, $r, array( 'version' => '1.4.9' ) ) );
		$this->assertSame( 'min', $this->verdict( $keys, $r, array( 'min_version' => '1.5.1' ) ), 'sotto la soglia già vista' );
	}

	public function test_future_signature_is_rejected_beyond_one_day(): void {
		list( $keys, $r ) = $this->v2( array( 'signed_at' => self::NOW + 86400 ) );
		$this->assertSame( '', $this->verdict( $keys, $r ), 'esattamente un giorno: ammesso' );
		list( $keys, $r ) = $this->v2( array( 'signed_at' => self::NOW + 86401 ) );
		$this->assertSame( 'future', $this->verdict( $keys, $r ) );
	}

	public function test_package_without_v2_fields_behaves_as_before(): void {
		$r = U::parse( '{"version":"1.2","update":true,"sha256":"' . hash( 'sha256', 'zip' ) . '","signature":"s","kid":"k"}' );
		$this->assertFalse( $r['v2'] );
		$this->assertSame( '', U::v2_verdict( 'pro', $r, array(), array(), self::NOW ), 'prima di qualsiasi v2 la v1 passa come oggi' );
		$this->assertSame( '', U::v2_verdict( 'pro', $r, array(), array( 'version' => '1.2', 'min_version' => '' ), self::NOW ), 'stessa versione ammessa' );
		$this->assertSame( 'rollback', U::v2_verdict( 'pro', $r, array(), array( 'version' => '1.3' ), self::NOW ), 'v1 sotto la versione più alta vista' );
		$this->assertSame( 'min', U::v2_verdict( 'pro', $r, array(), array( 'min_version' => '1.3' ), self::NOW ), 'v1 sotto la soglia vista' );
		$this->assertSame( '', U::v2_verdict( 'pro', $r, array(), array( 'version' => '1.1', 'min_version' => '1.2' ), self::NOW ), 'v1 alla soglia' );
		$this->assertSame( 'missing_v2', U::v2_verdict( 'pro', $r, array(), array( 'version' => '1.0', 'v2' => true ), self::NOW ), 'dopo un v2 visto la v1 non passa più' );
	}

	public function test_after_an_accepted_v2_a_package_without_v2_is_rejected(): void {
		list( $keys, $r ) = $this->v2();
		$this->assertSame( '', $this->verdict( $keys, $r ) );
		$seen = U::seen_after( array(), $r['version'], $r['min_version'] );
		$old  = U::parse( '{"version":"1.5.0","update":true,"sha256":"' . $r['sha256'] . '","signature":"s","kid":"k"}' );
		$this->assertSame( 'missing_v2', U::v2_verdict( 'pro', $old, $keys, $seen, self::NOW ) );
		$this->assertSame( 'missing_v2', U::v2_verdict( 'pro', U::parse( '{"version":"2.0","update":true}' ), $keys, $seen, self::NOW ), 'anche una versione più alta' );
		$this->assertSame( '', U::v2_verdict( 'pro', $r, $keys, $seen, self::NOW ), 'il v2 continua a passare' );
	}

	public function test_a_v1_package_is_remembered_without_turning_v2_on(): void {
		$a = U::seen_after( array(), '1.3', '', false );
		$this->assertSame( array( 'version' => '1.3', 'min_version' => '', 'v2' => false ), $a );
		$this->assertSame( array( 'version' => '1.3', 'min_version' => '', 'v2' => false ), U::seen_after( $a, '1.2', '', false ), 'non scende' );
		$b = U::seen_after( array( 'version' => '1.3', 'min_version' => '1.1', 'v2' => true ), '1.4', '', false );
		$this->assertSame( array( 'version' => '1.4', 'min_version' => '1.1', 'v2' => true ), $b, 'soglia e v2 restano' );
	}

	public function test_stored_thresholds_never_go_down(): void {
		$a = U::seen_after( array(), '1.5.0', '1.4' );
		$this->assertSame( array( 'version' => '1.5.0', 'min_version' => '1.4', 'v2' => true ), $a );
		$b = U::seen_after( $a, '1.5.0', '1.4' );
		$this->assertSame( $a, $b, 'stessa versione' );
		$c = U::seen_after( $a, '1.4.2', '1.2' );
		$this->assertSame( $a, $c, 'non scendono' );
		$d = U::seen_after( $a, '1.6', '1.5' );
		$this->assertSame( array( 'version' => '1.6', 'min_version' => '1.5', 'v2' => true ), $d );
		$this->assertSame( array( 'version' => '1.6', 'min_version' => '1.5', 'v2' => true ), U::seen_after( $d, '1.5.5', '1.0' ) );
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

	public function test_changelog_new_format_with_labels_date_todo_and_link(): void {
		$md   = "## 1.3.26 – 2026-11-17\n- Nuovo: permessi per area\n- Sicurezza: <i>x</i>\n- Altro: errori\nDa fare dopo l'aggiornamento:\n- Stripe: eventi del webhook\nChangelog di tutte le versioni: https://associazionesemplice.it/wordpress/changelog\n\n## 1.3.25\n- Vecchia voce\nChangelog di tutte le versioni: https://example.com/x";
		$html = U::changelog_html( $md );
		$this->assertStringContainsString( '<h4>1.3.26 – 2026-11-17</h4><ul><li><strong>Nuovo:</strong> permessi per area</li>', $html );
		$this->assertStringContainsString( '<li><strong>Sicurezza:</strong> &lt;i&gt;x&lt;/i&gt;</li>', $html );
		$this->assertStringContainsString( '</ul><p><strong>Da fare dopo l&#039;aggiornamento:</strong></p><ul><li>Stripe: eventi del webhook</li></ul>', $html );
		$this->assertStringContainsString( '<p><a href="https://associazionesemplice.it/wordpress/changelog">Changelog di tutte le versioni</a></p>', $html );
		$this->assertStringContainsString( '<h4>1.3.25</h4><ul><li>Vecchia voce</li>', $html );
		$this->assertStringNotContainsString( 'example.com', $html, 'un link fuori dal sito non entra' );
	}

	/** Il Pro dichiara «Update URI» con lo stesso host dell'API del portale: WordPress non cerca aggiornamenti con lo stesso nome su WordPress.org. */
	public function test_pro_header_declares_update_uri_on_the_portal_host(): void {
		$pro = (string) file_get_contents( dirname( __DIR__, 3 ) . '/associazionesemplice-pro/associazionesemplice-pro.php' );
		$this->assertSame( 1, preg_match( '/^\s*\*\s*Update URI:\s*(\S+)/m', $pro, $m ) );
		$this->assertSame( parse_url( U::API, PHP_URL_HOST ), parse_url( $m[1], PHP_URL_HOST ) );
		$this->assertSame( 'https', parse_url( $m[1], PHP_URL_SCHEME ) );
	}

	/** Il gratuito del repository non dichiara «Update URI» (è lo stesso codice di WordPress.org): lo aggiunge solo build.sh al pacchetto del portale. */
	public function test_free_source_has_no_update_uri_and_only_the_portal_build_adds_it(): void {
		$free  = (string) file_get_contents( dirname( __DIR__, 2 ) . '/associazionesemplice.php' );
		$build = (string) file_get_contents( dirname( __DIR__, 3 ) . '/build.sh' );
		$this->assertStringNotContainsStringIgnoringCase( 'Update URI', $free );
		$this->assertStringContainsString( 'Update URI:        https://associazionesemplice.it/', $build );
	}

	/** Con l'aggiornamento diretto attivo l'avviso di nuova versione porta a Bacheca → Aggiornamenti; il caricamento a mano resta il ripiego. */
	public function test_update_notice_points_to_update_core_when_the_updater_is_active(): void {
		$src = (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/Admin/UpdateNotice.php' );
		$this->assertStringContainsString( "admin_url( 'update-core.php' )", $src );
		$this->assertStringContainsString( 'Updater::enabled()', $src );
		$this->assertStringContainsString( 'Updater::products()', $src );
		$this->assertStringContainsString( 'Plugin → Aggiungi nuovo', $src );
	}
}
