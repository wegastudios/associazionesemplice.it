<?php
use AssociazioneSemplice\Install;
use PHPUnit\Framework\TestCase;

/** L'aggiornamento dello schema si fa a tappe e solo dove c'è chi può aspettarlo (amministrazione, cron, riga di comando). */
final class SchemaUpgradeStepsTest extends TestCase {

	public function test_a_visitor_request_does_not_run_the_upgrade(): void {
		$this->assertFalse( Install::may_upgrade_now( false, false, false ), 'un visitatore non esegue l\'aggiornamento: si programma' );
		$this->assertTrue( Install::may_upgrade_now( true, false, false ), 'in amministrazione sì' );
		$this->assertTrue( Install::may_upgrade_now( false, true, false ), 'dalla riga di comando sì' );
		$this->assertTrue( Install::may_upgrade_now( false, false, true ), 'dal cron sì' );
	}

	public function test_steps_are_the_same_work_as_before_in_the_same_order(): void {
		$names = Install::step_names( '' );
		$this->assertSame( array( 'tables', 'indexes', 'roles', 'seed', 'year_closures', 'minute_numbers', 'member_book', 'wizard' ), $names, 'nuova installazione: solo i passaggi di base' );
		$from67 = Install::step_names( '67' );
		$this->assertSame( $names, array_slice( $from67, 0, count( $names ) ), 'prima i passaggi di base, poi le migrazioni' );
		$this->assertSame( array( 'realign_memberships', 'activity_fee_name' ), array_slice( $from67, count( $names ) ), 'dalla 67 restano le migrazioni 71 e 72' );
		$old = Install::step_names( '2' );
		$this->assertSame( 'legacy_fee', end( $old ),'sotto la 3 si copia anche il contributo, per ultimo' );
		$this->assertContains( 'whatsapp_button', Install::step_names( '60' ) );
		$this->assertNotContains( 'whatsapp_button', $from67, 'una migrazione già fatta non si ripete' );
	}

	public function test_the_job_is_registered_and_cleaned_with_the_others(): void {
		$plugin = (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/Plugin.php' );
		$this->assertMatchesRegularExpression( "/'asem_schema_upgrade'\s*=>\s*array\(\s*'',\s*0,\s*'Install',\s*'maybe_upgrade'/", $plugin );
		$this->assertContains( 'asem_schema_upgrade', Install::JOB_HOOKS );
	}

	public function test_the_version_is_written_only_at_the_end(): void {
		$src = (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/Install.php' );
		$this->assertSame( 2, substr_count( $src, 'update_option( self::DB_VERSION_OPTION, self::DB_VERSION' ), 'solo activate() e la fine dell\'aggiornamento scrivono la versione' );
	}
}
