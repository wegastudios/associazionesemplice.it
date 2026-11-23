<?php
use AssociazioneSemplice\ServerStatusRules as R;
use PHPUnit\Framework\TestCase;

/** Le regole della scheda «Stato del server»: verde sul consigliato, giallo sul minimo, rosso sotto il minimo o con una funzione spenta. */
final class ServerStatusRulesTest extends TestCase {

	private function status( array $env, string $key ): ?string {
		$r = R::find( R::rows( $env ), $key );
		return $r ? $r['status'] : null;
	}

	public function test_missing_values_give_no_row(): void {
		$this->assertSame( array(), R::rows( array() ) );
	}

	public function test_php_versions(): void {
		$this->assertSame( R::BAD, $this->status( array( 'php' => '7.3.30' ), 'php' ) );
		$this->assertSame( R::WARN, $this->status( array( 'php' => '7.4.33' ), 'php' ) );
		$this->assertSame( R::WARN, $this->status( array( 'php' => '8.1.2' ), 'php' ) );
		$this->assertSame( R::OK, $this->status( array( 'php' => '8.2.0' ), 'php' ) );
		$this->assertSame( R::OK, $this->status( array( 'php' => '8.3.12-1+ubuntu' ), 'php' ) );
	}

	public function test_wordpress_versions(): void {
		$this->assertSame( R::BAD, $this->status( array( 'wp' => '5.9' ), 'wp' ) );
		$this->assertSame( R::WARN, $this->status( array( 'wp' => '6.2.1' ), 'wp' ) );
		$this->assertSame( R::OK, $this->status( array( 'wp' => '6.8' ), 'wp' ) );
	}

	public function test_opcache(): void {
		$this->assertSame( R::OK, $this->status( array( 'opcache' => true ), 'opcache' ) );
		$row = R::find( R::rows( array( 'opcache' => false ) ), 'opcache' );
		$this->assertSame( R::WARN, $row['status'] );
		$this->assertStringContainsString( 'OPcache', $row['advice'] );
	}

	public function test_memory_and_time_thresholds(): void {
		$this->assertSame( R::BAD, $this->status( array( 'memory' => 64 * R::MB ), 'memory' ) );
		$this->assertSame( R::WARN, $this->status( array( 'memory' => 128 * R::MB ), 'memory' ) );
		$this->assertSame( R::OK, $this->status( array( 'memory' => 256 * R::MB ), 'memory' ) );
		$this->assertSame( R::OK, $this->status( array( 'memory' => -1 ), 'memory' ) );
		$this->assertSame( R::BAD, $this->status( array( 'time' => 20 ), 'time' ) );
		$this->assertSame( R::WARN, $this->status( array( 'time' => 30 ), 'time' ) );
		$this->assertSame( R::OK, $this->status( array( 'time' => 60 ), 'time' ) );
		$this->assertSame( R::OK, $this->status( array( 'time' => 0 ), 'time' ) );
	}

	public function test_upload_limit_and_its_text(): void {
		$this->assertSame( R::BAD, $this->status( array( 'upload' => R::MB ), 'upload' ) );
		$row = R::find( R::rows( array( 'upload' => 8 * R::MB ) ), 'upload' );
		$this->assertSame( R::WARN, $row['status'] );
		$this->assertSame( '8 MB', $row['value'] );
		$this->assertStringContainsString( '8 MB', $row['advice'] );
		$this->assertSame( R::OK, $this->status( array( 'upload' => 64 * R::MB ), 'upload' ) );
	}

	public function test_database_versions_mysql_and_mariadb(): void {
		$this->assertSame( R::BAD, $this->status( array( 'db_version' => '5.6.40' ), 'mysql' ) );
		$this->assertSame( R::WARN, $this->status( array( 'db_version' => '5.7.44' ), 'mysql' ) );
		$this->assertSame( R::OK, $this->status( array( 'db_version' => '8.0.36' ), 'mysql' ) );
		$this->assertSame( R::BAD, $this->status( array( 'db_version' => '5.5.5', 'db_maria' => true ), 'mysql' ) ); // sotto il minimo di MariaDB 10.0.2
		$this->assertSame( R::WARN, $this->status( array( 'db_version' => '10.4.32', 'db_maria' => true ), 'mysql' ) );
		$this->assertSame( R::OK, $this->status( array( 'db_version' => '10.11.6', 'db_maria' => true ), 'mysql' ) );
		$row = R::find( R::rows( array( 'db_version' => '10.6.12-MariaDB', 'db_maria' => true ) ), 'mysql' );
		$this->assertSame( 'MariaDB 10.6.12', $row['value'] );
		$this->assertNull( $this->status( array( 'db_version' => '' ), 'mysql' ) );
	}

	public function test_table_engine_row_links_to_the_conversion_only_when_innodb_is_offered(): void {
		$this->assertSame( R::OK, $this->status( array( 'db_engine' => array( 'total' => 30, 'bad' => 0, 'innodb' => true ) ), 'db_engine' ) );
		$row = R::find( R::rows( array( 'db_engine' => array( 'total' => 30, 'bad' => 3, 'innodb' => true ) ) ), 'db_engine' );
		$this->assertSame( R::WARN, $row['status'] );
		$this->assertSame( 'asem-backup', $row['page'] );
		$this->assertSame( 'asem-db-engine', $row['anchor'] );
		$this->assertStringContainsString( '3 tabelle su 30', $row['value'] );
		$none = R::find( R::rows( array( 'db_engine' => array( 'total' => 30, 'bad' => 3, 'innodb' => false ) ) ), 'db_engine' );
		$this->assertArrayNotHasKey( 'page', $none );
	}

