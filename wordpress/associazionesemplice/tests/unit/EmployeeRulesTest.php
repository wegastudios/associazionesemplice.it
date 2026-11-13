<?php
use AssociazioneSemplice\EmployeeRules;
use PHPUnit\Framework\TestCase;

final class EmployeeRulesTest extends TestCase {

	public function test_the_removable_permissions_are_exactly_these_six(): void {
		$this->assertSame( array( 'courses', 'events', 'collect', 'expenses', 'refunds', 'funds' ), EmployeeRules::keys() );
		$this->assertCount( 6, EmployeeRules::labels() );
		foreach ( EmployeeRules::keys() as $k ) {
			$this->assertTrue( EmployeeRules::is_removable( $k ) );
			$this->assertNotSame( '', EmployeeRules::label( $k ) );
		}
		foreach ( array( 'people', 'asem_manage', 'asem_operate', 'members', 'settings', '' ) as $other ) {
			$this->assertFalse( EmployeeRules::is_removable( $other ), $other );
		}
	}

	public function test_sanitize_keeps_only_removable_permissions_without_duplicates(): void {
		$this->assertSame( array( 'courses', 'funds' ), EmployeeRules::sanitize_denied( array( 'funds', 'asem_manage', 'courses', 'courses', 'people' ) ) );
		$this->assertSame( array(), EmployeeRules::sanitize_denied( 'courses' ) );
		$this->assertSame( array(), EmployeeRules::sanitize_denied( null ) );
	}

	public function test_without_removals_everything_is_allowed_like_the_secretary(): void {
		foreach ( EmployeeRules::keys() as $k ) {
			$this->assertTrue( EmployeeRules::has( array(), $k ), $k );
		}
		foreach ( array( 'asem-activities', 'asem-income', 'asem-expense', 'asem-refunds', 'asem-money', 'asem-ledger' ) as $page ) {
			$this->assertNull( EmployeeRules::blocked_by( array(), EmployeeRules::page_need( $page ) ), $page );
		}
	}

	public function test_removing_one_permission_closes_only_its_own_pages(): void {
		$pages = array(
			'collect'  => array( 'asem-income', 'asem-group' ),
			'expenses' => array( 'asem-expense' ),
			'refunds'  => array( 'asem-refunds' ),
			'funds'    => array( 'asem-money', 'asem-ledger', 'asem-accounts', 'asem-transfer', 'asem-watch', 'asem-accounting', 'asem-reports', 'asem-statement' ),
			'events'   => array( 'asem-bookdesk' ),
		);
		foreach ( $pages as $key => $slugs ) {
			foreach ( $slugs as $slug ) {
				$this->assertSame( $key, EmployeeRules::blocked_by( array( $key ), EmployeeRules::page_need( $slug ) ), "$key / $slug" );
				foreach ( array_diff( EmployeeRules::keys(), array( $key ) ) as $other ) {
					$this->assertNull( EmployeeRules::blocked_by( array( $other ), EmployeeRules::page_need( $slug ) ), "$other / $slug" );
				}
			}
		}
	}

	public function test_activity_pages_need_at_least_one_of_courses_or_events(): void {
		$need = EmployeeRules::page_need( 'asem-activities' );
		$this->assertNull( EmployeeRules::blocked_by( array( 'courses' ), $need ) );
		$this->assertNull( EmployeeRules::blocked_by( array( 'events' ), $need ) );
		$this->assertSame( 'courses', EmployeeRules::blocked_by( array( 'courses', 'events' ), $need ) );
	}

	public function test_pages_of_fixed_permissions_are_never_blocked(): void {
		$all = EmployeeRules::keys();
		foreach ( array( 'asem-people', 'asem-person', 'asem-book', 'asem-runts', 'asem-minutes', 'asem-documents', 'asem-messages', 'asem', 'asem-inventory' ) as $page ) {
			$this->assertNull( EmployeeRules::page_need( $page ), $page );
			$this->assertNull( EmployeeRules::blocked_by( $all, EmployeeRules::page_need( $page ) ), $page );
		}
	}

	public function test_actions_follow_the_same_permissions(): void {
		$this->assertSame( 'collect', EmployeeRules::blocked_by( array( 'collect' ), EmployeeRules::action_need( 'asem_save_income' ) ) );
		$this->assertNull( EmployeeRules::blocked_by( array( 'expenses', 'funds' ), EmployeeRules::action_need( 'asem_save_income' ) ) );
		$this->assertSame( 'expenses', EmployeeRules::blocked_by( array( 'expenses' ), EmployeeRules::action_need( 'asem_save_expense' ) ) );
		$this->assertSame( 'refunds', EmployeeRules::blocked_by( array( 'refunds' ), EmployeeRules::action_need( 'asem_refund_pay' ) ) );
		$this->assertSame( 'refunds', EmployeeRules::blocked_by( array( 'refunds' ), EmployeeRules::action_need( 'asem_refunds_csv' ) ) );
		$this->assertSame( 'funds', EmployeeRules::blocked_by( array( 'funds' ), EmployeeRules::action_need( 'asem_cash_count' ) ) );
		$this->assertSame( 'funds', EmployeeRules::blocked_by( array( 'funds' ), EmployeeRules::export_need( 'ledger' ) ) );
		$this->assertSame( 'funds', EmployeeRules::blocked_by( array( 'funds' ), EmployeeRules::export_need( 'period' ) ) );
		$this->assertNull( EmployeeRules::export_need( 'people' ), 'l\'elenco soci non dipende da nessun permesso rimovibile' );
		$this->assertNull( EmployeeRules::action_need( 'asem_save_person' ) );
		$this->assertContains( 'asem_save_activity', EmployeeRules::activity_actions() );
	}

