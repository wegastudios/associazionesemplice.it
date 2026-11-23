<?php
use AssociazioneSemplice\SchemaGuard;
use PHPUnit\Framework\TestCase;

/** Cosa si rifiuta mentre lo schema è a metà (parte pura; la prova dentro WordPress è in tests/smoke_schema_e_export.php). */
final class SchemaGuardTest extends TestCase {

	public function test_nothing_is_blocked_when_the_schema_is_ready(): void {
		$this->assertFalse( SchemaGuard::action_blocked( 'asem_save_person', true ) );
		$this->assertFalse( SchemaGuard::rest_blocked( '/associazionesemplice/v1/webhooks/stripe', 'POST', true ) );
		$this->assertTrue( SchemaGuard::job_allowed( 'asem_send_reminders', true ) );
	}

	public function test_writes_are_blocked_when_the_schema_is_half_done(): void {
		foreach ( array( 'asem_save_person', 'asem_front_book', 'asem_long_job', 'asem_backup_restore', 'asem_checkin', 'asem_save_event' ) as $a ) {
			$this->assertTrue( SchemaGuard::action_blocked( $a, false ), $a );
		}
	}

	public function test_downloads_and_searches_stay_available(): void {
		foreach ( array( 'asem_export', 'asem_backup', 'asem_person_search', 'asem_event_slot', 'asem_cert_csv', 'asem_budget_csv', 'asem_card_pdf', 'asem_dismiss_notice' ) as $a ) {
			$this->assertFalse( SchemaGuard::action_blocked( $a, false ), $a );
		}
	}

	public function test_actions_of_other_plugins_are_not_touched(): void {
		$this->assertFalse( SchemaGuard::action_blocked( 'heartbeat', false ) );
		$this->assertFalse( SchemaGuard::action_blocked( '', false ) );
		$this->assertFalse( SchemaGuard::action_blocked( 'woocommerce_save', false ) );
	}

	public function test_rest_reads_pass_and_writes_are_refused_only_for_this_plugin(): void {
		$this->assertTrue( SchemaGuard::rest_blocked( '/associazionesemplice/v1/webhooks/stripe', 'POST', false ) );
		$this->assertTrue( SchemaGuard::rest_blocked( 'associazionesemplice/v1/me/bookings', 'delete', false ) );
		$this->assertFalse( SchemaGuard::rest_blocked( '/associazionesemplice/v1/me', 'GET', false ) );
		$this->assertFalse( SchemaGuard::rest_blocked( '/wp/v2/posts', 'POST', false ) );
	}

	public function test_only_the_upgrade_job_runs_while_the_schema_is_half_done(): void {
		$this->assertTrue( SchemaGuard::job_allowed( 'asem_schema_upgrade', false ) );
		$this->assertFalse( SchemaGuard::job_allowed( 'asem_send_reminders', false ) );
		$this->assertFalse( SchemaGuard::job_allowed( 'asem_purge_audit', false ) );
	}
}
