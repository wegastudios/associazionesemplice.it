<?php
use AssociazioneSemplice\Install;
use AssociazioneSemplice\Jobs;
use AssociazioneSemplice\Plugin;
use PHPUnit\Framework\TestCase;

/** Lavori periodici: due soli eventi e un giro in fila (la prova con WordPress vero è tests/smoke_lavori.php). */
final class JobsTest extends TestCase {

	private static function src( string $rel ): string {
		return str_replace( "\r\n", "\n", (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/' . $rel ) );
	}

	public function test_hourly_jobs_run_every_hour_in_the_hourly_round_only(): void {
		$now = 1800000000;
		$this->assertTrue( Jobs::due( 'hourly', 0, $now, 'hourly' ) );
		$this->assertTrue( Jobs::due( 'hourly', $now - 3600 + Jobs::EARLY, $now, 'hourly' ) );
		$this->assertFalse( Jobs::due( 'hourly', $now - 3600 + Jobs::EARLY + 1, $now, 'hourly' ) );
		$this->assertFalse( Jobs::due( 'hourly', 0, $now, 'daily' ) );
	}

	public function test_daily_jobs_run_in_the_daily_round_and_late_ones_in_the_hourly_round(): void {
		$now = 1800000000;
		$this->assertTrue( Jobs::due( 'daily', 0, $now, 'daily' ) );
		$this->assertTrue( Jobs::due( 'daily', $now - 86400, $now, 'daily' ) );
		$this->assertFalse( Jobs::due( 'daily', $now - 80000, $now, 'daily' ) );
		$this->assertFalse( Jobs::due( 'daily', $now - 86400, $now, 'hourly' ) ); // il giro giornaliero è ancora in tempo
		$this->assertTrue( Jobs::due( 'daily', $now - 86400 - 3600, $now, 'hourly' ) ); // rimasto indietro di oltre un'ora
		$this->assertFalse( Jobs::due( '', 0, $now, 'hourly' ) ); // i lotti che si programmano da soli non sono nel giro
		$this->assertFalse( Jobs::due( 'weekly', 0, $now, 'hourly' ) );
	}

	public function test_budget_leaves_room_on_small_hostings(): void {
		$this->assertLessThanOrEqual( 20, Jobs::BUDGET );
		$this->assertGreaterThan( 0, Jobs::BUDGET );
	}

	/** A ogni richiesta del sito nessun controllo per lavoro: solo il segnale del giorno, già in memoria. */
	public function test_requests_do_not_check_each_job(): void {
		$plugin = self::src( 'Plugin.php' );
		$from   = (int) strpos( $plugin, 'private static function schedule_jobs()' );
		$body   = substr( $plugin, $from, (int) strpos( $plugin, 'const JOBS_HOURLY', $from ) - $from );
		$this->assertStringNotContainsString( 'wp_next_scheduled', $body );
		$this->assertStringNotContainsString( 'wp_schedule_event', $body );
		$this->assertStringContainsString( 'BootState::get( self::JOBS_CHECKED )', $body );
		$this->assertStringContainsString( 'Jobs::ensure_scheduled()', $body );
		$this->assertSame( 'asem_cron_hourly', Plugin::JOBS_HOURLY );
		$this->assertSame( 'asem_cron_daily', Plugin::JOBS_DAILY );
	}

	public function test_the_two_events_are_cleared_on_deactivation_and_rescheduled_after(): void {
		$this->assertContains( Plugin::JOBS_HOURLY, Install::JOB_HOOKS );
		$this->assertContains( Plugin::JOBS_DAILY, Install::JOB_HOOKS );
		$this->assertStringContainsString( 'BootState::forget( Plugin::JOBS_CHECKED )', self::src( 'Install.php' ) );
	}

	/** Il Pro: i nomi scritti per non caricare Sms e Newsletter a ogni richiesta sono quelli veri; i suoi lavori entrano nel giro. */
	public function test_pro_job_names_match_and_join_the_round(): void {
		if ( ! is_readable( dirname( __DIR__, 2 ) . '/includes/ProBoot.php' ) ) {
			$this->markTestSkipped( 'edizione senza il Pro' );
		}
		$this->assertSame( \AssociazioneSemplice\Sms::HOURLY, \AssociazioneSemplice\ProBlockE::SMS_JOB );
		$e = self::src( 'ProBlockE.php' );
		$this->assertStringContainsString( "add_action( '" . \AssociazioneSemplice\Sms::HOOK . "', array( Sms::class, 'job' ) );", $e );
		$this->assertStringContainsString( "add_action( '" . \AssociazioneSemplice\Newsletter::HOOK . "', array( Newsletter::class, 'job' ) );", $e );
		$from = (int) strpos( $e, 'public static function register()' );
		$reg  = substr( $e, $from, (int) strpos( $e, 'public static function schedule()' ) - $from );
		$this->assertStringNotContainsString( 'Sms::HOOK', str_replace( '// = Sms::HOOK', '', $reg ) );
		$this->assertStringNotContainsString( 'Newsletter::HOOK', str_replace( '// = Newsletter::HOOK', '', $reg ) );
		$jobs = \AssociazioneSemplice\ProBoot::jobs( array() );
		foreach ( array( 'asem_license_check' => 'daily', 'asem_update_check' => 'hourly', 'asem_sms_hourly' => 'hourly', 'asem_newsletter_sync' => 'daily' ) as $hook => $freq ) {
			$this->assertSame( $freq, $jobs[ $hook ][0] ?? null, $hook );
		}
		$this->assertTrue( \AssociazioneSemplice\ProBoot::free_runs_jobs() );
	}
}
