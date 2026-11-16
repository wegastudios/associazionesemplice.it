<?php
use PHPUnit\Framework\TestCase;

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
