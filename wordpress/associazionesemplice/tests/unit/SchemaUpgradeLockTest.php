<?php
use AssociazioneSemplice\Db;
use AssociazioneSemplice\Install;
use PHPUnit\Framework\TestCase;

/** L'aggiornamento dello schema gira una volta sola anche con due richieste insieme (il blocco vero è provato da tests/smoke.php). */
final class SchemaUpgradeLockTest extends TestCase {

	private static function source(): string {
		return (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/Install.php' );
	}

	public function test_upgrade_is_needed_only_when_version_differs(): void {
		$this->assertFalse( Install::upgrade_needed( Install::DB_VERSION ) );
		$this->assertTrue( Install::upgrade_needed( '' ) );
		$this->assertTrue( Install::upgrade_needed( (string) ( (int) Install::DB_VERSION - 1 ) ) );
	}

	public function test_lock_name_is_per_site_and_fits_mysql(): void {
		$a = Db::lock_name( Install::UPGRADE_LOCK, 'sito_a|wp_' );
		$b = Db::lock_name( Install::UPGRADE_LOCK, 'sito_b|wp_' );
		$c = Db::lock_name( Install::UPGRADE_LOCK, 'sito_a|wp_2_' );
		$this->assertNotSame( $a, $b );
		$this->assertNotSame( $a, $c );
		$this->assertLessThanOrEqual( 64, strlen( $a ) );
		$this->assertSame( $a, Db::lock_name( Install::UPGRADE_LOCK, 'sito_a|wp_' ) );
	}

	public function test_maybe_upgrade_runs_inside_the_lock_and_rereads_the_version(): void {
		$src = self::source();
		$this->assertStringContainsString( 'Db::with_lock(', $src );
		$this->assertStringContainsString( 'self::UPGRADE_LOCK', $src );
		// dentro il blocco la versione si rilegge dal database, non dalla cache delle opzioni
		$this->assertMatchesRegularExpression( '/function \\(\\) use \\( &\\$started, \\$budget \\) \\{.*SELECT option_value FROM .*self::run_upgrade\\( \\$fresh, /s', $src );
	}

	public function test_a_lock_not_obtained_does_not_stop_the_site(): void {
		$src = self::source();
		// l'attesa è breve e, se il blocco resta occupato, la richiesta prosegue; un errore dell'aggiornamento stesso invece non si nasconde
		$this->assertMatchesRegularExpression( '/\\},\\s*10\\s*\\);\\s*\\} catch \\( \\\\InvalidArgumentException \\$e \\) \\{\\s*if \\( \\$started \\) \\{\\s*throw \\$e;/', $src );
	}
}
