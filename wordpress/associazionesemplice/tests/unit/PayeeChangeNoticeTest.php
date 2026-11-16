<?php
use AssociazioneSemplice\Bank;
use AssociazioneSemplice\Donations;
use PHPUnit\Framework\TestCase;

/** Chi riceve i soldi (IBAN, conto PayPal delle donazioni) non cambia senza registro ed email, da qualunque strada arrivi la modifica. */
final class PayeeChangeNoticeTest extends TestCase {

	private function src( string $file ): string {
		return (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/' . $file );
	}

	/** L'avviso sta nel salvataggio delle impostazioni, dopo la scrittura: ci passano pannello e ripristino. */
	public function test_settings_update_notifies_after_saving(): void {
		$src  = $this->src( 'Settings.php' );
		$save = (int) strpos( $src, 'update_option( self::OPTION, $clean );' );
		$bank = (int) strpos( $src, 'Bank::notify_change(' );
		$pp   = (int) strpos( $src, 'Donations::notify_change(' );
		$this->assertGreaterThan( 0, $save );
		$this->assertGreaterThan( $save, $bank, 'avviso IBAN dopo il salvataggio' );
		$this->assertGreaterThan( $save, $pp, 'avviso conto donazioni dopo il salvataggio' );
		$this->assertStringContainsString( "\$before['bank_accounts']", $src );
		$this->assertStringContainsString( "\$before['donate_paypal']", $src );
	}

	/** Il pannello dei conti non avvisa una seconda volta (l'email partirebbe doppia). */
	public function test_bank_panel_does_not_notify_twice(): void {
		$this->assertStringNotContainsString( 'Bank::notify_change(', $this->src( 'Admin/TechActions.php' ) );
	}

	/** Il ripristino di una copia passa da Settings::update (non scrive le impostazioni a mano). */
	public function test_restore_goes_through_settings_update(): void {
		$this->assertStringContainsString( 'Settings::update( $new );', $this->src( 'Backup.php' ) );
	}

	/** Il cambio del conto delle donazioni scrive il registro e manda l'email ai destinatari. */
	public function test_donation_change_logs_and_mails(): void {
		$src = $this->src( 'Donations.php' );
		$fn  = (int) strpos( $src, 'public static function notify_change' );
		$this->assertGreaterThan( 0, $fn );
		$body = substr( $src, $fn );
		$this->assertStringContainsString( "Audit::log( 'donate.account_changed'", $body );
		$this->assertStringContainsString( 'Bank::admin_recipients()', $body );
		$this->assertStringContainsString( 'Texts::mail(', $body );
		$this->assertLessThan( (int) strpos( $body, 'Audit::log(' ), (int) strpos( $body, 'account_changed( $old, $new )' ), 'senza cambio non si scrive né si manda nulla' );
	}

	public function test_account_is_masked_in_notices(): void {
		$this->assertSame( 'ma••••@esempio.it', Donations::mask_account( 'mario@esempio.it' ) );
		$this->assertSame( '••••WXYZ', Donations::mask_account( 'ABCDEFGHIJWXYZ' ) );
		$this->assertSame( '', Donations::mask_account( '  ' ) );
		$this->assertStringNotContainsString( 'rio@esempio', Donations::mask_account( 'mario@esempio.it' ) );
	}

	public function test_bank_signature_detects_iban_change(): void {
		$a = array( array( 'iban' => 'IT60X0542811101000000123456' ) );
		$b = array( array( 'iban' => 'IT60 X054 2811 1010 0000 0123 456' ) );
		$c = array( array( 'iban' => 'IT28W8000000292100645211208' ) );
		$this->assertSame( Bank::signature( $a ), Bank::signature( $b ), 'solo spazi: nessun cambio' );
		$this->assertNotSame( Bank::signature( $a ), Bank::signature( $c ) );
	}
}
