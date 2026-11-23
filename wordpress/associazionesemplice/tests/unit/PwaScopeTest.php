<?php
use PHPUnit\Framework\TestCase;

/** L'app installabile non pesa sul resto del sito: si registra solo nell'area soci o con una sessione, il worker non intercetta le altre pagine, file con ETag. (Il resto, con WordPress, è in tests/smoke.php.) */
final class PwaScopeTest extends TestCase {

	private function pwa(): string {
		return (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/Pwa.php' );
	}

	private function body( string $signature, string $next ): string {
		$src  = $this->pwa();
		$from = (int) strpos( $src, $signature );
		return substr( $src, $from, (int) strpos( $src, $next, $from ) - $from );
	}

	public function test_head_and_script_only_where_the_app_is_needed(): void {
		$this->assertStringContainsString( 'if ( ! self::should_register() ) {', $this->body( 'public static function print_head()', 'public static function enqueue()' ) );
		$this->assertStringContainsString( 'if ( ! self::should_register() ) {', $this->body( 'public static function enqueue()', '// ---------- Dispositivi' ) );
		$should = $this->body( 'public static function should_register()', 'public static function area_path()' );
		$this->assertStringContainsString( 'MemberSession::logged_in() || self::is_area_page()', $should );
	}

	public function test_worker_intercepts_only_the_member_area_page(): void {
		$src = $this->pwa();
		$this->assertStringContainsString( "url.pathname !== AREA_PATH", $src );
		$this->assertStringNotContainsString( "indexOf('/wp-admin')", $src, 'non serve più: il resto del sito non passa dal worker' );
		// L'unico respondWith sulle pagine è dentro il controllo del percorso dell'area.
		$nav  = $this->body( "if (req.mode === 'navigate') {", "if (url.origin === self.location.origin && url.pathname.indexOf(ASSETS)" );
		$this->assertLessThan( (int) strpos( $nav, 'e.respondWith' ), (int) strpos( $nav, 'AREA_PATH' ) );
	}

	public function test_files_have_an_etag_and_answer_304(): void {
		$serve = $this->body( 'public static function maybe_serve()', 'public static function etag(' );
		$this->assertStringContainsString( "header( 'ETag: ' . \$etag );", $serve );
		$this->assertStringContainsString( 'Calendar::not_modified( $etag, 0, $inm, \'\' )', $serve );
		$this->assertStringContainsString( 'status_header( 304 );', $serve );
		$this->assertLessThan( (int) strpos( $serve, 'echo $r[\'body\']' ), (int) strpos( $serve, 'status_header( 304 );' ), 'il 304 viene prima di scrivere il contenuto' );
	}

	public function test_icon_is_written_once_and_old_ones_removed(): void {
		$icon = $this->body( 'private static function icon_on_disk(', '// ---------- Contenuti' );
		$this->assertStringContainsString( 'if ( ! is_file( $path ) ) {', $icon );
		$this->assertStringContainsString( "'/asem-pwa'", $icon );
		$this->assertStringContainsString( "glob( \$dir . '/icon-' . \$size . '-*.png' )", $icon );
		$this->assertStringContainsString( "wp_delete_file( \$old )", $icon );
		$this->assertStringContainsString( 'self::icon_on_disk( $size );', $this->body( 'public static function icon_url(', 'public static function manifest()' ) );
		$un = (string) file_get_contents( dirname( __DIR__, 2 ) . '/uninstall.php' );
		$this->assertStringContainsString( '/asem-pwa/icon-*.png', $un );
	}
}
