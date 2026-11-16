<?php
use AssociazioneSemplice\AttemptLimit;
use PHPUnit\Framework\TestCase;

/** Limite alle password errate: memoria e orologio finti, nessun WordPress. */
final class AttemptLimitTest extends TestCase {

	/** @var array */
	private $store = array();
	/** @var int */
	private $clock = 1000000;

	private function limit(): AttemptLimit {
		$this->store = array();
		return new AttemptLimit(
			function ( string $k ) {
				return $this->store[ $k ] ?? false;
			},
			function ( string $k, array $v, int $ttl ) {
				$this->store[ $k ] = $v;
			},
			function ( string $k ) {
				unset( $this->store[ $k ] );
			},
			function () {
				return $this->clock;
			}
		);
	}

	public function test_first_errors_allow_another_attempt_and_the_last_blocks(): void {
		$l    = $this->limit();
		$keys = AttemptLimit::keys( 7, '203.0.113.9' );
		for ( $i = 1; $i < AttemptLimit::MAX_FAILURES; $i++ ) {
			$this->assertFalse( $l->is_locked( $keys ), "tentativo $i" );
			$this->assertFalse( $l->fail( $keys ), "errore $i" );
		}
		$this->assertFalse( $l->is_locked( $keys ) );
		$this->assertTrue( $l->fail( $keys ), 'l\'ultimo errore blocca' );
		$this->assertTrue( $l->is_locked( $keys ) );
	}

	public function test_lock_lasts_until_the_period_ends(): void {
		$l    = $this->limit();
		$keys = AttemptLimit::keys( 7, '203.0.113.9' );
		for ( $i = 0; $i < AttemptLimit::MAX_FAILURES; $i++ ) {
			$l->fail( $keys );
		}
		$this->clock += AttemptLimit::WINDOW - 1;
		$this->assertTrue( $l->is_locked( $keys ), 'ancora bloccato' );
		$this->clock += 1;
		$this->assertFalse( $l->is_locked( $keys ), 'scaduto' );
		$this->assertFalse( $l->fail( $keys ), 'si riparte da zero' );
	}

	public function test_success_resets_the_count(): void {
		$l    = $this->limit();
		$keys = AttemptLimit::keys( 7, '203.0.113.9' );
		for ( $i = 1; $i < AttemptLimit::MAX_FAILURES; $i++ ) {
			$l->fail( $keys );
		}
		$l->clear( $keys );
		for ( $i = 1; $i < AttemptLimit::MAX_FAILURES; $i++ ) {
			$this->assertFalse( $l->fail( $keys ) );
		}
		$this->assertFalse( $l->is_locked( $keys ) );
	}

	public function test_errors_outside_the_period_do_not_add_up(): void {
		$l    = $this->limit();
		$keys = AttemptLimit::keys( 7, '' );
		for ( $i = 1; $i < AttemptLimit::MAX_FAILURES; $i++ ) {
			$l->fail( $keys );
		}
		$this->clock += AttemptLimit::WINDOW;
		$this->assertFalse( $l->fail( $keys ), 'gli errori vecchi non contano' );
		$this->assertFalse( $l->is_locked( $keys ) );
	}

	public function test_lock_applies_per_user_and_per_connection(): void {
		$l = $this->limit();
		for ( $i = 0; $i < AttemptLimit::MAX_FAILURES; $i++ ) {
			$l->fail( AttemptLimit::keys( 7, '203.0.113.9' ) );
		}
		$this->assertTrue( $l->is_locked( AttemptLimit::keys( 7, '198.51.100.1' ) ), 'stesso utente, altra connessione' );
		$this->assertTrue( $l->is_locked( AttemptLimit::keys( 8, '203.0.113.9' ) ), 'altro utente, stessa connessione' );
		$this->assertFalse( $l->is_locked( AttemptLimit::keys( 8, '198.51.100.1' ) ), 'nessuno in comune' );
	}
}

/** Prova sul sorgente: i due punti che chiedono la password controllano il blocco prima della password e condividono le chiavi. */
final class AttemptLimitWiringTest extends TestCase {

	/** @return array<string,array{0:string,1:string}> */
	public static function entries(): array {
		return array(
			'ripristino'  => array( 'includes/Admin/Actions.php', 'backup.restore_locked' ),
			'azzeramento' => array( 'includes/Admin/TechActions.php', 'tech.reset_locked' ),
		);
	}

	/** @dataProvider entries */
	public function test_lock_is_checked_before_the_password( string $file, string $event ): void {
		$src  = (string) file_get_contents( dirname( __DIR__, 2 ) . '/' . $file );
		$lock = strpos( $src, '$lim->is_locked( $keys )' );
		$pass = strpos( $src, 'wp_check_password(' );
		$this->assertNotFalse( $lock );
		$this->assertNotFalse( $pass );
		$this->assertLessThan( $pass, $lock, 'il blocco si controlla prima della password' );
		$this->assertStringContainsString( "'" . $event . "'", $src );
		$this->assertStringContainsString( '$lim->clear( $keys )', $src );
		$this->assertStringContainsString( 'AttemptLimit::keys( $user && $user->exists() ? (int) $user->ID : 0, \AssociazioneSemplice\ClientIp::get() )', $src, 'chiavi condivise' );
		$this->assertStringContainsString( 'Troppi tentativi: riprova tra qualche minuto.', $src );
	}
}
