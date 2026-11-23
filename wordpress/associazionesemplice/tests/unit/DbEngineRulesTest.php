<?php
use AssociazioneSemplice\DbEngineRules;
use PHPUnit\Framework\TestCase;

/** Quali tabelle convertire e in che ordine (parte pura; la conversione vera sul database è provata da tests/smoke_db_engine.php). */
final class DbEngineRulesTest extends TestCase {

	public function test_innodb_and_views_are_ok_any_case(): void {
		$this->assertTrue( DbEngineRules::is_ok( 'InnoDB' ) );
		$this->assertTrue( DbEngineRules::is_ok( 'innodb' ) );
		$this->assertTrue( DbEngineRules::is_ok( null ) );
		$this->assertTrue( DbEngineRules::is_ok( '' ) );
		$this->assertFalse( DbEngineRules::is_ok( 'MyISAM' ) );
		$this->assertFalse( DbEngineRules::is_ok( 'Aria' ) );
	}

	public function test_to_convert_lists_only_the_others_smallest_first(): void {
		$rows = array(
			array( 'name' => 'wp_asem_b', 'engine' => 'MyISAM', 'bytes' => 5000 ),
			array( 'name' => 'wp_asem_ok', 'engine' => 'InnoDB', 'bytes' => 1 ),
			array( 'name' => 'wp_asem_a', 'engine' => 'MyISAM', 'bytes' => 100 ),
			array( 'name' => 'wp_asem_c', 'engine' => 'Aria', 'bytes' => 100 ),
			array( 'name' => 'wp_asem_view', 'engine' => null, 'bytes' => 0 ),
		);
		$this->assertSame( array( 'wp_asem_a', 'wp_asem_c', 'wp_asem_b' ), array_column( DbEngineRules::to_convert( $rows ), 'name' ) );
		$this->assertSame( array(), DbEngineRules::to_convert( array() ) );
	}

	public function test_engine_usable_only_when_yes_or_default(): void {
		$this->assertTrue( DbEngineRules::engine_usable( 'YES' ) );
		$this->assertTrue( DbEngineRules::engine_usable( 'DEFAULT' ) );
		$this->assertFalse( DbEngineRules::engine_usable( 'DISABLED' ) );
		$this->assertFalse( DbEngineRules::engine_usable( 'NO' ) );
		$this->assertFalse( DbEngineRules::engine_usable( '' ) );
	}

	public function test_only_plugin_tables_with_safe_names_are_touched(): void {
		$this->assertTrue( DbEngineRules::is_plugin_table( 'wp_asem_people', 'wp_' ) );
		$this->assertFalse( DbEngineRules::is_plugin_table( 'wp_posts', 'wp_' ) );
		$this->assertFalse( DbEngineRules::is_plugin_table( 'wp_asem_people`; DROP TABLE x', 'wp_' ) );
		$this->assertFalse( DbEngineRules::is_plugin_table( 'altro_asem_people', 'wp_' ) );
	}

	public function test_batch_always_tries_one_then_stops_at_limits(): void {
		$this->assertTrue( DbEngineRules::may_continue( 0, 0, 99.0, 4, 15.0 ) );
		$this->assertTrue( DbEngineRules::may_continue( 2, 2, 3.0, 4, 15.0 ) );
		$this->assertFalse( DbEngineRules::may_continue( 4, 4, 3.0, 4, 15.0 ) );
		$this->assertFalse( DbEngineRules::may_continue( 1, 1, 15.0, 4, 15.0 ) );
		$this->assertTrue( DbEngineRules::may_continue( 0, 3, 2.0, 4, 15.0 ), 'le tabelle che non si convertono non occupano un posto' );
		$this->assertFalse( DbEngineRules::may_continue( 0, 3, 20.0, 4, 15.0 ) );
	}
}
