<?php
use AssociazioneSemplice\MemberPassword as P;
use PHPUnit\Framework\TestCase;

/** Dopo un ripristino le password dei soci tornano, ma ogni socio deve sceglierne una nuova al primo accesso. */
final class MemberPasswordRestoreTest extends TestCase {

	private function src( string $file ): string {
		return (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/' . $file );
	}

	private function between( string $s, string $from, string $to ): string {
		$a = (int) strpos( $s, $from );
		$b = (int) strpos( $s, $to, $a );
		return substr( $s, $a, $b - $a );
	}

	public function test_state_follows_hash_and_marker(): void {
		$this->assertSame( 'none', P::state( '', 0 ) );
		$this->assertSame( 'none', P::state( '', P::RESTORED ) );
		$this->assertSame( 'ok', P::state( 'hash', 0 ) );
		$this->assertSame( 'reset', P::state( 'hash', 1 ) );
		$this->assertSame( 'restored', P::state( 'hash', P::RESTORED ) );
	}

	public function test_login_with_the_old_password_does_not_clear_the_restore_marker(): void {
		$this->assertSame( P::RESTORED, P::marker_after_login( P::RESTORED ) );
		$this->assertSame( 0, P::marker_after_login( 1 ) ); // il recupero lasciato a metà si azzera come prima
		$this->assertSame( 0, P::marker_after_login( 0 ) );
	}

	public function test_restore_puts_passwords_back_and_marks_them(): void {
		$b = $this->src( 'Backup.php' );
		$not_restored = $this->between( $b, 'const NOT_RESTORED', ');' );
		$wiped        = $this->between( $b, 'const MEMBER_ACCESS', ');' );
		$this->assertStringNotContainsString( 'asem_member_auth', $not_restored );
		$this->assertStringNotContainsString( 'asem_member_auth', $wiped );
		$this->assertStringContainsString( 'asem_push_subs', $not_restored ); // sessioni e push restano cancellate
		$this->assertStringContainsString( 'asem_member_sessions', $wiped );
		$this->assertStringContainsString( 'SET must_reset = %d WHERE pass_hash IS NOT NULL', $b );
		$this->assertStringContainsString( "pass_hash IS NOT NULL AND pass_hash <> ''", $b ); // chi non ha password (solo link) non riceve il segno
		$this->assertStringContainsString( 'MemberPassword::RESTORED', $b );
	}

	public function test_password_change_clears_marker_and_leaves_an_audit_row(): void {
		$l = $this->src( 'MemberLogin.php' );
		$this->assertStringContainsString( 'MemberPassword::marker_after_login', $l );
		$this->assertStringContainsString( "Audit::log( 'member.password_changed_after_restore', 'person', \$person_id );", $l ); // nessun dato nel registro
		$this->assertStringContainsString( 'must_reset <> %d', $l ); // il recupero via email non abbassa il segno del ripristino
	}

	public function test_link_login_goes_through_the_same_forced_screen(): void {
		$s = $this->src( 'MemberSession.php' );
		$this->assertStringContainsString( "'ok' === \$state ? null : array( 'person' => \$p, 'state' => \$state )", $s );
		$page = $this->src( 'Frontend/MemberLoginPage.php' );
		$this->assertStringContainsString( "'restored' === \$pending['state']", $page );
		$this->assertStringContainsString( 'Scegli una nuova password', $page );
	}

	public function test_texts_say_passwords_come_back(): void {
		$b = $this->src( 'Backup.php' ) . $this->src( 'Admin/BackupPage.php' ) . $this->src( 'Admin/Actions.php' );
		$this->assertStringContainsString( 'ogni socio dovrà sceglierne una nuova al primo accesso', $b );
		$this->assertStringContainsString( 'deve scegliere subito una nuova password', $b );
		$this->assertStringNotContainsString( 'password dei soci non fanno parte', $b );
		$this->assertStringNotContainsString( 'il ripristino non le rimette', $b );
		$this->assertStringNotContainsString( 'le password dei soci, le loro sessioni', $b );
		$en = (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/languages/en.json' );
		$this->assertStringContainsString( 'each member will have to choose a new one at first login', $en );
		$this->assertStringNotContainsString( 'members come back in with the link they receive', $en );
		$this->assertStringContainsString( "i soci dovranno entrare di nuovo", $b );
		$this->assertStringContainsString( "members will have to log in again", $en );
		$this->assertStringNotContainsString( "rientrano di nuovo", $b );
	}

	public function test_csv_import_does_not_carry_passwords(): void {
		$this->assertStringNotContainsStringIgnoringCase( 'password', $this->src( 'PeopleCsv.php' ) );
	}
}