	public function test_attachments_need_any_money_permission(): void {
		$need = EmployeeRules::action_need( 'asem_add_attachment' );
		$this->assertNull( EmployeeRules::blocked_by( array( 'collect', 'expenses' ), $need ) );
		$this->assertNotNull( EmployeeRules::blocked_by( array( 'collect', 'expenses', 'refunds' ), $need ) );
	}

	public function test_abilities_map_to_permissions_by_activity_kind(): void {
		$this->assertSame( 'expenses', EmployeeRules::blocked_by( array( 'expenses' ), EmployeeRules::ability_need( 'asem_add_expense', null ) ) );
		$this->assertSame( 'collect', EmployeeRules::blocked_by( array( 'collect' ), EmployeeRules::ability_need( 'asem_collect', null ) ) );
		$this->assertSame( 'collect', EmployeeRules::blocked_by( array( 'collect' ), EmployeeRules::ability_need( 'asem_door_cash', 'event' ) ) );
		$this->assertSame( 'courses', EmployeeRules::blocked_by( array( 'courses' ), EmployeeRules::ability_need( 'asem_view_participants', 'course' ) ) );
		$this->assertNull( EmployeeRules::blocked_by( array( 'courses' ), EmployeeRules::ability_need( 'asem_view_participants', 'event' ) ) );
		$this->assertSame( 'events', EmployeeRules::blocked_by( array( 'events' ), EmployeeRules::ability_need( 'asem_manage_event', 'recurring' ) ) );
		$this->assertNull( EmployeeRules::ability_need( 'asem_view_person', null ), 'le schede dei soci restano fisse' );
	}

	public function test_dates_are_validated(): void {
		$this->assertSame( '2026-02-28', EmployeeRules::clean_date( '2026-02-28' ) );
		$this->assertNull( EmployeeRules::clean_date( '2026-02-30' ) );
		$this->assertNull( EmployeeRules::clean_date( '28/02/2026' ) );
		$this->assertNull( EmployeeRules::clean_date( '' ) );
		$this->assertNull( EmployeeRules::clean_date( null ) );
	}

	public function test_without_dates_the_employee_is_always_in_service(): void {
		$this->assertTrue( EmployeeRules::is_active( null, null, '2001-01-01' ) );
		$this->assertTrue( EmployeeRules::is_active( '', '', '2099-12-31' ) );
	}

	public function test_start_and_end_days_are_included(): void {
		$this->assertFalse( EmployeeRules::is_active( '2026-03-01', '2026-06-30', '2026-02-28' ) );
		$this->assertTrue( EmployeeRules::is_active( '2026-03-01', '2026-06-30', '2026-03-01' ) );
		$this->assertTrue( EmployeeRules::is_active( '2026-03-01', '2026-06-30', '2026-06-30' ) );
		$this->assertFalse( EmployeeRules::is_active( '2026-03-01', '2026-06-30', '2026-07-01' ) );
		$this->assertTrue( EmployeeRules::is_active( '2026-03-01', null, '2030-01-01' ) );
		$this->assertFalse( EmployeeRules::is_active( null, '2026-06-30', '2026-07-01' ) );
	}

	public function test_workers_count_at_a_date(): void {
		$rows = array(
			array( 'from' => null, 'to' => null ),
			array( 'from' => '2026-03-01', 'to' => '2026-06-30' ),
			array( 'from' => '2027-01-01', 'to' => null ),
			array( 'from' => null, 'to' => '2025-12-31' ),
		);
		$this->assertSame( 2, EmployeeRules::count_active( $rows, '2025-12-31' ) ); // il primo e l'ultimo
		$this->assertSame( 2, EmployeeRules::count_active( $rows, '2026-03-01' ) );
		$this->assertSame( 1, EmployeeRules::count_active( $rows, '2026-07-01' ) );
		$this->assertSame( 2, EmployeeRules::count_active( $rows, '2027-01-01' ) );
		$this->assertSame( 0, EmployeeRules::count_active( array(), '2026-12-31' ) );
	}

	public function test_denied_message_names_the_permission_and_who_can_restore_it(): void {
		$m = EmployeeRules::denied_message( 'collect' );
		$this->assertStringContainsString( 'Incassi', $m );
		$this->assertStringContainsString( 'presidente', $m );
		$this->assertStringContainsString( 'amministratore', $m );
	}
}
