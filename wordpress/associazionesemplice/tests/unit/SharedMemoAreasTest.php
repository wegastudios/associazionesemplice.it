<?php
use AssociazioneSemplice\SharedMemo;
use PHPUnit\Framework\TestCase;

/** Quali aree della cache tra richieste svuota una scrittura: solo quelle delle tabelle nominate, con il nome intero. */
final class SharedMemoAreasTest extends TestCase {

	public function test_a_write_on_an_area_table_names_that_area(): void {
		$this->assertSame( array( 'ledger_deltas' ), SharedMemo::areas_in( 'UPDATE `wp_asem_transactions` SET amount_cents = 1 WHERE id = 2', 'wp_' ) );
		$this->assertSame( array( 'fiscal_years' ), SharedMemo::areas_in( "INSERT INTO wp_asem_fiscal_years (`year`) VALUES (2026)", 'wp_' ) );
		$this->assertSame( array( 'calendar' ), SharedMemo::areas_in( 'DELETE FROM wp_asem_lesson_exceptions WHERE id = 1', 'wp_' ) );
	}

	public function test_a_table_shared_by_more_queries_names_every_area_once(): void {
		$areas = SharedMemo::areas_in( 'UPDATE wp_asem_people p JOIN wp_asem_memberships m ON m.person_id = p.id JOIN wp_asem_sessions s SET p.x = 1', 'wp_' );
		sort( $areas );
		$this->assertSame( array( 'calendar', 'people_light' ), $areas );
	}

	public function test_similar_names_and_other_prefixes_do_not_count(): void {
		$this->assertSame( array(), SharedMemo::areas_in( 'UPDATE wp_asem_people_tags SET x = 1', 'wp_' ) );
		$this->assertSame( array(), SharedMemo::areas_in( 'UPDATE wp_asem_member_sessions SET x = 1', 'wp_' ) );
		$this->assertSame( array(), SharedMemo::areas_in( 'UPDATE wp2_asem_transactions SET x = 1', 'wp_' ) );
		$this->assertSame( array(), SharedMemo::areas_in( 'UPDATE wp_asem_accounts SET x = 1', 'wp_' ) );
		$this->assertSame( array( 'ledger_deltas' ), SharedMemo::areas_in( 'UPDATE wp2_asem_transactions SET x = 1', 'wp2_' ) );
	}

	public function test_without_a_persistent_cache_remember_just_runs_the_reading(): void {
		$runs = 0;
		$fn   = function () use ( &$runs ) {
			return ++$runs;
		};
		$this->assertFalse( SharedMemo::enabled() );
		$this->assertSame( 1, SharedMemo::remember( 'calendar', 'k', $fn ) );
		$this->assertSame( 2, SharedMemo::remember( 'calendar', 'k', $fn ) );
	}
}
