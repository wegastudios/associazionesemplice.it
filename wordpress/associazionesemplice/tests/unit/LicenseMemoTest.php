<?php
use PHPUnit\Framework\TestCase;

/** L'esito verificato del controllo della licenza si ricorda per 12 ore; il resto (con WordPress) è in tests/smoke.php. Qui il testo dei file. */
final class LicenseMemoTest extends TestCase {

	private function src( string $file ): string {
		return (string) file_get_contents( dirname( __DIR__, 2 ) . '/' . $file );
	}

	private function sync_body(): string {
		$lic  = $this->src( 'includes/License.php' );
		$from = (int) strpos( $lic, 'public static function sync(' );
		return substr( $lic, $from, (int) strpos( $lic, 'private static function memo_print(' ) - $from );
	}

	public function test_memory_lasts_twelve_hours_and_is_not_autoloaded(): void {
		$lic = $this->src( 'includes/License.php' );
		$this->assertStringContainsString( "const OPT_MEMO        = 'asem_license_memo';", $lic );
		$this->assertStringContainsString( 'const MEMO_TTL        = 12 * HOUR_IN_SECONDS;', $lic );
		$this->assertMatchesRegularExpression( '/update_option\(\s*self::OPT_MEMO,.*?false\s*\);/s', $lic, 'nessun caricamento automatico' );
		$this->assertStringContainsString( 'hash_hmac( \'sha256\', $domain . "\n" . $key, wp_salt( \'auth\' ) )', $lic, 'impronta di dominio e chiave' );
	}

	public function test_memory_never_stores_the_key(): void {
		$lic  = $this->src( 'includes/License.php' );
		$from = (int) strpos( $lic, 'private static function remember(' );
		$body = substr( $lic, $from, (int) strpos( $lic, 'private static function recall(' ) - $from );
		$this->assertStringNotContainsString( '$key', $body );
		$this->assertStringContainsString( "'fp' => \$print", $body );
	}

	/** Si ricorda solo dopo una firma valida: i ritorni d'errore e di firma non valida non passano da remember(). */
	public function test_only_verified_outcomes_are_remembered(): void {
		$body = $this->sync_body();
		$this->assertSame( 2, substr_count( $body, 'self::remember(' ), 'solo il negativo firmato (primo) e l\'esito finale' );
		$this->assertStringContainsString( "return self::failed( 'La risposta del server delle licenze non ha una firma valida: la licenza non è stata aggiornata.' );", $body );
		$remember_pos = (int) strpos( $body, 'self::remember(' );
		$this->assertGreaterThan( (int) strpos( $body, 'LicenseClient::verify_denial(' ), $remember_pos, 'dopo la verifica della firma del negativo' );
		$last = (int) strrpos( $body, 'self::remember(' );
		$this->assertGreaterThan( (int) strpos( $body, 'LicenseClient::verify_attestation(' ), $last, 'dopo la verifica della firma del positivo' );
		$this->assertGreaterThan( (int) strrpos( $body, 'return self::failed(' ), $last, 'dopo l\'ultimo ritorno d\'errore' );
		$this->assertLessThan( (int) strpos( $body, 'self::recall(' ), (int) strpos( $body, 'LicenseClient::enabled()' ) );
		$this->assertLessThan( (int) strpos( $body, 'LicenseClient::validate(' ), (int) strpos( $body, 'self::recall(' ), 'la memoria si consulta prima della chiamata' );
	}

	public function test_two_denials_rule_is_untouched(): void {
		$body = $this->sync_body();
		$this->assertStringContainsString( 'LicenseClient::denial_step( $first, $denial )', $body );
		$this->assertStringContainsString( 'if ( ! $step[\'apply\'] ) {', $body );
		$this->assertStringContainsString( '$r[\'reason_code\'] = $denial[\'rc\'];', $body );
		$client = $this->src( 'includes/LicenseClient.php' );
		$this->assertStringContainsString( "return array( 'apply' => \$day > \$old,", $client );
		$this->assertStringContainsString( 'const DENY_WINDOW = 604800;', $client );
	}

	public function test_check_now_and_new_key_skip_the_memory(): void {
		$boot = $this->src( 'includes/ProBoot.php' );
		$from = (int) strpos( $boot, 'public static function save_license(' );
		$save = substr( $boot, $from, (int) strpos( $boot, 'public static function license_check(' ) - $from );
		$this->assertStringContainsString( 'License::forget();', $save );
		$this->assertStringContainsString( 'License::sync( true )', $save );
		$check = substr( $boot, (int) strpos( $boot, 'public static function license_check(' ) );
		$this->assertStringContainsString( 'License::sync( true )', $check );
		$this->assertStringContainsString( "array( License::class, 'resync' )", $boot, 'il rinnovo dopo un aggiornamento chiede la risposta nuova' );
	}

	public function test_memory_is_cleared_on_key_unreadable_site_moved_deactivation_and_uninstall(): void {
		$lic = $this->src( 'includes/License.php' );
		$this->assertStringContainsString( 'self::forget(); // senza chiave leggibile', $lic );
		$this->assertStringContainsString( 'self::forget(); // sito copiato o spostato', $lic );
		$this->assertStringContainsString( 'hash_equals( $m[\'fp\'], $print )', $lic, 'chiave o dominio diversi: la memoria non vale' );
		$boot = $this->src( 'includes/ProBoot.php' );
		$from = (int) strpos( $boot, 'public static function deactivate()' );
		$this->assertStringContainsString( 'License::forget();', substr( $boot, $from, 400 ) );
		$this->assertStringContainsString( 'AssociazioneSemplice\\License::forget();', $this->src( 'associazionesemplice.php' ) );
		$un   = $this->src( 'uninstall.php' );
		$from = (int) strpos( $un, 'function asem_uninstall_secrets()' );
		$body = substr( $un, $from, (int) strpos( $un, 'function asem_uninstall_site()' ) - $from );
		$this->assertStringContainsString( "delete_option( 'asem_license_memo' );", $body, 'si pulisce anche se i dati restano' );	}
}