	public function test_packet_size(): void {
		$this->assertSame( R::BAD, $this->status( array( 'packet' => R::MB ), 'packet' ) );
		$this->assertSame( R::WARN, $this->status( array( 'packet' => 4 * R::MB ), 'packet' ) );
		$this->assertSame( R::OK, $this->status( array( 'packet' => 16 * R::MB ), 'packet' ) );
		$this->assertNull( $this->status( array( 'packet' => 0 ), 'packet' ) );
	}

	public function test_caches(): void {
		$this->assertSame( R::OK, $this->status( array( 'object_cache' => true ), 'object_cache' ) );
		$this->assertSame( R::WARN, $this->status( array( 'object_cache' => false ), 'object_cache' ) );
		$row = R::find( R::rows( array( 'page_cache' => 'WP Rocket' ) ), 'page_cache' );
		$this->assertSame( R::OK, $row['status'] );
		$this->assertStringContainsString( 'WP Rocket', $row['value'] );
		$this->assertSame( R::WARN, $this->status( array( 'page_cache' => '' ), 'page_cache' ) );
	}

	public function test_cron_running_stale_and_never_started(): void {
		$now = 1800000000;
		$url = 'https://example.org/wp-cron.php?doing_wp_cron';
		$ok  = R::find( R::rows( array( 'cron_last' => $now - 1200, 'now' => $now, 'cron_url' => $url ) ), 'cron' );
		$this->assertSame( R::OK, $ok['status'] );
		$this->assertStringContainsString( '20 minuti', $ok['value'] );
		$this->assertSame( '', $ok['advice'] );

		$stale = R::find( R::rows( array( 'cron_last' => $now - 5 * 3600, 'now' => $now, 'cron_url' => $url ) ), 'cron' );
		$this->assertSame( R::BAD, $stale['status'] );
		$this->assertStringContainsString( '5 ore', $stale['value'] );
		$this->assertStringContainsString( $url, $stale['code'] );
		$this->assertStringStartsWith( '*/5 * * * *', $stale['code'] );

		$off = R::find( R::rows( array( 'cron_last' => $now - 5 * 3600, 'now' => $now, 'cron_disabled' => true, 'cron_url' => $url ) ), 'cron' );
		$this->assertStringContainsString( 'DISABLE_WP_CRON', $off['advice'] );

		$new = R::find( R::rows( array( 'cron_last' => 0, 'now' => $now, 'cron_url' => $url ) ), 'cron' );
		$this->assertSame( R::WARN, $new['status'] );
		$this->assertNotEmpty( $new['code'] );

		$edge = R::find( R::rows( array( 'cron_last' => $now - 3 * 3600, 'now' => $now ) ), 'cron' ); // esattamente 3 ore: ancora regolare
		$this->assertSame( R::OK, $edge['status'] );
	}

	public function test_extensions_and_https(): void {
		$this->assertSame( R::BAD, $this->status( array( 'sodium' => false ), 'sodium' ) );
		$this->assertSame( R::OK, $this->status( array( 'sodium' => true ), 'sodium' ) );
		$this->assertSame( R::BAD, $this->status( array( 'zip' => false ), 'zip' ) );
		$this->assertSame( R::BAD, $this->status( array( 'mbstring' => false ), 'mbstring' ) );
		$this->assertSame( R::OK, $this->status( array( 'missing_ext' => array() ), 'other_ext' ) );
		$row = R::find( R::rows( array( 'missing_ext' => array( 'intl', 'iconv' ) ) ), 'other_ext' );
		$this->assertSame( R::WARN, $row['status'] );
		$this->assertSame( 'Mancano: intl, iconv', $row['value'] );
		$this->assertSame( R::BAD, $this->status( array( 'https' => false ), 'https' ) );
		$this->assertSame( R::OK, $this->status( array( 'https' => true ), 'https' ) );
	}

	public function test_summary_worst_and_ok_rows_carry_no_advice(): void {
		$rows = R::rows( array( 'php' => '8.3.1', 'opcache' => false, 'https' => false ) );
		$this->assertSame( array( 'bad' => 1, 'warn' => 1, 'ok' => 1 ), R::summary( $rows ) );
		$this->assertSame( R::BAD, R::worst( array( R::OK, R::BAD, R::WARN ) ) );
		$this->assertSame( R::WARN, R::worst( array( R::OK, R::WARN ) ) );
		$this->assertSame( R::OK, R::worst( array() ) );
		$this->assertSame( '', R::find( $rows, 'php' )['advice'] );
	}

	public function test_ago_and_mb_formats(): void {
		$this->assertSame( 'meno di un minuto', R::ago( 10 ) );
		$this->assertSame( 'un minuto', R::ago( 70 ) );
		$this->assertSame( '3 ore', R::ago( 3 * 3600 + 5 ) );
		$this->assertSame( 'un giorno', R::ago( 90000 ) );
		$this->assertSame( '2 giorni', R::ago( 2 * 86400 ) );
		$this->assertSame( '2,5', R::mb( (int) ( 2.5 * R::MB ) ) );
		$this->assertSame( '20', R::mb( 20 * R::MB ) );
	}
}
