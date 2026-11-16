<?php
use PHPUnit\Framework\TestCase;

/** Prova sul sorgente: i due punti che chiedono la password controllano il blocco prima della password e condividono le chiavi. */
final class AttemptLimitWiringTest extends TestCase {

	/** @return array<string,array{0:string,1:string}> */
	public static function entries(): array {
		return array(
			'ripristino'  => array( 'includes/Admin/Actions.php', 'backup.restore_locked' ),
			'azzeramento' => array( 'includes/Admin/TechActions.php', 'tech.reset_locked' ),
			'scarico'     => array( 'includes/Backup.php', 'backup.download_locked' ),
		);
	}

	/** La pagina della copia chiede la password e non ha più un link diretto allo scarico; lo scarico senza invio del modulo non parte. */
	public function test_backup_page_asks_for_the_password(): void {
		$page = (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/Admin/BackupPage.php' );
		$this->assertStringContainsString( "Ui::form_open( 'asem_backup'", $page );
		$this->assertStringContainsString( 'name="password"', $page );
		$this->assertStringNotContainsString( 'Backup::download_url(', $page );
		$src  = (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/Backup.php' );
		$post = strpos( $src, "'POST' !== \$_SERVER['REQUEST_METHOD']" );
		$exp  = strpos( $src, '$path = self::export( $with );' );
		$this->assertNotFalse( $post );
		$this->assertLessThan( $exp, $post, 'senza modulo inviato non si crea la copia' );
		$this->assertLessThan( $exp, (int) strpos( $src, 'self::require_password( $back );' ), 'la password si controlla prima di creare la copia' );
	}

	/** Anche le copie già salvate: modulo con password nella pagina, solo POST, password prima di leggere il nome del file. */
	public function test_saved_copies_ask_for_the_password(): void {
		$page = (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/Admin/BackupPage.php' );
		$this->assertStringContainsString( "Ui::form_open( 'asem_backup_saved'", $page );
		$this->assertStringNotContainsString( 'Backup::saved_url(', $page );
		$src = (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/Backup.php' );
		$fn  = (int) strpos( $src, 'public static function handle_saved' );
		$this->assertGreaterThan( 0, $fn );
		$body = substr( $src, $fn );
		$post = (int) strpos( $body, "'POST' !== \$_SERVER['REQUEST_METHOD']" );
		$nonce = (int) strpos( $body, "check_admin_referer( 'asem_backup_saved' )" );
		$pwd   = (int) strpos( $body, 'self::require_password( $back );' );
		$send  = (int) strpos( $body, 'self::send_zip(' );
		$this->assertTrue( $post > 0 && $nonce > $post && $pwd > $nonce && $send > $pwd, 'POST, nonce, password e solo poi lo scarico' );
		$this->assertStringNotContainsString( "\$_GET['name']", $body );
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
