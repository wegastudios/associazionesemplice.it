<?php
use AssociazioneSemplice\Calendar;
use AssociazioneSemplice\EventRequests;
use AssociazioneSemplice\Frontend\Front;
use AssociazioneSemplice\GuardianConsent;
use AssociazioneSemplice\Install;
use AssociazioneSemplice\Plugin;
use PHPUnit\Framework\TestCase;

/**
 * Sul sito pubblico le classi che servono solo a un link (parametro dell'indirizzo), all'aggiornamento dello schema, all'aggiornatore
 * o al menu di amministrazione non si leggono (la misura vera, con le richieste, è tests/ci/costo-fisso.sh).
 */
final class PublicRequestLoadTest extends TestCase {

	private static function src( string $rel ): string {
		return str_replace( "\r\n", "\n", (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/' . $rel ) );
	}

	public function test_schema_version_is_one_number_compared_without_loading_install(): void {
		$this->assertSame( Plugin::DB_VERSION, Install::DB_VERSION );
		$this->assertSame( Plugin::DB_VERSION_OPTION, Install::DB_VERSION_OPTION );
		$this->assertMatchesRegularExpression( '/if \( self::DB_VERSION !== \(string\) get_option\( self::DB_VERSION_OPTION, \'\' \) \) \{\s*Install::maybe_upgrade\(\);/', self::src( 'Plugin.php' ) );
	}

	public function test_pro_schema_version_is_one_number_compared_without_loading_the_schema(): void {
		if ( ! is_readable( dirname( __DIR__, 2 ) . '/includes/ProBoot.php' ) ) {
			$this->markTestSkipped( 'edizione senza il Pro' );
		}
		$this->assertSame( \AssociazioneSemplice\ProBoot::SCHEMA_VERSION, \AssociazioneSemplice\ProSchema::VERSION );
		$this->assertSame( \AssociazioneSemplice\ProBoot::SCHEMA_OPTION, \AssociazioneSemplice\ProSchema::VERSION_OPTION );
		$this->assertSame( \AssociazioneSemplice\ProBoot::RECEIPT_BACKFILL_OPTION, \AssociazioneSemplice\ReceiptFiles::FLAG );
		$boot = self::src( 'ProBoot.php' );
		$this->assertStringContainsString( "add_action( '" . \AssociazioneSemplice\ReceiptFiles::CRON . "', array( ReceiptFiles::class, 'cron_batch' ), 10, 2 );", $boot );
		$this->assertStringNotContainsString( 'ProSchema::register(', $boot );
		$this->assertStringNotContainsString( 'ReceiptFiles::register(', $boot );
		$this->assertMatchesRegularExpression( '/if \( self::backend\(\) \) \{\s*Admin\\\\ProMenu::register\(\);/', $boot );
	}

	/** Ogni parametro della mappa è proprio quello che il suo gestore controlla, e il gestore esiste. */
	public function test_every_link_parameter_is_the_one_its_handler_checks(): void {
		$consts = array(
			'asem_ical'        => Calendar::QUERY_VAR,
			'asem_evreq'       => EventRequests::PARAM,
			'asem_guardian_cf' => GuardianConsent::PARAM,
		);
		foreach ( Front::LINKS as $param => $handler ) {
			$this->assertTrue( method_exists( $handler[0], $handler[1] ), "$param: {$handler[0]}::{$handler[1]} esiste" );
			if ( isset( $consts[ $param ] ) ) {
				$this->assertSame( $param, $consts[ $param ] );
				continue;
			}
			$rel = str_replace( array( 'AssociazioneSemplice\\', '\\' ), array( '', '/' ), $handler[0] ) . '.php';
			$this->assertStringContainsString( "\$_GET['$param']", self::src( $rel ), "$param: lo controlla $rel" );
		}
	}

	/** I gestori dei link non si registrano più da soli su template_redirect: li smista solo Front::links(). */
	public function test_link_handlers_are_dispatched_only_from_the_map(): void {
		$seen = array();
		foreach ( Front::LINKS as $handler ) {
			$rel = str_replace( array( 'AssociazioneSemplice\\', '\\' ), array( '', '/' ), $handler[0] ) . '.php';
			if ( isset( $seen[ $rel ] ) ) {
				continue;
			}
			$seen[ $rel ] = true;
			$this->assertStringNotContainsString( "'template_redirect'", self::src( $rel ), "$rel non si aggancia da solo a template_redirect" );
		}
		$front = self::src( 'Frontend/Front.php' );
		$this->assertStringContainsString( "add_action( 'template_redirect', array( __CLASS__, 'links' ), 1 );", $front );
		$this->assertStringNotContainsString( 'EventRequests::register(', self::src( 'PublicEvents.php' ) );
	}

	/** L'aggiornatore si registra subito solo in bacheca, cron e WP-CLI; sul sito pubblico solo quando WordPress lavora sugli aggiornamenti. */
	public function test_updater_is_not_loaded_on_public_requests(): void {
		$plugin = self::src( 'Plugin.php' );
		$boot   = self::src( 'UpdaterBoot.php' );
		$this->assertStringNotContainsString( 'Updater::register()', $plugin );
		$this->assertStringContainsString( 'UpdaterBoot::register( self::backend() );', $plugin );
		$this->assertMatchesRegularExpression( '/if \( \$backend \) \{\s*Updater::register\(\);\s*return;\s*\}/', $boot );
		$this->assertStringNotContainsString( "class_exists( __NAMESPACE__ . '\\Updater' )", $plugin . self::src( 'Limits.php' ) );
		// il pacchetto per WordPress.org non deve nominare gli hook degli aggiornamenti: stanno solo in UpdaterBoot, che lì non c'è
		$this->assertStringNotContainsString( 'site_transient_update_plugins', $plugin );
		$this->assertStringContainsString( 'includes/UpdaterBoot.php', (string) file_get_contents( dirname( __DIR__, 3 ) . '/build.sh' ) );
		$pro = str_replace( "\r\n", "\n", (string) file_get_contents( dirname( __DIR__, 3 ) . '/associazionesemplice-pro/associazionesemplice-pro.php' ) );
		$this->assertMatchesRegularExpression( '/if \( is_admin\(\) \|\| wp_doing_cron\(\) \|\| \( defined\( \'WP_CLI\' \) && WP_CLI \) \) \{\s*asem_pro_updater\(\);\s*\} else \{/', $pro );
		foreach ( \AssociazioneSemplice\UpdaterBoot::HOOKS as $hook ) {
			$this->assertStringContainsString( "'$hook'", $pro, "il Pro carica l'aggiornatore anche con $hook" );
		}
	}
}
